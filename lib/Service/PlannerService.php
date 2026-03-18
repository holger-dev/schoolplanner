<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Service;

use DateTimeImmutable;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class PlannerService {
	public function __construct(
		private IDBConnection $connection,
		private SettingsService $settingsService,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getBootstrap(string $userId): array {
		$courses = $this->getCourses($userId);
		$lessons = $this->getLessonsByCourse(array_column($courses, 'id'));
		$items = $this->getItemsByLesson($this->flattenIds($lessons));
		$attachments = $this->getAttachmentsByItem($this->flattenIds($items));

		foreach ($courses as &$course) {
			$courseLessons = $lessons[$course['id']] ?? [];
			foreach ($courseLessons as &$lesson) {
				$lessonItems = $items[$lesson['id']] ?? [];
				foreach ($lessonItems as &$item) {
					$item['attachments'] = $attachments[$item['id']] ?? [];
				}
				unset($item);
				$lesson['items'] = $lessonItems;
			}
			unset($lesson);
			$course['lessons'] = $courseLessons;
		}
		unset($course);

		return [
			'courses' => $courses,
			'settings' => $this->settingsService->getSettings($userId),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function createCourse(string $userId, array $payload): array {
		$now = new DateTimeImmutable();
		$name = trim((string)($payload['name'] ?? '')) ?: 'Neuer Kurs';
		$description = (string)($payload['description'] ?? '');
		$slug = $this->slugify($name) ?: 'kurs';

		$query = $this->connection->getQueryBuilder();
		$query->insert('schoolplanner_courses')
			->values([
				'user_id' => $query->createNamedParameter($userId),
				'name' => $query->createNamedParameter($name),
				'description' => $query->createNamedParameter($description),
				'publish_slug' => $query->createNamedParameter($slug . '-' . $now->format('YmdHis')),
				'created_at' => $query->createNamedParameter($now->format('Y-m-d H:i:s')),
				'updated_at' => $query->createNamedParameter($now->format('Y-m-d H:i:s')),
			])
			->executeStatement();

		return $this->getCourse($userId, (int)$this->connection->lastInsertId('*PREFIX*schoolplanner_courses'));
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function updateCourse(string $userId, int $courseId, array $payload): array {
		$this->assertCourseOwner($userId, $courseId);
		$now = new DateTimeImmutable();

		$query = $this->connection->getQueryBuilder();
		$query->update('schoolplanner_courses')
			->set('name', $query->createNamedParameter(trim((string)($payload['name'] ?? '')) ?: 'Unbenannter Kurs'))
			->set('description', $query->createNamedParameter((string)($payload['description'] ?? '')))
			->set('updated_at', $query->createNamedParameter($now->format('Y-m-d H:i:s')))
			->where($query->expr()->eq('id', $query->createNamedParameter($courseId)))
			->executeStatement();

		return $this->getCourse($userId, $courseId);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function createLesson(string $userId, int $courseId, array $payload): array {
		$this->assertCourseOwner($userId, $courseId);
		$now = new DateTimeImmutable();

		$query = $this->connection->getQueryBuilder();
		$query->insert('schoolplanner_lessons')
			->values([
				'course_id' => $query->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
				'lesson_date' => $query->createNamedParameter((string)($payload['lessonDate'] ?? $now->format('Y-m-d'))),
				'title' => $query->createNamedParameter(trim((string)($payload['title'] ?? 'Neue Stunde')) ?: 'Neue Stunde'),
				'goal' => $query->createNamedParameter(trim((string)($payload['goal'] ?? ''))),
				'description' => $query->createNamedParameter((string)($payload['description'] ?? '')),
				'reflection' => $query->createNamedParameter((string)($payload['reflection'] ?? '')),
				'sort_order' => $query->createNamedParameter((int)($payload['sortOrder'] ?? 0), IQueryBuilder::PARAM_INT),
				'created_at' => $query->createNamedParameter($now->format('Y-m-d H:i:s')),
				'updated_at' => $query->createNamedParameter($now->format('Y-m-d H:i:s')),
			])
			->executeStatement();

		return $this->getLesson((int)$this->connection->lastInsertId('*PREFIX*schoolplanner_lessons'), $userId);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function updateLesson(string $userId, int $lessonId, array $payload): array {
		$lesson = $this->getLesson($lessonId, $userId);
		$now = new DateTimeImmutable();

		$query = $this->connection->getQueryBuilder();
		$query->update('schoolplanner_lessons')
			->set('lesson_date', $query->createNamedParameter((string)($payload['lessonDate'] ?? $lesson['lessonDate'])))
			->set('title', $query->createNamedParameter(trim((string)($payload['title'] ?? $lesson['title'])) ?: 'Neue Stunde'))
			->set('goal', $query->createNamedParameter(trim((string)($payload['goal'] ?? $lesson['goal']))))
			->set('description', $query->createNamedParameter((string)($payload['description'] ?? $lesson['description'])))
			->set('reflection', $query->createNamedParameter((string)($payload['reflection'] ?? $lesson['reflection'])))
			->set('updated_at', $query->createNamedParameter($now->format('Y-m-d H:i:s')))
			->where($query->expr()->eq('id', $query->createNamedParameter($lessonId)))
			->executeStatement();

		return $this->getLesson($lessonId, $userId);
	}

	public function deleteLesson(string $userId, int $lessonId): void {
		$lesson = $this->getLesson($lessonId, $userId);

		$itemQuery = $this->connection->getQueryBuilder();
		$itemQuery->delete('schoolplanner_items')
			->where($itemQuery->expr()->eq('lesson_id', $itemQuery->createNamedParameter($lessonId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$lessonQuery = $this->connection->getQueryBuilder();
		$lessonQuery->delete('schoolplanner_lessons')
			->where($lessonQuery->expr()->eq('id', $lessonQuery->createNamedParameter($lessonId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function deleteCourse(string $userId, int $courseId): void {
		$course = $this->getCourse($userId, $courseId);
		$lessonIds = array_map(static fn (array $lesson): int => (int)$lesson['id'], $course['lessons']);
		$itemIds = [];
		foreach ($course['lessons'] as $lesson) {
			foreach ($lesson['items'] as $item) {
				$itemIds[] = (int)$item['id'];
			}
		}

		if ($itemIds !== []) {
			$itemQuery = $this->connection->getQueryBuilder();
			$itemQuery->delete('schoolplanner_items')
				->where($itemQuery->expr()->in('id', $itemQuery->createNamedParameter($itemIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}

		if ($lessonIds !== []) {
			$lessonQuery = $this->connection->getQueryBuilder();
			$lessonQuery->delete('schoolplanner_lessons')
				->where($lessonQuery->expr()->in('id', $lessonQuery->createNamedParameter($lessonIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}

		$courseQuery = $this->connection->getQueryBuilder();
		$courseQuery->delete('schoolplanner_courses')
			->where($courseQuery->expr()->eq('id', $courseQuery->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function createLessonItem(string $userId, int $lessonId, array $payload): array {
		$lesson = $this->getLesson($lessonId, $userId);
		$now = new DateTimeImmutable();
		$sortOrder = array_key_exists('sortOrder', $payload)
			? (int)$payload['sortOrder']
			: $this->getNextItemSortOrder($lessonId);

		$query = $this->connection->getQueryBuilder();
		$query->insert('schoolplanner_items')
			->values([
				'lesson_id' => $query->createNamedParameter($lesson['id'], IQueryBuilder::PARAM_INT),
				'title' => $query->createNamedParameter(trim((string)($payload['title'] ?? 'Neues Element')) ?: 'Neues Element'),
				'description' => $query->createNamedParameter((string)($payload['description'] ?? '')),
				'published' => $query->createNamedParameter((int)(bool)($payload['published'] ?? false), IQueryBuilder::PARAM_INT),
				'is_current' => $query->createNamedParameter((int)(bool)($payload['isCurrent'] ?? false), IQueryBuilder::PARAM_INT),
				'sort_order' => $query->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT),
				'created_at' => $query->createNamedParameter($now->format('Y-m-d H:i:s')),
				'updated_at' => $query->createNamedParameter($now->format('Y-m-d H:i:s')),
			])
			->executeStatement();

		$itemId = (int)$this->connection->lastInsertId('*PREFIX*schoolplanner_items');
		if ((bool)($payload['isCurrent'] ?? false)) {
			$this->clearCurrentFlagsForLesson((int)$lesson['id'], $itemId);
		}

		return $this->getLessonItem($itemId, $userId);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function updateLessonItem(string $userId, int $itemId, array $payload): array {
		$item = $this->getLessonItem($itemId, $userId);
		$now = new DateTimeImmutable();

		$query = $this->connection->getQueryBuilder();
		$query->update('schoolplanner_items')
			->set('title', $query->createNamedParameter(trim((string)($payload['title'] ?? $item['title'])) ?: 'Neues Element'))
			->set('description', $query->createNamedParameter((string)($payload['description'] ?? $item['description'])))
			->set('published', $query->createNamedParameter((int)(bool)($payload['published'] ?? $item['published']), IQueryBuilder::PARAM_INT))
			->set('is_current', $query->createNamedParameter((int)(bool)($payload['isCurrent'] ?? $item['isCurrent']), IQueryBuilder::PARAM_INT))
			->set('sort_order', $query->createNamedParameter((int)($payload['sortOrder'] ?? $item['sortOrder']), IQueryBuilder::PARAM_INT))
			->set('updated_at', $query->createNamedParameter($now->format('Y-m-d H:i:s')))
			->where($query->expr()->eq('id', $query->createNamedParameter($itemId)))
			->executeStatement();

		if ((bool)($payload['isCurrent'] ?? $item['isCurrent'])) {
			$this->clearCurrentFlagsForLesson((int)$item['lessonId'], $itemId);
		}

		return $this->getLessonItem($itemId, $userId);
	}

	public function deleteLessonItem(string $userId, int $itemId): void {
		$this->getLessonItem($itemId, $userId);

		$query = $this->connection->getQueryBuilder();
		$query->delete('schoolplanner_items')
			->where($query->expr()->eq('id', $query->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getCourses(string $userId): array {
		$query = $this->connection->getQueryBuilder();
		$result = $query->select('*')
			->from('schoolplanner_courses')
			->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
			->orderBy('name', 'ASC')
			->executeQuery();

		$courses = [];
		while ($row = $result->fetch()) {
			$courses[] = $this->mapCourse($row);
		}
		$result->closeCursor();

		return $courses;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getCourse(string $userId, int $courseId): array {
		$query = $this->connection->getQueryBuilder();
		$result = $query->select('*')
			->from('schoolplanner_courses')
			->where($query->expr()->eq('id', $query->createNamedParameter($courseId)))
			->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
			->executeQuery();

		$row = $result->fetch();
		$result->closeCursor();
		if (!$row) {
			throw new DoesNotExistException('Course not found');
		}

		$course = $this->mapCourse($row);
		$lessons = $this->getLessonsByCourse([$courseId])[$courseId] ?? [];
		$items = $this->getItemsByLesson(array_column($lessons, 'id'));
		$attachments = $this->getAttachmentsByItem($this->flattenIds($items));
		foreach ($lessons as &$lesson) {
			$lessonItems = $items[$lesson['id']] ?? [];
			foreach ($lessonItems as &$item) {
				$item['attachments'] = $attachments[$item['id']] ?? [];
			}
			unset($item);
			$lesson['items'] = $lessonItems;
		}
		unset($lesson);
		$course['lessons'] = $lessons;

		return $course;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getLesson(int $lessonId, string $userId): array {
		$query = $this->connection->getQueryBuilder();
		$result = $query->select('l.*', 'c.user_id')
			->from('schoolplanner_lessons', 'l')
			->innerJoin('l', 'schoolplanner_courses', 'c', 'l.course_id = c.id')
			->where($query->expr()->eq('l.id', $query->createNamedParameter($lessonId)))
			->andWhere($query->expr()->eq('c.user_id', $query->createNamedParameter($userId)))
			->executeQuery();

		$row = $result->fetch();
		$result->closeCursor();
		if (!$row) {
			throw new DoesNotExistException('Lesson not found');
		}

		$lesson = $this->mapLesson($row);
		$lesson['items'] = $this->getItemsByLesson([$lessonId])[$lessonId] ?? [];
		$attachments = $this->getAttachmentsByItem(array_column($lesson['items'], 'id'));
		foreach ($lesson['items'] as &$item) {
			$item['attachments'] = $attachments[$item['id']] ?? [];
		}
		unset($item);
		return $lesson;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getLessonItem(int $itemId, string $userId): array {
		$query = $this->connection->getQueryBuilder();
		$result = $query->select('i.*', 'c.user_id')
			->from('schoolplanner_items', 'i')
			->innerJoin('i', 'schoolplanner_lessons', 'l', 'i.lesson_id = l.id')
			->innerJoin('l', 'schoolplanner_courses', 'c', 'l.course_id = c.id')
			->where($query->expr()->eq('i.id', $query->createNamedParameter($itemId)))
			->andWhere($query->expr()->eq('c.user_id', $query->createNamedParameter($userId)))
			->executeQuery();

		$row = $result->fetch();
		$result->closeCursor();
		if (!$row) {
			throw new DoesNotExistException('Item not found');
		}

		$item = $this->mapItem($row);
		$item['attachments'] = $this->getAttachmentsByItem([$itemId])[$itemId] ?? [];
		return $item;
	}

	public function getCourseIdForItem(string $userId, int $itemId): int {
		$query = $this->connection->getQueryBuilder();
		$result = $query->select('c.id')
			->from('schoolplanner_items', 'i')
			->innerJoin('i', 'schoolplanner_lessons', 'l', 'i.lesson_id = l.id')
			->innerJoin('l', 'schoolplanner_courses', 'c', 'l.course_id = c.id')
			->where($query->expr()->eq('i.id', $query->createNamedParameter($itemId)))
			->andWhere($query->expr()->eq('c.user_id', $query->createNamedParameter($userId)))
			->executeQuery();

		$row = $result->fetch();
		$result->closeCursor();
		if (!$row) {
			throw new DoesNotExistException('Item not found');
		}

		return (int)$row['id'];
	}

	/**
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	private function getLessonsByCourse(array $courseIds): array {
		if ($courseIds === []) {
			return [];
		}

		$query = $this->connection->getQueryBuilder();
		$result = $query->select('*')
			->from('schoolplanner_lessons')
			->where($query->expr()->in('course_id', $query->createNamedParameter($courseIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('lesson_date', 'ASC')
			->addOrderBy('sort_order', 'ASC')
			->addOrderBy('id', 'ASC')
			->executeQuery();

		$lessons = [];
		while ($row = $result->fetch()) {
			$lesson = $this->mapLesson($row);
			$lessons[(int)$row['course_id']][] = $lesson;
		}
		$result->closeCursor();
		return $lessons;
	}

	/**
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	private function getItemsByLesson(array $lessonIds): array {
		if ($lessonIds === []) {
			return [];
		}

		$query = $this->connection->getQueryBuilder();
		$result = $query->select('*')
			->from('schoolplanner_items')
			->where($query->expr()->in('lesson_id', $query->createNamedParameter($lessonIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('id', 'ASC')
			->executeQuery();

		$items = [];
		while ($row = $result->fetch()) {
			$items[(int)$row['lesson_id']][] = $this->mapItem($row);
		}
		$result->closeCursor();
		return $items;
	}

	private function assertCourseOwner(string $userId, int $courseId): void {
		$this->getCourse($userId, $courseId);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function mapCourse(array $row): array {
		return [
			'id' => (int)$row['id'],
			'name' => (string)$row['name'],
			'description' => (string)($row['description'] ?? ''),
			'publishSlug' => (string)$row['publish_slug'],
			'publishedUrl' => $row['published_url'] ? (string)$row['published_url'] : null,
			'lessons' => [],
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function mapLesson(array $row): array {
		return [
			'id' => (int)$row['id'],
			'courseId' => (int)$row['course_id'],
			'lessonDate' => (string)$row['lesson_date'],
			'title' => (string)$row['title'],
			'goal' => (string)($row['goal'] ?? ''),
			'description' => (string)($row['description'] ?? ''),
			'reflection' => (string)($row['reflection'] ?? ''),
			'items' => [],
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function mapItem(array $row): array {
		return [
			'id' => (int)$row['id'],
			'lessonId' => (int)$row['lesson_id'],
			'title' => (string)$row['title'],
			'description' => (string)($row['description'] ?? ''),
			'published' => (bool)$row['published'],
			'isCurrent' => (bool)($row['is_current'] ?? false),
			'sortOrder' => (int)($row['sort_order'] ?? 0),
			'attachments' => [],
		];
	}

	private function slugify(string $value): string {
		$value = strtolower(trim($value));
		$value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
		return trim($value, '-');
	}

	/**
	 * @param array<int, array<int, array<string, mixed>>> $byParent
	 * @return array<int>
	 */
	private function flattenIds(array $byParent): array {
		$ids = [];
		foreach ($byParent as $rows) {
			foreach ($rows as $row) {
				$ids[] = (int)$row['id'];
			}
		}
		return $ids;
	}

	/**
	 * @param array<int> $itemIds
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	private function getAttachmentsByItem(array $itemIds): array {
		if ($itemIds === []) {
			return [];
		}

		$query = $this->connection->getQueryBuilder();
		$result = $query->select('*')
			->from('schoolplanner_attachments')
			->where($query->expr()->in('item_id', $query->createNamedParameter($itemIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC')
			->executeQuery();

		$attachments = [];
		while ($row = $result->fetch()) {
			$attachments[(int)$row['item_id']][] = [
				'id' => (int)$row['id'],
				'itemId' => (int)$row['item_id'],
				'fileName' => (string)$row['file_name'],
				'storedName' => (string)$row['stored_name'],
				'mimeType' => (string)($row['mime_type'] ?? ''),
				'size' => (int)$row['size'],
			];
		}
		$result->closeCursor();

		return $attachments;
	}

	private function getNextItemSortOrder(int $lessonId): int {
		$query = $this->connection->getQueryBuilder();
		$result = $query->selectAlias($query->createFunction('MAX(sort_order)'), 'max_sort_order')
			->from('schoolplanner_items')
			->where($query->expr()->eq('lesson_id', $query->createNamedParameter($lessonId, IQueryBuilder::PARAM_INT)))
			->executeQuery();

		$row = $result->fetch();
		$result->closeCursor();
		return ((int)($row['max_sort_order'] ?? -1)) + 1;
	}

	private function clearCurrentFlagsForLesson(int $lessonId, int $currentItemId): void {
		$query = $this->connection->getQueryBuilder();
		$query->update('schoolplanner_items')
			->set('is_current', $query->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('lesson_id', $query->createNamedParameter($lessonId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->neq('id', $query->createNamedParameter($currentItemId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
