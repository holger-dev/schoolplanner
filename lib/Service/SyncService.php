<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Schnittstelle für den Offline-Client.
 *
 * Bewusst schmal gehalten: Der Client liest einen Ausschnitt der Planung und
 * schreibt ausschließlich Zustände zurück – Mitarbeit, Freigabe eines Elements
 * und "aktueller Schritt". Inhalte (Stunden, Texte, Schüler:innen) werden nur
 * gelesen. Dadurch legt der Client nie Datensätze mit unbekannter ID an, und
 * der Abgleich kommt ohne UUID-Zuordnung, Tombstones und Text-Merging aus.
 *
 * Es wird bewusst KEIN Delta über einen "since"-Zeitpunkt ausgeliefert, sondern
 * immer das vollständige Zeitfenster. Das kostet ein paar hundert Kilobyte und
 * spart dafür: Grabsteine für gelöschte Stunden, einen Cursor und jede
 * Abhängigkeit von der Uhr des Endgeräts. Gelöschte Stunden verschwinden
 * implizit, weil sie im Ausschnitt fehlen.
 */
class SyncService {
	public const API_VERSION = 1;

	private const DEFAULT_DAYS_BACK = 14;
	private const DEFAULT_DAYS_AHEAD = 42;
	private const MAX_DAYS = 400;

	public function __construct(
		private IDBConnection $connection,
		private PlannerService $plannerService,
		private StudentService $studentService,
		private PublishService $publishService,
	) {
	}

	/**
	 * Vollständiger Ausschnitt der Planung im gewählten Zeitfenster.
	 *
	 * @return array<string, mixed>
	 */
	public function pull(string $userId, string $from, string $to): array {
		[$from, $to] = $this->normalizeWindow($from, $to);

		$bootstrap = $this->plannerService->getBootstrap($userId);
		$courses = [];
		$lessonIds = [];

		foreach ($bootstrap['courses'] ?? [] as $course) {
			$courseId = (int)$course['id'];
			$lessons = [];

			foreach ($course['lessons'] ?? [] as $lesson) {
				$date = (string)($lesson['lessonDate'] ?? '');
				if ($date < $from || $date > $to) {
					continue;
				}
				$lessonIds[] = (int)$lesson['id'];
				$lessons[] = [
					'id' => (int)$lesson['id'],
					'lessonDate' => $date,
					'lessonSlot' => (int)($lesson['lessonSlot'] ?? 1),
					'title' => (string)($lesson['title'] ?? ''),
					'goal' => (string)($lesson['goal'] ?? ''),
					'description' => (string)($lesson['description'] ?? ''),
					'updatedAt' => (string)($lesson['updatedAt'] ?? ''),
					'items' => array_map(static function (array $item): array {
						return [
							'id' => (int)$item['id'],
							'title' => (string)($item['title'] ?? ''),
							'description' => (string)($item['description'] ?? ''),
							'teacherNote' => (string)($item['teacherNote'] ?? ''),
							'published' => (bool)($item['published'] ?? false),
							'isCurrent' => (bool)($item['isCurrent'] ?? false),
							'sortOrder' => (int)($item['sortOrder'] ?? 0),
							'updatedAt' => (string)($item['updatedAt'] ?? ''),
							'attachments' => array_map(static function (array $a): array {
								return [
									'id' => (int)$a['id'],
									'fileName' => (string)($a['fileName'] ?? ''),
									'mimeType' => (string)($a['mimeType'] ?? ''),
									'size' => (int)($a['size'] ?? 0),
								];
							}, $item['attachments'] ?? []),
						];
					}, $lesson['items'] ?? []),
				];
			}

			$courses[] = [
				'id' => $courseId,
				'name' => (string)($course['name'] ?? ''),
				'participationScale' => (string)($course['participationScale'] ?? ''),
				'students' => array_map(static function (array $s): array {
					return [
						'id' => (int)$s['id'],
						'name' => (string)($s['name'] ?? ''),
						'note' => (string)($s['note'] ?? ''),
					];
				}, $this->studentService->getOverview($userId, $courseId)['students'] ?? []),
				'lessons' => $lessons,
			];
		}

		return [
			'apiVersion' => self::API_VERSION,
			'serverTime' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'window' => ['from' => $from, 'to' => $to],
			'courses' => $courses,
			'participation' => $this->fetchParticipation($lessonIds),
		];
	}

