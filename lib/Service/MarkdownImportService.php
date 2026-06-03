<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

/**
 * Reads lesson units from Markdown files inside the user's Nextcloud Files and
 * feeds them into the same merge/preview logic as the JSON import.
 *
 * Convention: one Markdown file = one lesson. A small front matter block carries
 * the required date and slot. Second-level headings ("## …") become flow items.
 *
 *   ---
 *   date: 2026-09-01
 *   slot: 1
 *   title: Einführung Python
 *   goal: SuS verstehen Variablen
 *   ---
 *
 *   Beschreibung der Stunde in Markdown …
 *
 *   ## Warm-up
 *   Inhalt des Elements …
 *
 *   ## Übung
 *   Inhalt des Elements …
 */
class MarkdownImportService {
	public function __construct(
		private IRootFolder $rootFolder,
		private JsonPlanService $jsonPlanService,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function preview(string $userId, int $courseId, string $path): array {
		$build = $this->buildPlanFromPath($userId, $path);
		$preview = $this->jsonPlanService->previewCoursePlan($userId, $courseId, $build['plan']);

		if ($build['errors'] !== []) {
			$preview['errors'] = array_merge($build['errors'], $preview['errors']);
			$preview['valid'] = false;
		}
		$preview['filesRead'] = $build['filesRead'];

		return $preview;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function import(string $userId, int $courseId, string $path): array {
		$build = $this->buildPlanFromPath($userId, $path);
		if ($build['errors'] !== []) {
			throw new \InvalidArgumentException('Markdown-Import nicht möglich: ' . implode(' ', $build['errors']));
		}
		return $this->jsonPlanService->importCoursePlan($userId, $courseId, $build['plan']);
	}

	/**
	 * @return array{plan: array<string, mixed>, errors: array<int, string>, filesRead: int}
	 */
	private function buildPlanFromPath(string $userId, string $path): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$normalized = '/' . ltrim(trim($path), '/');

		try {
			$node = $userFolder->get($normalized);
		} catch (NotFoundException $exception) {
			throw new \InvalidArgumentException('Der Pfad wurde nicht gefunden: ' . $path);
		}

		$files = [];
		if ($node instanceof Folder) {
			foreach ($node->getDirectoryListing() as $child) {
				if ($child instanceof File && $this->isMarkdown($child->getName())) {
					$files[] = $child;
				}
			}
			usort($files, static fn (File $a, File $b): int => strcmp($a->getName(), $b->getName()));
		} elseif ($node instanceof File && $this->isMarkdown($node->getName())) {
			$files = [$node];
		} else {
			throw new \InvalidArgumentException('Bitte einen Ordner oder eine .md-Datei auswählen.');
		}

		$lessons = [];
		$errors = [];
		foreach ($files as $file) {
			$parsed = $this->parseFile((string)$file->getContent(), $file->getName());
			$errors = array_merge($errors, $parsed['errors']);
			foreach ($parsed['lessons'] as $lesson) {
				$lessons[] = $lesson;
			}
		}

		if ($files === []) {
			$errors[] = 'Im gewählten Ort wurden keine Markdown-Dateien (.md) gefunden.';
		}

		return [
			'plan' => [
				'schoolplanner' => 'course-plan',
				'version' => 1,
				'course' => ['lessons' => $lessons],
			],
			'errors' => $errors,
			'filesRead' => count($files),
		];
	}

	private function isMarkdown(string $name): bool {
		return (bool)preg_match('/\.(md|markdown)$/i', $name);
	}

	/**
	 * A file may contain one or several lessons. A new lesson starts at every
	 * line that begins with "date:". The "---" fences used by the Nextcloud Text
	 * editor are tolerated but not required.
	 *
	 * @return array{lessons: array<int, array<string, mixed>>, errors: array<int, string>}
	 */
	private function parseFile(string $content, string $fileName): array {
		$content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
		$lines = preg_split('/\R/', $content) ?: [];

		$starts = [];
		foreach ($lines as $i => $line) {
			if (preg_match('/^\s*date\s*:/i', $line)) {
				$starts[] = $i;
			}
		}

		if ($starts === []) {
			return [
				'lessons' => [],
				'errors' => [sprintf('%s: Keine Stunde erkannt. Es muss mindestens eine Zeile "date: JJJJ-MM-TT" geben.', $fileName)],
			];
		}

		$lessons = [];
		$errors = [];
		$count = count($starts);

		foreach ($starts as $idx => $start) {
			$end = ($idx + 1 < $count) ? $starts[$idx + 1] : count($lines);
			$section = array_slice($lines, $start, $end - $start);
			$lesson = $this->parseLessonSection($section, $fileName);

			$label = $count > 1 ? sprintf('%s (Stunde %d)', $fileName, $idx + 1) : $fileName;
			$lineErrors = $this->validateLesson($lesson, $label);
			if ($lineErrors !== []) {
				$errors = array_merge($errors, $lineErrors);
				continue;
			}
			$lessons[] = $lesson;
		}

		return ['lessons' => $lessons, 'errors' => $errors];
	}

	/**
	 * @param array<int, string> $section
	 * @return array<string, mixed>
	 */
	private function parseLessonSection(array $section, string $fileName): array {
		$meta = ['date' => null, 'slot' => null, 'title' => null, 'goal' => null];

		$index = 0;
		$total = count($section);
		for (; $index < $total; $index++) {
			$line = $section[$index];
			$trimmed = trim($line);
			if ($trimmed === '' || $trimmed === '---') {
				continue;
			}
			if (preg_match('/^\s*(date|slot|title|goal)\s*:\s*(.*?)\s*$/i', $line, $kv)) {
				$meta[strtolower($kv[1])] = trim($kv[2], " \t\"'");
				continue;
			}
			break;
		}

		$bodyLines = array_slice($section, $index);
		$descriptionLines = [];
		$items = [];
		$current = null;
		$titleHeadingUsed = false;

		foreach ($bodyLines as $line) {
			if (!$titleHeadingUsed && $current === null && $items === [] && preg_match('/^#\s+(.*)$/', $line, $h)) {
				if (($meta['title'] ?? '') === '' || $meta['title'] === null) {
					$meta['title'] = trim($h[1]);
				}
				$titleHeadingUsed = true;
				continue;
			}
			if (preg_match('/^##\s+(.*)$/', $line, $h)) {
				if ($current !== null) {
					$items[] = $current;
				}
				$current = ['title' => trim($h[1]), 'lines' => []];
				continue;
			}
			if ($current === null) {
				$descriptionLines[] = $line;
			} else {
				$current['lines'][] = $line;
			}
		}
		if ($current !== null) {
			$items[] = $current;
		}

		$title = (string)($meta['title'] ?? '');
		if ($title === '') {
			$title = preg_replace('/\.(md|markdown)$/i', '', $fileName) ?: 'Neue Stunde';
		}

		$normalizedItems = array_map(static function (array $item): array {
			return [
				'title' => $item['title'] !== '' ? $item['title'] : 'Element',
				'description' => trim(implode("\n", $item['lines'])),
			];
		}, $items);

		return [
			'date' => $this->normalizeDate((string)($meta['date'] ?? '')),
			'slot' => $meta['slot'],
			'title' => $title,
			'goal' => (string)($meta['goal'] ?? ''),
			'description' => trim($this->stripFences(implode("\n", $descriptionLines))),
			'items' => $normalizedItems,
		];
	}

	/**
	 * @param array<string, mixed> $lesson
	 * @return array<int, string>
	 */
	private function validateLesson(array $lesson, string $label): array {
		$errors = [];
		$date = trim((string)($lesson['date'] ?? ''));
		$slot = $lesson['slot'] ?? null;

		if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			$errors[] = sprintf('%s: "date" fehlt oder ist ungültig (Format JJJJ-MM-TT).', $label);
		}
		if ($slot === null || $slot === '' || !is_numeric($slot) || (int)$slot < 1 || (int)$slot > 8) {
			$errors[] = sprintf('%s: "slot" fehlt oder liegt nicht zwischen 1 und 8.', $label);
		}

		return $errors;
	}

	/**
	 * Normalises typographic dashes (en dash, em dash, minus sign) that editors
	 * sometimes substitute, so a date like 2026–09–01 still validates.
	 */
	private function normalizeDate(string $date): string {
		$date = str_replace(["\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}"], '-', trim($date));
		return $date;
	}

	private function stripFences(string $text): string {
		// Remove standalone "---" lines that the Text editor may leave behind.
		return preg_replace('/^\s*---\s*$/m', '', $text) ?? $text;
	}
}
