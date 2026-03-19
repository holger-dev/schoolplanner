<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Service;

use DateTimeImmutable;

class ImportExportService {
	public function __construct(
		private PlannerService $plannerService,
		private AttachmentService $attachmentService,
	) {
	}

	/**
	 * @param array<int> $courseIds
	 * @return array{content: string, fileName: string}
	 */
	public function exportArchive(string $userId, array $courseIds = []): array {
		if (!class_exists(\ZipArchive::class)) {
			throw new \RuntimeException('ZIP-Unterstützung ist auf dem Server nicht verfügbar.');
		}

		$bootstrap = $this->plannerService->getBootstrap($userId);
		$courses = array_values(array_filter(
			$bootstrap['courses'] ?? [],
			static fn (array $course): bool => $courseIds === [] || in_array((int)$course['id'], $courseIds, true)
		));
		$export = [
			'version' => 1,
			'exportedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			'courses' => [],
		];

		$tempFile = tempnam(sys_get_temp_dir(), 'schoolplanner-export-');
		if ($tempFile === false) {
			throw new \RuntimeException('Temporäre Export-Datei konnte nicht angelegt werden.');
		}

		$zip = new \ZipArchive();
		if ($zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			@unlink($tempFile);
			throw new \RuntimeException('ZIP-Datei konnte nicht erstellt werden.');
		}

		foreach ($courses as $courseIndex => $course) {
			$exportCourse = [
				'name' => (string)$course['name'],
				'description' => (string)($course['description'] ?? ''),
				'lessons' => [],
			];

			foreach ($course['lessons'] ?? [] as $lessonIndex => $lesson) {
				$exportLesson = [
					'lessonDate' => (string)$lesson['lessonDate'],
					'lessonSlot' => (int)($lesson['lessonSlot'] ?? 1),
					'title' => (string)$lesson['title'],
					'goal' => (string)($lesson['goal'] ?? ''),
					'description' => (string)($lesson['description'] ?? ''),
					'reflection' => (string)($lesson['reflection'] ?? ''),
					'sortOrder' => (int)($lesson['sortOrder'] ?? 0),
					'items' => [],
				];

				foreach ($lesson['items'] ?? [] as $itemIndex => $item) {
					$exportItem = [
						'title' => (string)$item['title'],
						'description' => (string)($item['description'] ?? ''),
						'teacherNote' => (string)($item['teacherNote'] ?? ''),
						'published' => (bool)($item['published'] ?? false),
						'isCurrent' => (bool)($item['isCurrent'] ?? false),
						'sortOrder' => (int)($item['sortOrder'] ?? 0),
						'attachments' => [],
					];

					foreach ($item['attachments'] ?? [] as $attachment) {
						$archivePath = sprintf(
							'attachments/course-%d/lesson-%d/item-%d/%s',
							$courseIndex,
							$lessonIndex,
							$itemIndex,
							(string)$attachment['storedName']
						);
						$zip->addFromString($archivePath, $this->attachmentService->readAttachmentContent($attachment));
						$exportItem['attachments'][] = [
							'fileName' => (string)$attachment['fileName'],
							'mimeType' => (string)($attachment['mimeType'] ?? 'application/octet-stream'),
							'archivePath' => $archivePath,
						];
					}

					$exportLesson['items'][] = $exportItem;
				}

				$exportCourse['lessons'][] = $exportLesson;
			}

			$export['courses'][] = $exportCourse;
		}

		$zip->addFromString(
			'schoolplanner-export.json',
			json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
		);
		$zip->close();

		$content = file_get_contents($tempFile);
		@unlink($tempFile);
		if ($content === false) {
			throw new \RuntimeException('Export-Datei konnte nicht gelesen werden.');
		}

		return [
			'content' => $content,
			'fileName' => 'schoolplanner-export-' . (new DateTimeImmutable())->format('Ymd-His') . '.zip',
		];
	}

	/**
	 * @param array<string, mixed> $uploadedFile
	 * @return array{coursesImported: int}
	 */
	public function importArchive(string $userId, array $uploadedFile): array {
		if (!class_exists(\ZipArchive::class)) {
			throw new \RuntimeException('ZIP-Unterstützung ist auf dem Server nicht verfügbar.');
		}
		if (!isset($uploadedFile['tmp_name']) || !is_string($uploadedFile['tmp_name']) || !is_file($uploadedFile['tmp_name'])) {
			throw new \RuntimeException('Keine Import-Datei hochgeladen.');
		}

		$zip = new \ZipArchive();
		if ($zip->open($uploadedFile['tmp_name']) !== true) {
			throw new \RuntimeException('ZIP-Datei konnte nicht geöffnet werden.');
		}

		$rawJson = $zip->getFromName('schoolplanner-export.json');
		if (!is_string($rawJson)) {
			$zip->close();
			throw new \RuntimeException('schoolplanner-export.json fehlt im Archiv.');
		}

		$payload = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
		$coursesImported = 0;

		foreach ($payload['courses'] ?? [] as $course) {
			$createdCourse = $this->plannerService->createCourse($userId, [
				'name' => (string)($course['name'] ?? 'Importierter Kurs'),
				'description' => (string)($course['description'] ?? ''),
			]);

			foreach ($course['lessons'] ?? [] as $lesson) {
				$createdLesson = $this->plannerService->createLesson($userId, (int)$createdCourse['id'], [
					'lessonDate' => (string)($lesson['lessonDate'] ?? (new DateTimeImmutable())->format('Y-m-d')),
					'lessonSlot' => (int)($lesson['lessonSlot'] ?? 1),
					'title' => (string)($lesson['title'] ?? 'Neue Stunde'),
					'goal' => (string)($lesson['goal'] ?? ''),
					'description' => (string)($lesson['description'] ?? ''),
					'reflection' => (string)($lesson['reflection'] ?? ''),
					'sortOrder' => (int)($lesson['sortOrder'] ?? 0),
				]);

				foreach ($lesson['items'] ?? [] as $item) {
					$createdItem = $this->plannerService->createLessonItem($userId, (int)$createdLesson['id'], [
						'title' => (string)($item['title'] ?? 'Neues Element'),
						'description' => (string)($item['description'] ?? ''),
						'teacherNote' => (string)($item['teacherNote'] ?? ''),
						'published' => (bool)($item['published'] ?? false),
						'isCurrent' => (bool)($item['isCurrent'] ?? false),
						'sortOrder' => (int)($item['sortOrder'] ?? 0),
					]);

					foreach ($item['attachments'] ?? [] as $attachment) {
						$archivePath = (string)($attachment['archivePath'] ?? '');
						if ($archivePath === '') {
							continue;
						}
						$content = $zip->getFromName($archivePath);
						if (!is_string($content)) {
							continue;
						}
						$this->attachmentService->createAttachmentFromContent(
							(int)$createdItem['id'],
							(string)($attachment['fileName'] ?? 'datei'),
							$content,
							(string)($attachment['mimeType'] ?? 'application/octet-stream')
						);
					}
				}
			}

			$coursesImported++;
		}

		$zip->close();

		return [
			'coursesImported' => $coursesImported,
		];
	}
}