	/**
	 * Zustände zurückschreiben. Pro Zeile gilt: Ist der mitgelieferte
	 * Zeitstempel neuer als der gespeicherte, wird geschrieben – sonst nicht.
	 * Verworfene Zeilen kommen samt aktuellem Serverstand zurück, damit der
	 * Client den Konflikt anzeigen kann statt still zu überschreiben.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function push(string $userId, array $payload): array {
		$now = new DateTimeImmutable();

		$participation = $this->pushParticipation(
			$userId,
			is_array($payload['participation'] ?? null) ? $payload['participation'] : [],
			$now
		);
		$items = $this->pushItems(
			$userId,
			is_array($payload['items'] ?? null) ? $payload['items'] : [],
			$now
		);

		return [
			'apiVersion' => self::API_VERSION,
			'serverTime' => $now->format(DateTimeInterface::ATOM),
			'participation' => $participation,
			'items' => $items,
			// Freigaben wirken erst, wenn die Schueler-Seite neu erzeugt wird.
			'published' => $this->republish($userId, $items),
		];
	}

	/**
	 * Kurze Auskunft für den Verbindungstest beim Einrichten.
	 *
	 * @return array<string, mixed>
	 */
	public function info(string $userId, string $appVersion): array {
		$courses = $this->plannerService->getCourses($userId);
		return [
			'apiVersion' => self::API_VERSION,
			'appVersion' => $appVersion,
			'serverTime' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'user' => $userId,
			'courses' => count($courses),
		];
	}

	/**
	 * Betroffene Kurse neu veroeffentlichen.
	 *
	 * Ohne diesen Schritt landet eine Freigabe zwar in der Datenbank, die
	 * Schueler-Seite bliebe aber auf dem alten Stand - der Sinn des Freigebens
	 * ginge damit verloren. Fehler (etwa fehlende SFTP-Zugangsdaten) brechen
	 * den Abgleich nicht ab, werden aber gemeldet, damit sie nicht unbemerkt
	 * bleiben.
	 *
	 * @param array<int, array<string, mixed>> $itemResults
	 * @return array<int, array<string, mixed>>
	 */
	private function republish(string $userId, array $itemResults): array {
		$courseIds = [];
		foreach ($itemResults as $result) {
			if (($result['result'] ?? '') !== 'applied') {
				continue;
			}
			try {
				$courseIds[$this->plannerService->getCourseIdForItem($userId, (int)$result['id'])] = true;
			} catch (\Throwable $e) {
				// Element inzwischen weg - dann gibt es auch nichts zu veroeffentlichen.
			}
		}

		$out = [];
		foreach (array_keys($courseIds) as $courseId) {
			try {
				$this->publishService->publishCourse($userId, (int)$courseId);
				$out[] = ['courseId' => (int)$courseId, 'ok' => true];
			} catch (\Throwable $e) {
				$out[] = ['courseId' => (int)$courseId, 'ok' => false, 'error' => $e->getMessage()];
			}
		}

		return $out;
	}

	// ------------------------------------------------------------------
	// Mitarbeit
	// ------------------------------------------------------------------

