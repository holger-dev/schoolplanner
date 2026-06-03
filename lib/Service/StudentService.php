<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Service;

use DateTimeImmutable;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class StudentService {
	public function __construct(
		private IDBConnection $connection,
		private PlannerService $plannerService,
	) {
	}

	/**
	 * Students and groups of a course, ready for the frontend.
	 *
	 * @return array{students: array<int, array<string, mixed>>, groups: array<int, array<string, mixed>>}
	 */
	public function getOverview(string $userId, int $courseId): array {
		// Ownership check (throws when the course is not owned by the user).
		$this->plannerService->getCourse($userId, $courseId);

		$groups = $this->fetchGroups($courseId);
		$memberships = $this->fetchMemberships($courseId);
		$students = $this->fetchStudents($courseId);

		foreach ($students as &$student) {
			$student['groupIds'] = $memberships[$student['id']] ?? [];
		}
		unset($student);

		return [
			'students' => $students,
			'groups' => $groups,
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function createStudent(string $userId, int $courseId, array $payload): array {
		$this->plannerService->getCourse($userId, $courseId);
		$this->insertStudent($courseId, (string)($payload['name'] ?? ''), (string)($payload['note'] ?? ''));
		return $this->getOverview($userId, $courseId);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function updateStudent(string $userId, int $studentId, array $payload): array {
		$courseId = $this->getCourseIdForStudent($userId, $studentId);
		$now = new DateTimeImmutable();

		$query = $this->connection->getQueryBuilder();
		$query->update('sp_students')
			->set('name', $query->createNamedParameter(mb_substr(trim((string)($payload['name'] ?? '')) ?: 'Unbenannt', 0, 255)))
			->set('note', $query->createNamedParameter((string)($payload['note'] ?? '')))
			->set('updated_at', $query->createNamedParameter($now->format('Y-m-d H:i:s')))
			->where($query->expr()->eq('id', $query->createNamedParameter($studentId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		if (array_key_exists('groupIds', $payload) && is_array($payload['groupIds'])) {
			$this->setStudentGroups($courseId, $studentId, $payload['groupIds']);
		}

		return $this->getOverview($userId, $courseId);
	}

	public function deleteStudent(string $userId, int $studentId): array {
		$courseId = $this->getCourseIdForStudent($userId, $studentId);

		$memberQuery = $this->connection->getQueryBuilder();
		$memberQuery->delete('sp_group_members')
			->where($memberQuery->expr()->eq('student_id', $memberQuery->createNamedParameter($studentId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$query = $this->connection->getQueryBuilder();
		$query->delete('sp_students')
			->where($query->expr()->eq('id', $query->createNamedParameter($studentId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		return $this->getOverview($userId, $courseId);
	}

	/**
	 * Flexible bulk import: accepts newline separated names, optionally with a
	 * note after a comma, semicolon or tab (e.g. "Max Muster, sitzt vorne").
	 *
	 * @param array<string, mixed> $payload
	 */
	public function importStudents(string $userId, int $courseId, array $payload): array {
		$this->plannerService->getCourse($userId, $courseId);
		$raw = (string)($payload['text'] ?? '');
		$lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

		$imported = 0;
		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			$parts = preg_split('/\s*[,;\t]\s*/', $line, 2) ?: [$line];
			$name = trim($parts[0] ?? '');
			if ($name === '') {
				continue;
			}
			$note = trim($parts[1] ?? '');
			$this->insertStudent($courseId, $name, $note);
			$imported++;
		}

		$overview = $this->getOverview($userId, $courseId);
		$overview['imported'] = $imported;
		return $overview;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function createGroup(string $userId, int $courseId, array $payload): array {
		$this->plannerService->getCourse($userId, $courseId);
		$now = new DateTimeImmutable();

		$query = $this->connection->getQueryBuilder();
		$query->insert('sp_student_groups')
			->values([
				'course_id' => $query->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
				'name' => $query->createNamedParameter(mb_substr(trim((string)($payload['name'] ?? '')) ?: 'Neue Gruppe', 0, 255)),
				'created_at' => $query->createNamedParameter($now->format('Y-m-d H:i:s')),
				'updated_at' => $query->createNamedParameter($now->format('Y-m-d H:i:s')),
			])
			->executeStatement();

		return $this->getOverview($userId, $courseId);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function updateGroup(string $userId, int $groupId, array $payload): array {
		$courseId = $this->getCourseIdForGroup($userId, $groupId);
		$now = new DateTimeImmutable();

		$query = $this->connection->getQueryBuilder();
		$query->update('sp_student_groups')
			->set('name', $query->createNamedParameter(mb_substr(trim((string)($payload['name'] ?? '')) ?: 'Gruppe', 0, 255)))
			->set('updated_at', $query->createNamedParameter($now->format('Y-m-d H:i:s')))
			->where($query->expr()->eq('id', $query->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		return $this->getOverview($userId, $courseId);
	}

	public function deleteGroup(string $userId, int $groupId): array {
		$courseId = $this->getCourseIdForGroup($userId, $groupId);

		$memberQuery = $this->connection->getQueryBuilder();
		$memberQuery->delete('sp_group_members')
			->where($memberQuery->expr()->eq('group_id', $memberQuery->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$query = $this->connection->getQueryBuilder();
		$query->delete('sp_student_groups')
			->where($query->expr()->eq('id', $query->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		return $this->getOverview($userId, $courseId);
	}

	private function insertStudent(int $courseId, string $name, string $note): void {
		$now = new DateTimeImmutable();
		$query = $this->connection->getQueryBuilder();
		$query->insert('sp_students')
			->values([
				'course_id' => $query->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
				'name' => $query->createNamedParameter(mb_substr(trim($name) ?: 'Unbenannt', 0, 255)),
				'note' => $query->createNamedParameter($note),
				'sort_order' => $query->createNamedParameter($this->getNextStudentSortOrder($courseId), IQueryBuilder::PARAM_INT),
				'created_at' => $query->createNamedParameter($now->format('Y-m-d H:i:s')),
				'updated_at' => $query->createNamedParameter($now->format('Y-m-d H:i:s')),
			])
			->executeStatement();
	}

	/**
	 * @param array<int|string> $groupIds
	 */
	private function setStudentGroups(int $courseId, int $studentId, array $groupIds): void {
		$validGroupIds = array_map(static fn (array $group): int => (int)$group['id'], $this->fetchGroups($courseId));

		$deleteQuery = $this->connection->getQueryBuilder();
		$deleteQuery->delete('sp_group_members')
			->where($deleteQuery->expr()->eq('student_id', $deleteQuery->createNamedParameter($studentId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$seen = [];
		foreach ($groupIds as $rawId) {
			$groupId = (int)$rawId;
			if (!in_array($groupId, $validGroupIds, true) || isset($seen[$groupId])) {
				continue;
			}
			$seen[$groupId] = true;

			$insertQuery = $this->connection->getQueryBuilder();
			$insertQuery->insert('sp_group_members')
				->values([
					'group_id' => $insertQuery->createNamedParameter($groupId, IQueryBuilder::PARAM_INT),
					'student_id' => $insertQuery->createNamedParameter($studentId, IQueryBuilder::PARAM_INT),
				])
				->executeStatement();
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchStudents(int $courseId): array {
		$query = $this->connection->getQueryBuilder();
		$result = $query->select('*')
			->from('sp_students')
			->where($query->expr()->eq('course_id', $query->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('name', 'ASC')
			->executeQuery();

		$students = [];
		while ($row = $result->fetch()) {
			$students[] = [
				'id' => (int)$row['id'],
				'courseId' => (int)$row['course_id'],
				'name' => (string)$row['name'],
				'note' => (string)($row['note'] ?? ''),
				'groupIds' => [],
			];
		}
		$result->closeCursor();
		return $students;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchGroups(int $courseId): array {
		$query = $this->connection->getQueryBuilder();
		$result = $query->select('*')
			->from('sp_student_groups')
			->where($query->expr()->eq('course_id', $query->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
			->orderBy('name', 'ASC')
			->executeQuery();

		$groups = [];
		while ($row = $result->fetch()) {
			$groups[] = [
				'id' => (int)$row['id'],
				'courseId' => (int)$row['course_id'],
				'name' => (string)$row['name'],
			];
		}
		$result->closeCursor();
		return $groups;
	}

	/**
	 * @return array<int, array<int>> Map of student id => list of group ids.
	 */
	private function fetchMemberships(int $courseId): array {
		$query = $this->connection->getQueryBuilder();
		$result = $query->select('m.student_id', 'm.group_id')
			->from('sp_group_members', 'm')
			->innerJoin('m', 'sp_students', 's', 'm.student_id = s.id')
			->where($query->expr()->eq('s.course_id', $query->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
			->executeQuery();

		$memberships = [];
		while ($row = $result->fetch()) {
			$memberships[(int)$row['student_id']][] = (int)$row['group_id'];
		}
		$result->closeCursor();
		return $memberships;
	}

	private function getNextStudentSortOrder(int $courseId): int {
		$query = $this->connection->getQueryBuilder();
		$result = $query->selectAlias($query->createFunction('MAX(sort_order)'), 'max_sort_order')
			->from('sp_students')
			->where($query->expr()->eq('course_id', $query->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
			->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return ((int)($row['max_sort_order'] ?? -1)) + 1;
	}

	private function getCourseIdForStudent(string $userId, int $studentId): int {
		return $this->resolveCourseId($userId, 'sp_students', $studentId, 'Student not found');
	}

	private function getCourseIdForGroup(string $userId, int $groupId): int {
		return $this->resolveCourseId($userId, 'sp_student_groups', $groupId, 'Group not found');
	}

	private function resolveCourseId(string $userId, string $table, int $rowId, string $error): int {
		$query = $this->connection->getQueryBuilder();
		$result = $query->select('t.course_id')
			->from($table, 't')
			->innerJoin('t', 'schoolplanner_courses', 'c', 't.course_id = c.id')
			->where($query->expr()->eq('t.id', $query->createNamedParameter($rowId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('c.user_id', $query->createNamedParameter($userId)))
			->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if (!$row) {
			throw new DoesNotExistException($error);
		}
		return (int)$row['course_id'];
	}
}
