<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Service;

use DateTimeImmutable;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Per-lesson participation: attendance status, an optional grade on a chosen
 * scale (3, 5 or German note) and a free note. Plus a course-wide overview that
 * shows every student against every lesson at a glance.
 */
class ParticipationService {
	private const STATUSES = ['present', 'excused', 'unexcused'];
	private const SCALES = ['', 'scale3', 'scale5', 'note'];

	public function __construct(
		private IDBConnection $connection,
		private PlannerService $plannerService,
		private StudentService $studentService,
	) {
	}

	/**
	 * All students of the lesson's course, each with its (possibly empty)
	 * participation entry for this lesson.
	 *
	 * @return array<string, mixed>
	 */
	public function getForLesson(string $userId, int $lessonId): array {
		$lesson = $this->plannerService->getLesson($lessonId, $userId);
		$courseId = (int)$lesson['courseId'];
		$course = $this->plannerService->getCourse($userId, $courseId);
		$scale = (string)($course['participationScale'] ?? '');
		$students = $this->studentService->getOverview($userId, $courseId)['students'];

		$entries = $this->fetchEntriesForLessons([$lessonId])[$lessonId] ?? [];
		$rows = [];
		foreach ($students as $student) {
			$entry = $entries[(int)$student['id']] ?? null;
			$rows[] = [
				'studentId' => (int)$student['id'],
				'name' => (string)$student['name'],
				'status' => $entry['status'] ?? '',
				'grade' => $entry['grade'] ?? '',
				'note' => $entry['note'] ?? '',
			];
		}

		return [
			'lessonId' => $lessonId,
			'courseId' => $courseId,
			'scale' => $scale,
			'students' => $rows,
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function saveForLesson(string $userId, int $lessonId, array $payload): array {
		$lesson = $this->plannerService->getLesson($lessonId, $userId);
		$courseId = (int)$lesson['courseId'];
		$course = $this->plannerService->getCourse($userId, $courseId);
		$validStudentIds = array_map(
			static fn (array $student): int => (int)$student['id'],
			$this->studentService->getOverview($userId, $courseId)['students']
		);

		// The grading scale is a course-wide setting, not chosen per lesson.
		$scale = $this->normalizeScale((string)($course['participationScale'] ?? ''));
		$entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
		$now = new DateTimeImmutable();

		foreach ($entries as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$studentId = (int)($entry['studentId'] ?? 0);
			if (!in_array($studentId, $validStudentIds, true)) {
				continue;
			}

			$status = $this->normalizeStatus((string)($entry['status'] ?? ''));
			$grade = mb_substr(trim((string)($entry['grade'] ?? '')), 0, 16);
			$note = (string)($entry['note'] ?? '');

			$this->upsertEntry($lessonId, $studentId, $status, $scale, $grade, $note, $now);
		}

		return $this->getForLesson($userId, $lessonId);
	}

	/**
	 * Course-wide matrix: students x lessons.
	 *
	 * @return array<string, mixed>
	 */
	public function getOverview(string $userId, int $courseId): array {
		$course = $this->plannerService->getCourse($userId, $courseId);
		$students = $this->studentService->getOverview($userId, $courseId)['students'];

		$lessons = [];
		$lessonIds = [];
		foreach ($course['lessons'] ?? [] as $lesson) {
			$lessons[] = [
				'id' => (int)$lesson['id'],
				'date' => (string)$lesson['lessonDate'],
				'slot' => (int)$lesson['lessonSlot'],
				'title' => (string)$lesson['title'],
			];
			$lessonIds[] = (int)$lesson['id'];
		}

		$entriesByLesson = $this->fetchEntriesForLessons($lessonIds);

		// grid[studentId][lessonId] = entry
		$grid = [];
		foreach ($students as $student) {
			$studentId = (int)$student['id'];
			$grid[$studentId] = [];
			foreach ($lessonIds as $lessonId) {
				$entry = $entriesByLesson[$lessonId][$studentId] ?? null;
				if ($entry !== null) {
					$grid[$studentId][$lessonId] = [
						'status' => $entry['status'],
						'grade' => $entry['grade'],
						'scale' => $entry['scale'],
						'note' => $entry['note'] ?? '',
					];
				}
			}
		}

		return [
			'courseId' => $courseId,
			'students' => array_map(static fn (array $s): array => ['id' => (int)$s['id'], 'name' => (string)$s['name']], $students),
			'lessons' => $lessons,
			'grid' => $grid,
		];
	}

	private function upsertEntry(int $lessonId, int $studentId, string $status, string $scale, string $grade, string $note, DateTimeImmutable $now): void {
		$existingId = $this->findEntryId($lessonId, $studentId);

		if ($existingId !== null) {
			$query = $this->connection->getQueryBuilder();
			$query->update('sp_participation')
				->set('status', $query->createNamedParameter($status))
				->set('scale', $query->createNamedParameter($scale))
				->set('grade', $query->createNamedParameter($grade))
				->set('note', $query->createNamedParameter($note))
				->set('updated_at', $query->createNamedParameter($now->format('Y-m-d H:i:s')))
				->where($query->expr()->eq('id', $query->createNamedParameter($existingId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			return;
		}

		$query = $this->connection->getQueryBuilder();
		$query->insert('sp_participation')
			->values([
				'lesson_id' => $query->createNamedParameter($lessonId, IQueryBuilder::PARAM_INT),
				'student_id' => $query->createNamedParameter($studentId, IQueryBuilder::PARAM_INT),
				'status' => $query->createNamedParameter($status),
				'scale' => $query->createNamedParameter($scale),
				'grade' => $query->createNamedParameter($grade),
				'note' => $query->createNamedParameter($note),
				'created_at' => $query->createNamedParameter($now->format('Y-m-d H:i:s')),
				'updated_at' => $query->createNamedParameter($now->format('Y-m-d H:i:s')),
			])
			->executeStatement();
	}

	private function findEntryId(int $lessonId, int $studentId): ?int {
		$query = $this->connection->getQueryBuilder();
		$result = $query->select('id')
			->from('sp_participation')
			->where($query->expr()->eq('lesson_id', $query->createNamedParameter($lessonId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('student_id', $query->createNamedParameter($studentId, IQueryBuilder::PARAM_INT)))
			->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row ? (int)$row['id'] : null;
	}

	/**
	 * @param array<int> $lessonIds
	 * @return array<int, array<int, array<string, mixed>>> lessonId => studentId => entry
	 */
	private function fetchEntriesForLessons(array $lessonIds): array {
		if ($lessonIds === []) {
			return [];
		}

		$query = $this->connection->getQueryBuilder();
		$result = $query->select('*')
			->from('sp_participation')
			->where($query->expr()->in('lesson_id', $query->createNamedParameter($lessonIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeQuery();

		$entries = [];
		while ($row = $result->fetch()) {
			$entries[(int)$row['lesson_id']][(int)$row['student_id']] = [
				'status' => (string)($row['status'] ?? ''),
				'scale' => (string)($row['scale'] ?? ''),
				'grade' => (string)($row['grade'] ?? ''),
				'note' => (string)($row['note'] ?? ''),
			];
		}
		$result->closeCursor();
		return $entries;
	}

	private function normalizeStatus(string $status): string {
		return in_array($status, self::STATUSES, true) ? $status : '';
	}

	private function normalizeScale(string $scale): string {
		return in_array($scale, self::SCALES, true) ? $scale : '';
	}
}