	/**
	 * @param array<int, array<string, mixed>> $entries
	 * @return array<int, array<string, mixed>>
	 */
	private function pushParticipation(string $userId, array $entries, DateTimeImmutable $now): array {
		$results = [];

		foreach ($entries as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$lessonId = (int)($entry['lessonId'] ?? 0);
			$studentId = (int)($entry['studentId'] ?? 0);
			$result = ['lessonId' => $lessonId, 'studentId' => $studentId];

			try {
				// Wirft, wenn die Stunde nicht dem angemeldeten Nutzer gehört.
				$lesson = $this->plannerService->getLesson($lessonId, $userId);
				$course = $this->plannerService->getCourse($userId, (int)$lesson['courseId']);
			} catch (\Throwable $e) {
				$results[] = $result + ['result' => 'unknown'];
				continue;
			}

			if (!$this->studentBelongsToCourse($studentId, (int)$course['id'])) {
				$results[] = $result + ['result' => 'unknown'];
				continue;
			}

			$stored = $this->fetchParticipationRow($lessonId, $studentId);
			$clientTime = $this->parseTime((string)($entry['updatedAt'] ?? ''));
			$serverTime = $stored ? $this->parseTime((string)$stored['updated_at']) : null;

			if ($stored !== null && $clientTime !== null && $serverTime !== null
				&& $clientTime->getTimestamp() <= $serverTime->getTimestamp()) {
				$results[] = $result + [
					'result' => 'skipped',
					'server' => $this->mapParticipationRow($stored),
				];
				continue;
			}

			$this->upsertParticipation(
				$lessonId,
				$studentId,
				$this->normalizeStatus((string)($entry['status'] ?? '')),
				$this->normalizeScale((string)($course['participationScale'] ?? '')),
				mb_substr(trim((string)($entry['grade'] ?? '')), 0, 16),
				(string)($entry['note'] ?? ''),
				$now,
				$stored !== null
			);

			$results[] = $result + [
				'result' => 'applied',
				'updatedAt' => $now->format(DateTimeInterface::ATOM),
			];
		}

		return $results;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchParticipation(array $lessonIds): array {
		if ($lessonIds === []) {
			return [];
		}

		$query = $this->connection->getQueryBuilder();
		$result = $query->select('*')
			->from('sp_participation')
			->where($query->expr()->in('lesson_id', $query->createNamedParameter($lessonIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeQuery();

		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = $this->mapParticipationRow($row);
		}
		$result->closeCursor();

		return $rows;
	}

	private function fetchParticipationRow(int $lessonId, int $studentId): ?array {
		$query = $this->connection->getQueryBuilder();
		$result = $query->select('*')
			->from('sp_participation')
			->where($query->expr()->eq('lesson_id', $query->createNamedParameter($lessonId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('student_id', $query->createNamedParameter($studentId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1)
			->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row === false ? null : $row;
	}

	private function upsertParticipation(
		int $lessonId,
		int $studentId,
		string $status,
		string $scale,
		string $grade,
		string $note,
		DateTimeImmutable $now,
		bool $exists,
	): void {
		$query = $this->connection->getQueryBuilder();

		if ($exists) {
			$query->update('sp_participation')
				->set('status', $query->createNamedParameter($status))
				->set('scale', $query->createNamedParameter($scale))
				->set('grade', $query->createNamedParameter($grade))
				->set('note', $query->createNamedParameter($note))
				->set('updated_at', $query->createNamedParameter($now->format('Y-m-d H:i:s')))
				->where($query->expr()->eq('lesson_id', $query->createNamedParameter($lessonId, IQueryBuilder::PARAM_INT)))
				->andWhere($query->expr()->eq('student_id', $query->createNamedParameter($studentId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			return;
		}

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

	private function studentBelongsToCourse(int $studentId, int $courseId): bool {
		$query = $this->connection->getQueryBuilder();
		$result = $query->select('id')
			->from('sp_students')
			->where($query->expr()->eq('id', $query->createNamedParameter($studentId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('course_id', $query->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1)
			->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row !== false;
	}

	// ------------------------------------------------------------------
	// Elementzustände (Freigabe, aktueller Schritt)
	// ------------------------------------------------------------------

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, array<string, mixed>>
	 */
	private function pushItems(string $userId, array $items, DateTimeImmutable $now): array {
		$results = [];

		foreach ($items as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$itemId = (int)($entry['id'] ?? 0);

			try {
				// Wirft, wenn das Element nicht dem angemeldeten Nutzer gehört.
				$item = $this->plannerService->getLessonItem($itemId, $userId);
			} catch (\Throwable $e) {
				$results[] = ['id' => $itemId, 'result' => 'unknown'];
				continue;
			}

			$clientTime = $this->parseTime((string)($entry['updatedAt'] ?? ''));
			$serverTime = $this->parseTime((string)($item['updatedAt'] ?? ''));

			if ($clientTime !== null && $serverTime !== null
				&& $clientTime->getTimestamp() <= $serverTime->getTimestamp()) {
				$results[] = [
					'id' => $itemId,
					'result' => 'skipped',
					'server' => [
						'published' => (bool)$item['published'],
						'isCurrent' => (bool)$item['isCurrent'],
						'updatedAt' => (string)($item['updatedAt'] ?? ''),
					],
				];
				continue;
			}

			$query = $this->connection->getQueryBuilder();
			$query->update('schoolplanner_items')
				->set('published', $query->createNamedParameter(
					(int)(bool)($entry['published'] ?? $item['published']), IQueryBuilder::PARAM_INT
				))
				->set('is_current', $query->createNamedParameter(
					(int)(bool)($entry['isCurrent'] ?? $item['isCurrent']), IQueryBuilder::PARAM_INT
				))
				->set('updated_at', $query->createNamedParameter($now->format('Y-m-d H:i:s')))
				->where($query->expr()->eq('id', $query->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)))
				->executeStatement();

			$results[] = [
				'id' => $itemId,
				'result' => 'applied',
				'updatedAt' => $now->format(DateTimeInterface::ATOM),
			];
		}

		return $results;
	}

	// ------------------------------------------------------------------
	// Hilfsfunktionen
	// ------------------------------------------------------------------

	/**
	 * @return array{0: string, 1: string}
	 */
	private function normalizeWindow(string $from, string $to): array {
		$today = new DateTimeImmutable('today');
		$valid = static fn (string $v): bool => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);

		$start = $valid($from) ? $from : $today->modify('-' . self::DEFAULT_DAYS_BACK . ' days')->format('Y-m-d');
		$end = $valid($to) ? $to : $today->modify('+' . self::DEFAULT_DAYS_AHEAD . ' days')->format('Y-m-d');

		if ($start > $end) {
			[$start, $end] = [$end, $start];
		}

		// Ein zu großes Fenster würde die Antwort unnötig aufblähen.
		$startDate = new DateTimeImmutable($start);
		$endDate = new DateTimeImmutable($end);
		if ((int)$startDate->diff($endDate)->days > self::MAX_DAYS) {
			$end = $startDate->modify('+' . self::MAX_DAYS . ' days')->format('Y-m-d');
		}

		return [$start, $end];
	}

	private function parseTime(string $value): ?DateTimeImmutable {
		if (trim($value) === '') {
			return null;
		}
		try {
			return new DateTimeImmutable($value);
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function mapParticipationRow(array $row): array {
		$updated = $this->parseTime((string)($row['updated_at'] ?? ''));
		return [
			'lessonId' => (int)$row['lesson_id'],
			'studentId' => (int)$row['student_id'],
			'status' => (string)($row['status'] ?? ''),
			'scale' => (string)($row['scale'] ?? ''),
			'grade' => (string)($row['grade'] ?? ''),
			'note' => (string)($row['note'] ?? ''),
			'updatedAt' => $updated ? $updated->format(DateTimeInterface::ATOM) : '',
		];
	}

	private function normalizeStatus(string $status): string {
		return in_array($status, ['', 'present', 'excused', 'unexcused'], true) ? $status : '';
	}

	private function normalizeScale(string $scale): string {
		return in_array($scale, ['', 'scale3', 'scale5', 'note'], true) ? $scale : '';
	}
}
