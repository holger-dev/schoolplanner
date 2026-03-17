<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Service;

use DateTimeImmutable;
use League\CommonMark\CommonMarkConverter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use phpseclib3\Net\SFTP;

class PublishService {
	public function __construct(
		private PlannerService $plannerService,
		private AttachmentService $attachmentService,
		private SettingsService $settingsService,
		private IDBConnection $connection,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function publishCourseForItem(string $userId, int $itemId): array {
		return $this->publishCourse($userId, $this->plannerService->getCourseIdForItem($userId, $itemId));
	}

	/**
	 * @return array<string, mixed>
	 */
	public function publishCourse(string $userId, int $courseId): array {
		$settings = $this->settingsService->getSettings($userId);
		$this->assertSettingsAreComplete($settings);

		$course = $this->plannerService->getCourse($userId, $courseId);
		$allCourses = $this->plannerService->getBootstrap($userId)['courses'];
		$site = $this->buildSite($settings, $course, $allCourses);

		$sftp = new SFTP($settings['sftpHost'], 22, 15);
		if (!$sftp->login($settings['sftpUsername'], $settings['sftpPassword'])) {
			throw new \RuntimeException('SFTP-Anmeldung fehlgeschlagen.');
		}

		$this->syncCourseFiles($sftp, $site['courseRoot'], $site['courseFiles']);
		$this->putFile($sftp, $site['remoteRoot'] . '/index.html', $site['rootIndex']);

		$this->storePublishedUrl($courseId, $site['coursePublicUrl']);

		return [
			'ok' => true,
			'publicUrl' => $site['coursePublicUrl'],
			'course' => $this->plannerService->getCourse($userId, $courseId),
		];
	}

	/**
	 * @param array<string, string> $settings
	 */
	private function assertSettingsAreComplete(array $settings): void {
		$required = [
			'sftpHost' => 'SFTP-Host',
			'sftpUsername' => 'SFTP-Benutzername',
			'sftpPassword' => 'SFTP-Passwort',
			'publicBaseUrl' => 'Oeffentliche Basis-URL',
		];

		foreach ($required as $key => $label) {
			if (trim($settings[$key] ?? '') === '') {
				throw new \RuntimeException($label . ' fehlt.');
			}
		}
	}

	/**
	 * @param array<string, string> $settings
	 * @param array<string, mixed> $course
	 * @param array<int, array<string, mixed>> $allCourses
	 * @return array<string, mixed>
	 */
	private function buildSite(array $settings, array $course, array $allCourses): array {
		$remoteRoot = '';
		$publicRoot = rtrim(trim($settings['publicBaseUrl']), '/');
		$courseRoot = $remoteRoot . '/courses/' . $course['publishSlug'];
		$coursePublicUrl = $publicRoot . '/courses/' . $course['publishSlug'] . '/';

		$courseFiles = [];
		$courseFiles['index.html'] = $this->renderCoursePage($course, $publicRoot);

		foreach ($course['lessons'] as $lesson) {
			$courseFiles['lessons/' . $this->lessonSlug($lesson) . '.html'] = $this->renderLessonPage($course, $lesson, $publicRoot);
			foreach ($lesson['items'] as $item) {
				foreach (($item['attachments'] ?? []) as $attachment) {
					$courseFiles['assets/item-' . $item['id'] . '/' . $attachment['fileName']] = $this->attachmentService->readAttachmentContent($attachment);
				}
			}
		}

		$courseFiles['.schoolplanner-manifest.json'] = json_encode(array_keys($courseFiles), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

		$rootIndex = $this->renderHomePage($allCourses, $course, $publicRoot);

		return [
			'remoteRoot' => $remoteRoot,
			'courseRoot' => $courseRoot,
			'courseFiles' => $courseFiles,
			'rootIndex' => $rootIndex,
			'coursePublicUrl' => $coursePublicUrl,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $allCourses
	 * @param array<string, mixed> $currentCourse
	 */
	private function renderHomePage(array $allCourses, array $currentCourse, string $publicRoot): string {
		$courses = array_values(array_filter($allCourses, static function (array $course) use ($currentCourse): bool {
			return $course['id'] === $currentCourse['id'] || $course['publishedUrl'] !== null;
		}));

		usort($courses, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

		$items = array_map(function (array $course) use ($publicRoot): string {
			$url = $publicRoot . '/courses/' . $course['publishSlug'] . '/';
			return '<li><a href="' . $this->escape($url) . '">' . $this->escape($course['name']) . '</a></li>';
		}, $courses);

		return $this->renderPage(
			'Kurse',
			'<section><h1>Kurse</h1><p>Waehle deinen Kurs und oeffne danach die Makroplanung oder eine einzelne Stunde.</p><ul>' . implode('', $items) . '</ul></section>'
		);
	}

	/**
	 * @param array<string, mixed> $course
	 */
	private function renderCoursePage(array $course, string $publicRoot): string {
		$lessons = array_map(function (array $lesson) use ($course, $publicRoot): string {
			$url = $publicRoot . '/courses/' . $course['publishSlug'] . '/lessons/' . $this->lessonSlug($lesson) . '.html';
			$publishedItems = array_values(array_filter(
				$lesson['items'],
				static fn (array $item): bool => (bool)$item['published']
			));
			$publishedMarkup = array_map(function (array $item): string {
				$attachmentLinks = array_map(function (array $attachment) use ($item): string {
					$url = 'assets/item-' . $item['id'] . '/' . rawurlencode($attachment['fileName']);
					return '<li><a href="' . $this->escape($url) . '" download>' . $this->escape($attachment['fileName']) . '</a></li>';
				}, $item['attachments'] ?? []);

				return '<div class="published-item"><h3>' . $this->escape($item['title']) . '</h3><div class="copy">' . $this->renderMarkdown($item['description']) . '</div>'
					. ($attachmentLinks === [] ? '' : '<ul>' . implode('', $attachmentLinks) . '</ul>')
					. '</div>';
			}, $publishedItems);

			return '<article class="card">'
				. '<p class="meta">' . $this->escape($lesson['lessonDate']) . '</p>'
				. '<h2>' . $this->escape($lesson['title']) . '</h2>'
				. '<div class="copy">' . $this->renderMarkdown($lesson['description']) . '</div>'
				. ($publishedMarkup === [] ? '' : '<h3>Veröffentlichte Elemente</h3>' . implode('', $publishedMarkup))
				. '<p><a href="' . $this->escape($url) . '">Zur Stunde</a></p>'
				. '</article>';
		}, $course['lessons']);

		return $this->renderPage(
			$course['name'],
			'<nav><a href="../../index.html">Kursuebersicht</a></nav>'
			. '<section><h1>' . $this->escape($course['name']) . '</h1>'
			. '<div class="copy">' . $this->renderMarkdown($course['description']) . '</div>'
			. '<h2>Makroplanung</h2>'
			. implode('', $lessons)
			. '</section>'
		);
	}

	/**
	 * @param array<string, mixed> $course
	 * @param array<string, mixed> $lesson
	 */
	private function renderLessonPage(array $course, array $lesson, string $publicRoot): string {
		$publishedItems = array_values(array_filter(
			$lesson['items'],
			static fn (array $item): bool => (bool)$item['published']
		));

		$itemMarkup = array_map(function (array $item): string {
			$attachmentLinks = array_map(function (array $attachment) use ($item): string {
				$url = '../assets/item-' . $item['id'] . '/' . rawurlencode($attachment['fileName']);
				return '<li><a href="' . $this->escape($url) . '" download>' . $this->escape($attachment['fileName']) . '</a></li>';
			}, $item['attachments'] ?? []);

			return '<article class="card"><h2>' . $this->escape($item['title']) . '</h2>'
				. '<div class="copy">' . $this->renderMarkdown($item['description']) . '</div>'
				. ($attachmentLinks === [] ? '' : '<h3>Dateien</h3><ul>' . implode('', $attachmentLinks) . '</ul>')
				. '</article>';
		}, $publishedItems);

		$content = '<nav><a href="../../../index.html">Kursuebersicht</a> / <a href="../index.html">' . $this->escape($course['name']) . '</a></nav>'
			. '<section><p class="meta">' . $this->escape($lesson['lessonDate']) . '</p>'
			. '<h1>' . $this->escape($lesson['title']) . '</h1>'
			. '<div class="copy">' . $this->renderMarkdown($lesson['description']) . '</div>'
			. '<h2>Veröffentlichte Elemente</h2>'
			. ($itemMarkup === [] ? '<p>Fuer diese Stunde sind aktuell keine Elemente veroeffentlicht.</p>' : implode('', $itemMarkup))
			. '</section>';

		return $this->renderPage($lesson['title'] . ' - ' . $course['name'], $content);
	}

	private function renderPage(string $title, string $content): string {
		return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . $this->escape($title) . '</title>'
			. '<style>'
			. 'body{font-family:system-ui,sans-serif;max-width:980px;margin:0 auto;padding:2rem 1rem;background:#f7f8f5;color:#1d2733;}'
			. 'a{color:#1557c0;text-decoration:none;}a:hover{text-decoration:underline;}'
			. 'nav{margin-bottom:1.5rem;}'
			. '.card{background:#fff;border:1px solid #d6dee8;border-radius:16px;padding:1rem 1.25rem;margin-bottom:1rem;box-shadow:0 10px 24px rgba(13,31,52,.06);}'
			. '.published-item{border-top:1px solid #e4ebf3;margin-top:1rem;padding-top:1rem;}'
			. '.meta{color:#587086;font-size:.95rem;margin:0 0 .5rem;}'
			. '.copy p,.copy li{line-height:1.6;}'
			. '.copy pre{background:#0f1720;color:#f7fafc;padding:1rem;border-radius:12px;overflow:auto;}'
			. '.copy code{background:#eef3f9;padding:.1rem .35rem;border-radius:6px;}'
			. '.copy pre code{background:transparent;padding:0;color:inherit;}'
			. '</style></head><body>' . $content . '</body></html>';
	}

	private function lessonSlug(array $lesson): string {
		$title = strtolower((string)$lesson['title']);
		$title = preg_replace('/[^a-z0-9]+/', '-', $title) ?? '';
		$title = trim($title, '-');
		return $lesson['lessonDate'] . '-' . ($title !== '' ? $title : 'stunde-' . $lesson['id']);
	}

	private function escape(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	private function renderMarkdown(string $value): string {
		$converter = new CommonMarkConverter([
			'html_input' => 'strip',
			'allow_unsafe_links' => false,
		]);
		return (string)$converter->convert($value);
	}

	/**
	 * @param array<string, string> $files
	 */
	private function syncCourseFiles(SFTP $sftp, string $courseRoot, array $files): void {
		$manifestPath = $courseRoot . '/.schoolplanner-manifest.json';
		$oldManifest = [];
		if ($sftp->file_exists($manifestPath)) {
			$decoded = json_decode((string)$sftp->get($manifestPath), true);
			$oldManifest = is_array($decoded) ? $decoded : [];
		}

		foreach ($oldManifest as $relativePath) {
			if (!isset($files[$relativePath])) {
				$sftp->delete($courseRoot . '/' . ltrim((string)$relativePath, '/'));
			}
		}

		foreach ($files as $relativePath => $content) {
			$this->putFile($sftp, $courseRoot . '/' . ltrim($relativePath, '/'), $content);
		}
	}

	private function putFile(SFTP $sftp, string $remoteFile, string $content): void {
		$this->ensureDirectory($sftp, dirname($remoteFile));
		if (!$sftp->put($remoteFile, $content)) {
			throw new \RuntimeException('Datei konnte nicht hochgeladen werden: ' . $remoteFile);
		}
	}

	private function ensureDirectory(SFTP $sftp, string $directory): void {
		$segments = array_filter(explode('/', trim($directory, '/')));
		$path = '';
		foreach ($segments as $segment) {
			$path .= '/' . $segment;
			if (!$sftp->is_dir($path) && !$sftp->mkdir($path)) {
				throw new \RuntimeException('Verzeichnis konnte nicht angelegt werden: ' . $path);
			}
		}
	}

	private function storePublishedUrl(int $courseId, string $publishedUrl): void {
		$query = $this->connection->getQueryBuilder();
		$query->update('schoolplanner_courses')
			->set('published_url', $query->createNamedParameter($publishedUrl))
			->where($query->expr()->eq('id', $query->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
