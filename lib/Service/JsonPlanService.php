<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Service;

/**
 * Provider-independent planning via a clean, documented JSON schema.
 *
 * Export a course to JSON, edit it anywhere (by hand or with any LLM), then
 * re-import it. Lessons are merged into an EXISTING course by (date, slot):
 * a matching lesson is overwritten, a new combination is created. Links and
 * students are added when missing. Nothing else is deleted.
 */
class JsonPlanService {
	private const SCHEMA_ID = 'course-plan';
	private const SCHEMA_VERSION = 1;

	public function __construct(
		private PlannerService $plannerService,
		private StudentService $studentService,
		private AttachmentService $attachmentService,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function exportCoursePlan(string $userId, int $courseId): array {
		$course = $this->plannerService->getCourse($userId, $courseId);
		$overview = $this->studentService->getOverview($userId, $courseId);

		$lessons = array_map(static function (array $lesson): array {
			return [
				'date' => (string)$lesson['lessonDate'],
				'slot' => (int)$lesson['lessonSlot'],
				'title' => (string)$lesson['title'],
				'goal' => (string)($lesson['goal'] ?? ''),
				'description' => (string)($lesson['description'] ?? ''),
				'reflection' => (string)($lesson['reflection'] ?? ''),
				'items' => array_map(static function (array $item): array {
					return [
						'title' => (string)$item['title'],
						'description' => (string)($item['description'] ?? ''),
						'teacherNote' => (string)($item['teacherNote'] ?? ''),
						'published' => (bool)($item['published'] ?? false),
					];
				}, $lesson['items'] ?? []),
			];
		}, $course['lessons'] ?? []);

		$links = array_map(static function (array $link): array {
			return ['label' => (string)$link['label'], 'url' => (string)$link['url']];
		}, $course['links'] ?? []);

		$students = array_map(static function (array $student): array {
			return ['name' => (string)$student['name'], 'note' => (string)($student['note'] ?? '')];
		}, $overview['students'] ?? []);

		return [
			'schoolplanner' => self::SCHEMA_ID,
			'version' => self::SCHEMA_VERSION,
			'course' => [
				'name' => (string)$course['name'],
				'description' => (string)($course['description'] ?? ''),
				'links' => $links,
				'students' => $students,
				'lessons' => $lessons,
			],
		];
	}

	/**
	 * Validate a plan and report what an import would do, without changing anything.
	 *
	 * @param mixed $plan
	 * @return array<string, mixed>
	 */
	public function previewCoursePlan(string $userId, int $courseId, mixed $plan): array {
		$course = $this->plannerService->getCourse($userId, $courseId);
		$parsed = $this->normalizePlan($plan);

		$errors = $parsed['errors'];
		$lessonsInput = $parsed['lessons'];

		$existing = $this->existingLessonIndex($course);
		$lessonReport = [];
		$newCount = 0;
		$overwriteCount = 0;
		$unchangedCount = 0;
		$seen = [];

		foreach ($lessonsInput as $index => $lesson) {
			$lineErrors = $this->validateLesson($lesson, $index);
			if ($lineErrors !== []) {
				$errors = array_merge($errors, $lineErrors);
				continue;
			}

			$key = $this->lessonKey((string)$lesson['date'], (int)$lesson['slot']);
			if (isset($seen[$key])) {
				$errors[] = sprintf('Stunde %d: Datum %s / Slot %d kommt mehrfach in der JSON vor.', $index + 1, $lesson['date'], $lesson['slot']);
				continue;
			}
			$seen[$key] = true;

			if (!isset($existing[$key])) {
				$status = 'new';
				$newCount++;
			} elseif ($this->lessonMatches($existing[$key], $lesson)) {
				// Inhaltlich identisch: Die Stunde wird beim Import uebersprungen.
				$status = 'unchanged';
				$unchangedCount++;
			} else {
				$status = 'overwrite';
				$overwriteCount++;
			}

			$lessonReport[] = [
				'date' => (string)$lesson['date'],
				'slot' => (int)$lesson['slot'],
				'title' => (string)($lesson['title'] ?? 'Neue Stunde'),
				'itemCount' => is_array($lesson['items'] ?? null) ? count($lesson['items']) : 0,
				'status' => $status,
			];
		}

		$existingLinkUrls = $this->lowercaseSet(array_column($course['links'] ?? [], 'url'));
		$linksNew = 0;
		foreach ($parsed['links'] as $link) {
			if (!isset($existingLinkUrls[mb_strtolower(trim((string)($link['url'] ?? '')))]) && trim((string)($link['url'] ?? '')) !== '') {
				$linksNew++;
			}
		}

		$existingStudentNames = $this->lowercaseSet(array_column($this->studentService->getOverview($userId, $courseId)['students'] ?? [], 'name'));
		$studentsNew = 0;
		foreach ($parsed['students'] as $student) {
			$name = is_array($student) ? (string)($student['name'] ?? '') : (string)$student;
			if (trim($name) !== '' && !isset($existingStudentNames[mb_strtolower(trim($name))])) {
				$studentsNew++;
			}
		}

		return [
			'valid' => $errors === [],
			'errors' => $errors,
			'courseName' => (string)$course['name'],
			'lessons' => $lessonReport,
			'summary' => [
				'new' => $newCount,
				'overwrite' => $overwriteCount,
				'unchanged' => $unchangedCount,
				'linksNew' => $linksNew,
				'studentsNew' => $studentsNew,
			],
		];
	}

	/**
	 * @param mixed $plan
	 * @return array<string, mixed>
	 */
	public function importCoursePlan(string $userId, int $courseId, mixed $plan): array {
		$preview = $this->previewCoursePlan($userId, $courseId, $plan);
		if ($preview['valid'] !== true) {
			throw new \InvalidArgumentException('Die Planung ist ungültig: ' . implode(' ', $preview['errors']));
		}

		$parsed = $this->normalizePlan($plan);
		$course = $this->plannerService->getCourse($userId, $courseId);
		$existing = $this->existingLessonIndex($course);

		$created = 0;
		$overwritten = 0;
		$unchanged = 0;

		foreach ($parsed['lessons'] as $lesson) {
			$key = $this->lessonKey((string)$lesson['date'], (int)$lesson['slot']);

			// Inhaltlich unveraenderte Stunden gar nicht erst anfassen: Das spart
			// nicht nur Arbeit, es haelt auch updated_at stabil und laesst
			// Anhaenge und Freigaben unberuehrt.
			if (isset($existing[$key]) && $this->lessonMatches($existing[$key], $lesson)) {
				$unchanged++;
				continue;
			}

			$fields = [
				'lessonDate' => (string)$lesson['date'],
				'lessonSlot' => (int)$lesson['slot'],
				'title' => (string)($lesson['title'] ?? 'Neue Stunde'),
				'goal' => (string)($lesson['goal'] ?? ''),
				'description' => (string)($lesson['description'] ?? ''),
				'reflection' => (string)($lesson['reflection'] ?? ''),
			];

			$preserved = [];
			if (isset($existing[$key])) {
				$lessonId = (int)$existing[$key]['id'];
				$this->plannerService->updateLesson($userId, $lessonId, $fields);
				// Remember publish/current state so a re-import does not silently
				// unpublish everything the teacher already released.
				$preserved = $this->clearLessonItems($userId, $lessonId);
				$overwritten++;
			} else {
				$createdLesson = $this->plannerService->createLesson($userId, $courseId, $fields);
				$lessonId = (int)$createdLesson['id'];
				$existing[$key] = ['id' => $lessonId];
				$created++;
			}

			$this->createItems($userId, $lessonId, is_array($lesson['items'] ?? null) ? $lesson['items'] : [], $preserved);
		}

		$this->importLinks($userId, $courseId, $parsed['links']);
		$studentsImported = $this->importStudents($userId, $courseId, $parsed['students']);

		return [
			'course' => $this->plannerService->getCourse($userId, $courseId),
			'summary' => [
				'lessonsCreated' => $created,
				'lessonsOverwritten' => $overwritten,
				'lessonsUnchanged' => $unchanged,
				'studentsImported' => $studentsImported,
			],
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 */
	private function createItems(string $userId, int $lessonId, array $items, array $preserved = []): void {
		$order = 0;
		foreach ($items as $item) {
			if (!is_array($item)) {
				continue;
			}
			$title = mb_substr(trim((string)($item['title'] ?? '')) ?: 'Element', 0, 255);
			$state = $preserved[$this->itemKey($title)] ?? null;

			$created = $this->plannerService->createLessonItem($userId, $lessonId, [
				'title' => $title,
				'description' => (string)($item['description'] ?? ''),
				'teacherNote' => (string)($item['teacherNote'] ?? ''),
				// Keep whatever the element had before the re-import.
				'published' => $state !== null ? (bool)$state['published'] : (bool)($item['published'] ?? false),
				'isCurrent' => $state !== null ? (bool)$state['isCurrent'] : false,
				'sortOrder' => $order,
			]);

			$this->attachFiles($userId, (int)$created['id'], is_array($item['files'] ?? null) ? $item['files'] : []);
			$order++;
		}
	}

	/**
	 * Deletes the current items of a lesson and returns their publish state,
	 * keyed by title, so it can be restored for re-imported elements.
	 *
	 * @return array<string, array{published: bool, isCurrent: bool}>
	 */
	private function clearLessonItems(string $userId, int $lessonId): array {
		$lesson = $this->plannerService->getLesson($lessonId, $userId);
		$state = [];
		foreach ($lesson['items'] ?? [] as $item) {
			$itemId = (int)$item['id'];
			$state[$this->itemKey((string)$item['title'])] = [
				'published' => (bool)($item['published'] ?? false),
				'isCurrent' => (bool)($item['isCurrent'] ?? false),
			];
			$this->attachmentService->deleteAttachmentsForItem($userId, $itemId);
			$this->plannerService->deleteLessonItem($userId, $itemId);
		}
		return $state;
	}

	private function itemKey(string $title): string {
		return mb_strtolower(trim($title));
	}

	/**
	 * Attaches files that were referenced relatively in the Markdown source.
	 *
	 * @param array<int, array{name: string, content: string, mime?: string}> $files
	 */
	private function attachFiles(string $userId, int $itemId, array $files): void {
		foreach ($files as $file) {
			if (!is_array($file) || trim((string)($file['name'] ?? '')) === '') {
				continue;
			}
			try {
				$this->attachmentService->createAttachmentFromContent(
					$itemId,
					(string)$file['name'],
					(string)($file['content'] ?? ''),
					(string)($file['mime'] ?? 'application/octet-stream')
				);
			} catch (\Throwable $exception) {
				// A single broken file must not abort the whole import.
			}
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $links
	 */
	private function importLinks(string $userId, int $courseId, array $links): void {
		$course = $this->plannerService->getCourse($userId, $courseId);
		$existing = $this->lowercaseSet(array_column($course['links'] ?? [], 'url'));

		foreach ($links as $link) {
			$url = trim((string)($link['url'] ?? ''));
			if ($url === '' || isset($existing[mb_strtolower($url)])) {
				continue;
			}
			$this->plannerService->createCourseLink($userId, $courseId, [
				'label' => (string)($link['label'] ?? ''),
				'url' => $url,
			]);
			$existing[mb_strtolower($url)] = true;
		}
	}

	/**
	 * @param array<int, mixed> $students
	 */
	private function importStudents(string $userId, int $courseId, array $students): int {
		$existing = $this->lowercaseSet(array_column($this->studentService->getOverview($userId, $courseId)['students'] ?? [], 'name'));
		$imported = 0;

		foreach ($students as $student) {
			$name = is_array($student) ? (string)($student['name'] ?? '') : (string)$student;
			$note = is_array($student) ? (string)($student['note'] ?? '') : '';
			$name = trim($name);
			if ($name === '' || isset($existing[mb_strtolower($name)])) {
				continue;
			}
			$this->studentService->createStudent($userId, $courseId, ['name' => $name, 'note' => $note]);
			$existing[mb_strtolower($name)] = true;
			$imported++;
		}

		return $imported;
	}

	/**
	 * Accepts either a decoded array or a raw JSON string.
	 *
	 * @param mixed $plan
	 * @return array{errors: array<int, string>, lessons: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>, students: array<int, mixed>}
	 */
	private function normalizePlan(mixed $plan): array {
		if (is_string($plan)) {
			$decoded = json_decode($plan, true);
			$plan = is_array($decoded) ? $decoded : null;
		}

		$errors = [];
		if (!is_array($plan)) {
			return ['errors' => ['Die JSON konnte nicht gelesen werden.'], 'lessons' => [], 'links' => [], 'students' => []];
		}

		if (($plan['schoolplanner'] ?? null) !== self::SCHEMA_ID) {
			$errors[] = 'Unbekanntes Format: erwartet wird "schoolplanner": "course-plan".';
		}

		$course = is_array($plan['course'] ?? null) ? $plan['course'] : [];
		$lessons = is_array($course['lessons'] ?? null) ? array_values(array_filter($course['lessons'], 'is_array')) : [];
		$links = is_array($course['links'] ?? null) ? array_values(array_filter($course['links'], 'is_array')) : [];
		$students = is_array($course['students'] ?? null) ? array_values($course['students']) : [];

		if ($lessons === [] && $errors === []) {
			$errors[] = 'Die Planung enthält keine Stunden.';
		}

		return ['errors' => $errors, 'lessons' => $lessons, 'links' => $links, 'students' => $students];
	}

	/**
	 * @param array<string, mixed> $lesson
	 * @return array<int, string>
	 */
	private function validateLesson(array $lesson, int $index): array {
		$errors = [];
		$date = trim((string)($lesson['date'] ?? ''));
		$slot = $lesson['slot'] ?? null;

		if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$this->isRealDate($date)) {
			$errors[] = sprintf('Stunde %d: Pflichtfeld "date" fehlt oder ist ungültig (Format YYYY-MM-DD).', $index + 1);
		}
		if (!is_numeric($slot) || (int)$slot < 1 || (int)$slot > 8) {
			$errors[] = sprintf('Stunde %d: Pflichtfeld "slot" fehlt oder liegt nicht zwischen 1 und 8.', $index + 1);
		}

		return $errors;
	}

	private function isRealDate(string $date): bool {
		[$year, $month, $day] = array_map('intval', explode('-', $date));
		return checkdate($month, $day, $year);
	}

	/**
	 * @param array<string, mixed> $course
	 * @return array<string, array<string, mixed>>
	 */
	private function existingLessonIndex(array $course): array {
		$index = [];
		foreach ($course['lessons'] ?? [] as $lesson) {
			$index[$this->lessonKey((string)$lesson['lessonDate'], (int)$lesson['lessonSlot'])] = $lesson;
		}
		return $index;
	}

	private function lessonKey(string $date, int $slot): string {
		return $date . '#' . $slot;
	}

	/**
	 * Vergleicht eine vorhandene Stunde mit der aus der Datei gelesenen.
	 *
	 * Verglichen wird bewusst die Form, in der die Daten GESCHRIEBEN wuerden –
	 * inklusive trim und Laengenbegrenzung –, sonst gaelte eine Stunde schon
	 * wegen eines Leerzeichens als geaendert.
	 *
	 * Im Zweifel lautet die Antwort "nicht gleich": Eine faelschlich als
	 * unveraendert eingestufte Stunde wuerde eine echte Aenderung verschlucken,
	 * eine faelschlich als geaendert eingestufte kostet nur einen ueberfluessigen
	 * Schreibvorgang.
	 *
	 * @param array<string, mixed> $existing Stunde aus der Datenbank
	 * @param array<string, mixed> $incoming Stunde aus der Markdown-/JSON-Quelle
	 */
	private function lessonMatches(array $existing, array $incoming): bool {
		if (!isset($existing['items']) || !is_array($existing['items'])) {
			return false;                      // ohne Elemente kein Vergleich
		}

		$head = static function (string $title, string $goal, string $description, string $reflection): array {
			return [
				'title' => mb_substr(trim($title) ?: 'Neue Stunde', 0, 255),
				'goal' => mb_substr(trim($goal), 0, 255),
				'description' => str_replace("\r\n", "\n", $description),
				'reflection' => str_replace("\r\n", "\n", $reflection),
			];
		};

		$left = $head(
			(string)($existing['title'] ?? ''),
			(string)($existing['goal'] ?? ''),
			(string)($existing['description'] ?? ''),
			(string)($existing['reflection'] ?? '')
		);
		$right = $head(
			(string)($incoming['title'] ?? ''),
			(string)($incoming['goal'] ?? ''),
			(string)($incoming['description'] ?? ''),
			(string)($incoming['reflection'] ?? '')
		);
		if ($left !== $right) {
			return false;
		}

		$existingItems = array_values($existing['items']);
		$incomingItems = is_array($incoming['items'] ?? null) ? array_values($incoming['items']) : [];
		if (count($existingItems) !== count($incomingItems)) {
			return false;
		}

		foreach ($incomingItems as $index => $item) {
			if (!is_array($item) || !is_array($existingItems[$index] ?? null)) {
				return false;
			}
			$stored = $existingItems[$index];

			// Referenzierte Dateien zaehlen mit: Tauscht jemand nur ein Bild aus,
			// bleibt der Text gleich – die Stunde muss trotzdem neu geschrieben
			// werden. Vorhandene Anhaenge werden dafuer ueber Name und Groesse
			// verglichen, weil der Inhalt in der Datenbank nicht danebenliegt.
			$incomingFiles = [];
			foreach ((is_array($item['files'] ?? null) ? $item['files'] : []) as $file) {
				if (!is_array($file)) {
					continue;
				}
				$incomingFiles[] = [
					'name' => (string)($file['name'] ?? ''),
					'size' => strlen((string)($file['content'] ?? '')),
				];
			}
			$storedFiles = [];
			foreach ((is_array($stored['attachments'] ?? null) ? $stored['attachments'] : []) as $attachment) {
				$storedFiles[] = [
					'name' => (string)($attachment['fileName'] ?? ''),
					'size' => (int)($attachment['size'] ?? 0),
				];
			}
			sort($incomingFiles);
			sort($storedFiles);

			$a = [
				'title' => mb_substr(trim((string)($stored['title'] ?? '')) ?: 'Element', 0, 255),
				'description' => str_replace("\r\n", "\n", (string)($stored['description'] ?? '')),
				'teacherNote' => mb_substr(trim((string)($stored['teacherNote'] ?? '')), 0, 255),
				'files' => $storedFiles,
			];
			$b = [
				'title' => mb_substr(trim((string)($item['title'] ?? '')) ?: 'Element', 0, 255),
				'description' => str_replace("\r\n", "\n", (string)($item['description'] ?? '')),
				'teacherNote' => mb_substr(trim((string)($item['teacherNote'] ?? '')), 0, 255),
				'files' => $incomingFiles,
			];
			if ($a !== $b) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<int, mixed> $values
	 * @return array<string, bool>
	 */
	private function lowercaseSet(array $values): array {
		$set = [];
		foreach ($values as $value) {
			$set[mb_strtolower(trim((string)$value))] = true;
		}
		return $set;
	}
}
