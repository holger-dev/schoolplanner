<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Service;

use DateTimeImmutable;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use phpseclib3\Net\SFTP;

class PublishService {
	private ?MarkdownConverter $markdownConverter = null;

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

		$course = $this->filterLessonsForPublishing($this->plannerService->getCourse($userId, $courseId));
		$allCourses = array_map(
			fn (array $publishedCourse): array => $this->filterLessonsForPublishing($publishedCourse),
			$this->plannerService->getBootstrap($userId)['courses']
		);
		$site = $this->buildSite($settings, $course, $allCourses);

		$sftp = $this->connectSftp($settings['sftpHost'], $settings['sftpUsername'], $settings['sftpPassword']);

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

	private function connectSftp(string $host, string $username, string $password): SFTP {
		$candidates = [$host];
		$records = dns_get_record($host, DNS_A);
		if (is_array($records)) {
			foreach ($records as $record) {
				$ip = $record['ip'] ?? null;
				if (is_string($ip) && $ip !== '' && !in_array($ip, $candidates, true)) {
					$candidates[] = $ip;
				}
			}
		}

		$lastException = null;
		foreach ($candidates as $candidate) {
			try {
				$sftp = new SFTP($candidate, 22, 15);
				if ($sftp->login($username, $password)) {
					return $sftp;
				}
			} catch (\Throwable $exception) {
				$lastException = $exception;
			}
		}

		if ($lastException instanceof \Throwable) {
			throw $lastException;
		}

		throw new \RuntimeException('SFTP-Anmeldung fehlgeschlagen.');
	}

	/**
	 * @param array<string, string> $settings
	 * @param array<string, mixed> $course
	 * @param array<int, array<string, mixed>> $allCourses
	 * @return array<string, mixed>
	 */
	private function buildSite(array $settings, array $course, array $allCourses): array {
		// Optional target directory on the server (e.g. "html" on Uberspace).
		// Empty = server root (previous behaviour). Relative paths are resolved
		// from the SFTP login directory (usually the user's home).
		$remoteRoot = rtrim(trim($settings['sftpRemotePath'] ?? ''), '/');
		$publicRoot = rtrim(trim($settings['publicBaseUrl']), '/');
		$courseRoot = $remoteRoot . '/courses/' . $course['publishSlug'];
		$coursePublicUrl = $publicRoot . '/courses/' . $course['publishSlug'] . '/';
		$publishedAt = (new DateTimeImmutable())->format(DATE_ATOM);

		$courseFiles = [];
		$courseFiles['index.html'] = $this->renderCoursePage($course, $publicRoot);
		$courseFiles['__schoolplanner_version.json'] = json_encode([
			'updatedAt' => $publishedAt,
		], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

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
	 * @param array<string, mixed> $course
	 * @return array<string, mixed>
	 */
	private function filterLessonsForPublishing(array $course): array {
		$course['lessons'] = array_values(array_filter(
			$course['lessons'] ?? [],
			static fn (array $lesson): bool => count($lesson['items'] ?? []) > 0
		));

		return $course;
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

		$searchEntries = [];
		$items = array_map(function (array $course) use ($publicRoot): string {
			$url = $publicRoot . '/courses/' . $course['publishSlug'] . '/';
			$lessonCount = count($course['lessons'] ?? []);

			return '<a class="course-card" href="' . $this->escape($url) . '">'
				. '<h2>' . $this->escape($course['name']) . '</h2>'
				. '<div class="course-card__meta"><span>' . $this->escape((string)$lessonCount) . ' Stunden</span><span>Öffnen</span></div>'
				. '</a>';
		}, $courses);

		foreach ($courses as $course) {
			$courseUrl = $publicRoot . '/courses/' . $course['publishSlug'] . '/';
			$searchEntries[] = [
				'type' => 'Kurs',
				'title' => (string)$course['name'],
				'subtitle' => count($course['lessons'] ?? []) . ' Stunden',
				'url' => $courseUrl,
				'search' => mb_strtolower(trim((string)$course['name'] . ' ' . (string)($course['description'] ?? ''))),
			];
			foreach ($course['lessons'] ?? [] as $lesson) {
				$lessonHash = 'lesson-' . $this->lessonSlug($lesson);
				$searchEntries[] = [
					'type' => 'Stunde',
					'title' => (string)$lesson['title'],
					'subtitle' => trim($this->formatLessonDate((string)$lesson['lessonDate']) . ' · ' . (string)($course['name'] ?? '')),
					'url' => $courseUrl . '#' . $lessonHash,
					'search' => mb_strtolower(trim(
						(string)$course['name'] . ' '
						. (string)$lesson['title'] . ' '
						. (string)($lesson['goal'] ?? '') . ' '
						. (string)($lesson['description'] ?? '')
					)),
				];
			}
		}

		$searchJson = json_encode(
			$searchEntries,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		return $this->renderPage(
			'Kurse',
			'<section class="hero">'
			. '<h1>Kursübersicht</h1>'
			. '<p>Klicke auf deinen Kurs.</p>'
			. '<div class="search-shell">'
			. '<label class="search-label" for="global-search">Suche</label>'
			. '<input id="global-search" class="search-input" type="search" placeholder="Kurse und Stunden durchsuchen">'
			. '<div id="search-results" class="search-results" hidden></div>'
			. '</div>'
			. '</section>'
			. '<section class="course-grid">' . implode('', $items) . '</section>'
			. '<script>window.schoolplannerSearchEntries=' . $searchJson . ';</script>'
		);
	}

	/**
	 * @param array<string, mixed> $course
	 */
	private function renderCoursePage(array $course, string $publicRoot): string {
		$currentLesson = $this->selectCurrentLesson($course['lessons']);
		$today = new DateTimeImmutable('today');

		$lessonItems = array_map(function (array $lesson) use ($course, $publicRoot, $currentLesson, $today): string {
			$panelId = 'lesson-' . $this->lessonSlug($lesson);
			$isCurrent = $currentLesson !== null && (int)$currentLesson['id'] === (int)$lesson['id'];
			$isToday = $lesson['lessonDate'] === $today->format('Y-m-d');
			$badge = $isToday ? '<span class="pill">Heute</span>' : ($isCurrent ? '<span class="pill">Aktuell</span>' : '');
			$publishedCount = count(array_filter(
				$lesson['items'],
				static fn (array $item): bool => (bool)$item['published']
			));

			// Bewusst eine Zeile je Stunde: Bei 30 Terminen ist eine Liste aus
			// Kacheln mit Zielsatz nicht mehr zu ueberblicken.
			$short = (new DateTimeImmutable($lesson['lessonDate']))->format('d.m.');
			$weekday = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'][(int)(new DateTimeImmutable($lesson['lessonDate']))->format('N') - 1];

			return '<a class="lesson-list-item' . ($isCurrent ? ' lesson-list-item--active' : '') . ($isToday ? ' lesson-list-item--today' : '') . '" href="#' . $this->escape($panelId) . '" data-lesson-panel="' . $this->escape($panelId) . '" title="' . $this->escape($lesson['title']) . '">'
				. '<span class="lli-date">' . $this->escape($weekday . ' ' . $short) . '</span>'
				. '<span class="lli-title">' . $this->escape($lesson['title']) . '</span>'
				. ($badge !== '' ? '<span class="lli-dot" aria-hidden="true"></span>' : '<span class="lli-count">' . $this->escape((string)$publishedCount) . '</span>')
				. '</a>';
		}, $course['lessons']);

		$currentLessonMarkup = $currentLesson === null
			? '<article class="card card--focus"><h2>Noch keine Stunde angelegt</h2><p>Für diesen Kurs gibt es aktuell noch keine veröffentlichte Planung.</p></article>'
			: implode('', array_map(function (array $lesson) use ($course, $currentLesson): string {
				$panelId = 'lesson-' . $this->lessonSlug($lesson);
				$isActive = (int)$currentLesson['id'] === (int)$lesson['id'];
				return $this->renderLessonDetail($course, $lesson, 'assets', true, $panelId, $isActive);
			}, $course['lessons']));

		$linksBlock = $this->renderCourseLinks($course);

		// Kursname und wichtige Links bleiben beim Scrollen stehen, damit die
		// Links jederzeit erreichbar sind und der Kopf keine Hoehe frisst.
		return $this->renderPage(
			$course['name'],
			'<div class="course-bar"><div class="course-bar__inner">'
			. '<a class="course-bar__back" href="../../index.html">&larr; Kurse</a>'
			. '<span class="course-bar__name">' . $this->escape($course['name']) . '</span>'
			. $linksBlock
			. '</div></div>'
			. '<section class="course-layout">'
			. '<aside class="sidebar-card"><div class="sidebar-card__header"><h2>Stunden</h2>'
			. '<span class="sidebar-card__count">' . count($course['lessons']) . '</span></div>'
			. ($lessonItems === [] ? '<p>Noch keine Stunden vorhanden.</p>' : '<div class="lesson-list-public">' . implode('', $lessonItems) . '</div>')
			. '</aside>'
			. '<div class="content-stage">' . $currentLessonMarkup . '</div>'
			. '</section>',
			'__schoolplanner_version.json'
		);
	}

	/**
	 * @param array<string, mixed> $course
	 * @param array<string, mixed> $lesson
	 */
	private function renderLessonPage(array $course, array $lesson, string $publicRoot): string {
		$content = '<nav><a href="../../../index.html">Kursübersicht</a> / <a href="../index.html">' . $this->escape($course['name']) . '</a></nav>'
			. $this->renderLessonDetail($course, $lesson, '../assets', false);

		return $this->renderPage($lesson['title'] . ' - ' . $course['name'], $content, '../__schoolplanner_version.json');
	}

	/**
	 * @param array<string, mixed> $course
	 */
	private function renderCourseLinks(array $course): string {
		$links = $course['links'] ?? [];
		if ($links === []) {
			return '';
		}

		$items = array_map(function (array $link): string {
			return '<li><a href="' . $this->escape((string)$link['url']) . '" target="_blank" rel="noopener noreferrer">'
				. $this->escape((string)$link['label']) . '</a></li>';
		}, $links);

		return '<ul class="course-bar__links">' . implode('', $items) . '</ul>';
	}

	private function renderPage(string $title, string $content, ?string $refreshPath = null): string {
		$refreshConfig = $refreshPath !== null
			? '<script>window.schoolplannerRefreshPath=' . json_encode($refreshPath, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ';</script>'
			: '';
		return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . $this->escape($title) . '</title>'
			. '<link rel="preconnect" href="https://cdnjs.cloudflare.com">'
			. '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">'
			. '<style>'
			. ':root{color-scheme:dark;--page-bg:#0b1220;--page-accent:#38bdf8;--page-accent-strong:#0ea5e9;--page-card:#121a2b;--page-card-2:#182235;--page-ink:#e5eefb;--page-muted:#95a7c2;--page-line:#273550;--page-shadow:0 24px 60px rgba(0,0,0,.35);--page-soft:#0f1729;--page-soft-2:#0d1423;--page-success:#22c55e;}'
			. '*{box-sizing:border-box;}'
			. 'html{min-height:100%;background:radial-gradient(circle at top left,#17233a 0,#0b1220 42%,#060b16 100%) fixed;}'
			. 'body{min-height:100vh;margin:0;font-family:Calibri,Candara,"Segoe UI",Arial,sans-serif;background:transparent;color:var(--page-ink);}'
			. 'a{color:var(--page-accent);text-decoration:none;}a:hover{text-decoration:underline;}'
			. '.site-shell{max-width:1240px;min-height:100vh;margin:0 auto;padding:32px 20px 56px;}'
			. '.hero,.course-hero,.sidebar-card,.card,.course-card{background:linear-gradient(180deg,rgba(24,34,53,.95),rgba(13,20,35,.96));backdrop-filter:blur(10px);border:1px solid rgba(39,53,80,.95);box-shadow:var(--page-shadow);}'
			. '.hero,.course-hero{border-radius:10px;padding:24px 26px;margin-bottom:24px;}'
			. '.hero h1,.course-hero h1{font-family:Calibri,Candara,"Segoe UI",Arial,sans-serif;font-size:clamp(2rem,4vw,3rem);font-weight:700;line-height:1.05;margin:.35rem 0 0;}'
			. '.hero p,.course-hero p,.course-hero .copy p{max-width:66ch;color:var(--page-muted);}'
			. '.search-shell{margin-top:18px;display:flex;flex-direction:column;gap:.7rem;}'
			. '.search-label{font-size:.92rem;color:var(--page-muted);font-weight:700;}'
			. '.search-input{width:100%;padding:.95rem 1rem;border-radius:8px;border:1px solid var(--page-line);background:var(--page-soft);color:var(--page-ink);font:inherit;outline:none;}'
			. '.search-input:focus{border-color:var(--page-accent);box-shadow:0 0 0 3px rgba(56,189,248,.18);}'
			. '.search-results{display:flex;flex-direction:column;gap:.65rem;padding:.9rem 1rem;border:1px solid var(--page-line);border-radius:8px;background:rgba(10,16,29,.9);}'
			. '.search-result{display:flex;justify-content:space-between;gap:1rem;padding:.7rem .1rem;border-bottom:1px solid rgba(39,53,80,.7);}'
			. '.search-result:last-child{border-bottom:0;}'
			. '.search-result a{display:block;flex:1;}'
			. '.search-result strong{display:block;color:var(--page-ink);font-size:1rem;}'
			. '.search-result span{display:block;color:var(--page-muted);margin-top:.18rem;font-size:.92rem;}'
			. '.search-result__type{flex:0 0 auto;align-self:flex-start;padding:.2rem .45rem;border-radius:999px;background:rgba(56,189,248,.12);color:var(--page-accent);font-size:.78rem;font-weight:700;text-transform:uppercase;}'
			. '.search-empty{color:var(--page-muted);font-size:.95rem;}'
			. '.course-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;}'
			. '.course-card{display:flex;flex-direction:column;gap:.75rem;padding:20px;border-radius:10px;transition:transform .18s ease,box-shadow .18s ease, border-color .18s ease;background:linear-gradient(180deg,rgba(24,34,53,.98),rgba(10,16,29,.96));}'
			. '.course-card:hover{transform:translateY(-3px);text-decoration:none;box-shadow:0 24px 60px rgba(0,0,0,.38);border-color:rgba(56,189,248,.45);}'
			. '.course-card h2{margin:0;font-size:1.5rem;}'
			. '.course-card__meta{display:flex;justify-content:space-between;gap:1rem;margin-top:auto;font-size:.92rem;color:var(--page-muted);font-weight:600;}'
			. '.breadcrumb{margin-bottom:18px;color:var(--page-muted);}'
			. '.course-bar{position:sticky;top:10px;z-index:30;margin:0 0 18px;padding:9px 14px;border-radius:10px;background:rgba(12,19,33,.94);backdrop-filter:blur(12px);border:1px solid var(--page-line);box-shadow:var(--page-shadow);}'
			. '.course-bar__inner{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}'
			. '.course-bar__back{color:var(--page-muted);font-size:.88rem;white-space:nowrap;}'
			. '.course-bar__name{font-size:1.1rem;font-weight:700;margin-right:auto;}'
			. '.course-bar__links{list-style:none;display:flex;gap:.4rem;flex-wrap:wrap;margin:0;padding:0;}'
			. '.course-bar__links a{display:inline-flex;align-items:center;padding:.3rem .62rem;border-radius:999px;background:rgba(56,189,248,.1);border:1px solid rgba(56,189,248,.3);font-size:.85rem;font-weight:600;}'
			. '.course-bar__links a:hover{text-decoration:none;border-color:rgba(56,189,248,.65);background:rgba(56,189,248,.2);}'
			. '.course-layout{display:grid;grid-template-columns:minmax(300px,360px) minmax(0,1fr);gap:22px;align-items:start;}'
			// Eigener Scrollbereich: Die Stundenliste laeuft unabhaengig vom Inhalt
			// rechts, sonst muesste man fuer die naechste Stunde erst durch die
			// ganze aktuelle scrollen.
			. '.sidebar-card{border-radius:10px;padding:16px;position:sticky;top:72px;display:flex;flex-direction:column;max-height:calc(100vh - 96px);overflow:hidden;}'
			. '.sidebar-card__header{display:flex;align-items:baseline;justify-content:space-between;gap:.6rem;flex:0 0 auto;}'
			. '.sidebar-card__header h2,.card h2{margin:0;}'
			. '.sidebar-card__header h2{font-size:1.05rem;}'
			. '.sidebar-card__count{color:var(--page-muted);font-size:.85rem;font-weight:600;}'
			. '.sidebar-card__header p{margin:.35rem 0 0;color:var(--page-muted);line-height:1.5;}'
			. '.lesson-list-public{display:flex;flex-direction:column;gap:2px;margin-top:12px;flex:1 1 auto;min-height:0;overflow-y:auto;padding-right:4px;}'
			. '.lesson-list-public::-webkit-scrollbar{width:8px;}'
			. '.lesson-list-public::-webkit-scrollbar-thumb{background:rgba(148,167,194,.28);border-radius:99px;}'
			. '.lesson-list-item{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:10px;padding:7px 9px;border-radius:7px;border:1px solid transparent;background:transparent;transition:background .15s ease,border-color .15s ease;}'
			. '.lesson-list-item:hover{text-decoration:none;background:var(--page-soft);border-color:rgba(56,189,248,.28);}'
			. '.lesson-list-item--active{border-color:rgba(56,189,248,.65);background:rgba(10,25,47,.92);}'
			. '.lesson-list-item--today .lli-date{color:var(--page-accent);font-weight:700;}'
			. '.lli-date{color:var(--page-muted);font-size:.82rem;white-space:nowrap;font-variant-numeric:tabular-nums;}'
			. '.lli-title{font-size:.95rem;font-weight:600;color:var(--page-ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
			. '.lesson-list-item--active .lli-title{color:var(--page-accent);}'
			. '.lli-count{color:var(--page-muted);font-size:.78rem;font-variant-numeric:tabular-nums;}'
			. '.lli-dot{width:7px;height:7px;border-radius:50%;background:var(--page-accent);display:inline-block;}'
			. '.content-stage{display:flex;flex-direction:column;gap:18px;}'
			. '.course-hero--split{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;flex-wrap:wrap;}'
			. '.course-hero__title{flex:1 1 320px;min-width:0;}'
			. '.hero-links{flex:0 1 auto;max-width:100%;min-width:240px;border:1px solid rgba(56,189,248,.25);background:rgba(56,189,248,.06);border-radius:10px;padding:12px 14px;}'
			. '.hero-links h3{margin:0 0 .55rem;font-size:1rem;color:var(--page-muted);text-transform:uppercase;letter-spacing:.03em;}'
			. '.hero-links__list{list-style:none;padding:0;margin:0;display:flex;flex-wrap:wrap;gap:.5rem;}'
			. '.hero-links__list a{display:inline-flex;align-items:center;gap:.35rem;padding:.45rem .7rem;border-radius:999px;background:rgba(56,189,248,.1);border:1px solid rgba(56,189,248,.3);font-weight:600;word-break:break-word;}'
			. '.hero-links__list a:hover{text-decoration:none;border-color:rgba(56,189,248,.6);background:rgba(56,189,248,.18);}'
			. '.lesson-panel{display:none;}'
			. '.lesson-panel--active{display:block;}'
			. '.card{border-radius:10px;padding:22px 24px;margin:0;}'
			. '.card--focus{background:linear-gradient(180deg,rgba(24,34,53,.98),rgba(9,15,27,.98));padding-bottom:28px;}'
			. '.card__header{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.25rem;}'
			. '.card__header h2{font-size:1.8rem;}'
			. '.card__link{display:inline-flex;align-items:center;gap:.4rem;font-weight:700;}'
			. '.meta{color:var(--page-muted);font-size:.95rem;margin:0 0 .5rem;}'
			. '.pill{display:inline-flex;align-items:center;padding:.2rem .55rem;border-radius:999px;background:rgba(34,197,94,.14);color:#8ef0b2;font-size:.78rem;font-weight:700;text-transform:uppercase;}'
			. '.status-badge{display:inline-flex;align-items:center;margin:0 0 .55rem;padding:.22rem .58rem;border-radius:999px;background:rgba(56,189,248,.12);color:#7dd3fc;font-size:.78rem;font-weight:700;text-transform:uppercase;}'
			. '.published-item{margin:0;padding:1rem;border:1px solid rgba(39,53,80,.92);border-radius:8px;background:rgba(11,18,32,.62);box-shadow:inset 0 0 0 1px rgba(255,255,255,.015);}'
			. '.published-item + .published-item{margin-top:1rem;}'
			. '.published-item--current{border-color:rgba(56,189,248,.82);background:linear-gradient(180deg,rgba(12,26,47,.96),rgba(8,16,31,.98));box-shadow:0 0 0 2px rgba(56,189,248,.14),0 18px 36px rgba(2,8,23,.26);}'
			. '.published-item h3{margin:.2rem 0 .6rem;font-size:1.1rem;}'
			. '.published-item__body{border:1px solid rgba(39,53,80,.72);border-radius:7px;padding:1rem;background:rgba(7,13,24,.42);}'
			. '.attachment-list{display:flex;flex-wrap:wrap;gap:.65rem;list-style:none;padding:0;margin:.85rem 0 0;}'
			. '.attachment-list a{display:inline-flex;align-items:center;padding:.5rem .8rem;border-radius:999px;background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.22);font-weight:600;}'
			. '.copy p,.copy li{line-height:1.7;}'
			. '.copy h1,.copy h2,.copy h3,.copy h4{font-family:Calibri,Candara,"Segoe UI",Arial,sans-serif;line-height:1.18;margin:1.15em 0 .45em;font-weight:700;}'
			. '.copy ul,.copy ol{padding-left:1.3rem;}'
			. '.copy blockquote{margin:1rem 0;padding:0 0 0 1rem;border-left:4px solid rgba(56,189,248,.45);color:var(--page-muted);}'
			. '.copy table{width:100%;border-collapse:collapse;margin:1rem 0;font-size:.96rem;background:rgba(10,16,29,.5);border:1px solid rgba(39,53,80,.9);border-radius:8px;overflow:hidden;}'
			. '.copy table th,.copy table td{padding:.6rem .8rem;border-bottom:1px solid rgba(39,53,80,.8);text-align:left;vertical-align:top;}'
			. '.copy table th{background:rgba(56,189,248,.12);color:#cfe9fb;font-weight:700;border-bottom:2px solid rgba(56,189,248,.35);}'
			. '.copy table tr:last-child td{border-bottom:0;}'
			. '.copy table tbody tr:nth-child(even){background:rgba(56,189,248,.04);}'
			. '.copy .table-scroll{overflow-x:auto;}'
			. '.copy pre{position:relative;background:#0a1020;color:#dbeafe;padding:1rem 1.1rem;border-radius:8px;overflow:auto;border:1px solid #25324c;box-shadow:none;}'
			. '.copy code{background:#17233a;padding:.12rem .36rem;border-radius:4px;font-size:.95em;}'
			. '.copy pre code{background:transparent;padding:0;color:inherit;}'
			. '.codeblock{position:relative;}'
			. '.copy-button{position:absolute;top:.7rem;right:.7rem;border:1px solid #2e415f;background:#10192b;color:#dbeafe;border-radius:6px;padding:.3rem .55rem;font:600 .8rem Calibri,Candara,"Segoe UI",Arial,sans-serif;cursor:pointer;}'
			. '.copy-button:hover{background:#16233a;}'
			. '@media (max-width:960px){.site-shell{padding:22px 16px 40px;}.course-grid{grid-template-columns:1fr;}.course-layout{grid-template-columns:1fr;}.sidebar-card{position:static;max-height:none;overflow:visible;}.lesson-list-public{overflow:visible;max-height:none;}.course-bar{top:0;}.course-bar__name{font-size:1rem;}.course-hero--split{flex-direction:column;}.hero-links{min-width:0;width:100%;}.card__header{flex-direction:column;}.course-card__meta{flex-direction:column;align-items:flex-start;}}'
			. '</style></head><body><div class="site-shell">' . $content . '</div>'
			. $refreshConfig
			. '<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>'
			. "<script>document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('pre code').forEach(function(block){if(window.hljs){window.hljs.highlightElement(block);}var pre=block.parentElement;if(pre&&pre.parentElement&&!pre.parentElement.classList.contains('codeblock')){var wrap=document.createElement('div');wrap.className='codeblock';pre.parentElement.insertBefore(wrap,pre);wrap.appendChild(pre);var btn=document.createElement('button');btn.type='button';btn.className='copy-button';btn.textContent='Copy';btn.addEventListener('click',function(){navigator.clipboard.writeText(block.innerText).then(function(){btn.textContent='Copied';setTimeout(function(){btn.textContent='Copy';},1200);});});wrap.appendChild(btn);}});var links=document.querySelectorAll('.lesson-list-item[data-lesson-panel]');var panels=document.querySelectorAll('.lesson-panel');var SCROLL_FLAG='sp-scroll-to-current';function scrollToCurrent(){var current=document.querySelector('.lesson-panel--active .published-item--current');if(current){window.setTimeout(function(){current.scrollIntoView({behavior:'smooth',block:'center'});},120);}}function scrollIfJustPublished(){var want=null;try{want=sessionStorage.getItem(SCROLL_FLAG);sessionStorage.removeItem(SCROLL_FLAG);}catch(e){}if(want){scrollToCurrent();}}function activate(id){if(!id)return;links.forEach(function(link){link.classList.toggle('lesson-list-item--active',link.getAttribute('data-lesson-panel')===id);});panels.forEach(function(panel){panel.classList.toggle('lesson-panel--active',panel.id===id);});}links.forEach(function(link){link.addEventListener('click',function(event){event.preventDefault();var id=link.getAttribute('data-lesson-panel');activate(id);if(history.replaceState){history.replaceState(null,'','#'+id);}else{location.hash=id;}});});var initial=location.hash?location.hash.slice(1):null;if(initial&&document.getElementById(initial)){activate(initial);}else{var active=document.querySelector('.lesson-panel--active');if(active){activate(active.id);}}scrollIfJustPublished();var searchInput=document.getElementById('global-search');var resultBox=document.getElementById('search-results');if(searchInput&&resultBox&&Array.isArray(window.schoolplannerSearchEntries)){var entries=window.schoolplannerSearchEntries;function renderResults(matches){if(matches.length===0){resultBox.innerHTML='<div class=\"search-empty\">Keine Treffer gefunden.</div>';resultBox.hidden=false;return;}resultBox.innerHTML=matches.slice(0,12).map(function(entry){return '<div class=\"search-result\"><a href=\"'+entry.url+'\"><strong>'+entry.title+'</strong><span>'+entry.subtitle+'</span></a><span class=\"search-result__type\">'+entry.type+'</span></div>';}).join('');resultBox.hidden=false;}searchInput.addEventListener('input',function(){var value=(searchInput.value||'').trim().toLowerCase();if(value.length<3){resultBox.hidden=true;resultBox.innerHTML='';return;}var matches=entries.filter(function(entry){return typeof entry.search==='string'&&entry.search.indexOf(value)!==-1;});renderResults(matches);});}if(window.schoolplannerRefreshPath){var currentVersion=null;var poll=function(){fetch(window.schoolplannerRefreshPath+'?t='+Date.now(),{cache:'no-store'}).then(function(response){return response.ok?response.json():null;}).then(function(payload){if(!payload||!payload.updatedAt){return;}if(currentVersion===null){currentVersion=payload.updatedAt;return;}if(currentVersion!==payload.updatedAt){try{sessionStorage.setItem(SCROLL_FLAG,'1');}catch(e){}window.location.reload();}}).catch(function(){});};poll();window.setInterval(poll,4000);}});</script>"
			. '</body></html>';
	}

	/**
	 * @param array<string, mixed> $course
	 * @param array<string, mixed> $lesson
	 */
	private function renderLessonDetail(array $course, array $lesson, string $assetBasePath, bool $showLessonLink, ?string $panelId = null, bool $isActive = true): string {
		$publishedItems = array_values(array_filter(
			$lesson['items'],
			static fn (array $item): bool => (bool)$item['published']
		));

		$itemMarkup = array_map(function (array $item) use ($assetBasePath): string {
			$attachmentLinks = array_map(function (array $attachment) use ($item, $assetBasePath): string {
				$url = $assetBasePath . '/item-' . $item['id'] . '/' . rawurlencode($attachment['fileName']);
				return '<li><a href="' . $this->escape($url) . '" download>' . $this->escape($attachment['fileName']) . '</a></li>';
			}, $item['attachments'] ?? []);

			return '<article class="published-item' . ((bool)($item['isCurrent'] ?? false) ? ' published-item--current' : '') . '">'
				. ((bool)($item['isCurrent'] ?? false) ? '<span class="status-badge">Hier sind wir</span>' : '')
				. '<h3>' . $this->escape($item['title']) . '</h3>'
				. '<div class="published-item__body"><div class="copy">' . $this->renderMarkdown($this->resolveLocalRefs((string)$item['description'], $item, $assetBasePath)) . '</div>'
				. ($attachmentLinks === [] ? '' : '<ul class="attachment-list">' . implode('', $attachmentLinks) . '</ul>')
				. '</div>'
				. '</article>';
		}, $publishedItems);

		$lessonUrl = 'lessons/' . $this->lessonSlug($lesson) . '.html';
		$linkMarkup = $showLessonLink
			? '<a class="card__link" href="' . $this->escape($lessonUrl) . '">Zur Detailansicht</a>'
			: '';
		$panelAttributes = $panelId !== null
			? ' id="' . $this->escape($panelId) . '" class="lesson-panel' . ($isActive ? ' lesson-panel--active' : '') . '"'
			: '';

		return '<article' . $panelAttributes . '><div class="card card--focus">'
			. '<div class="card__header"><div><p class="meta">' . $this->escape($this->formatLessonDate($lesson['lessonDate'])) . '</p><h2>' . $this->escape($lesson['title']) . '</h2></div>' . $linkMarkup . '</div>'
			. ($this->truncateText((string)($lesson['goal'] ?? ''), 240) !== '' ? '<p><strong>Ziel:</strong> ' . $this->escape($this->truncateText((string)$lesson['goal'], 240)) . '</p>' : '')
			. '<div class="copy">' . $this->renderMarkdown((string)($lesson['description'] ?? '')) . '</div>'
			. ($itemMarkup === [] ? '<p>Für diese Stunde sind aktuell keine Elemente veröffentlicht.</p>' : implode('', $itemMarkup))
			. '</div></article>';
	}

	/**
	 * @param array<int, array<string, mixed>> $lessons
	 * @return array<string, mixed>|null
	 */
	private function selectCurrentLesson(array $lessons): ?array {
		if ($lessons === []) {
			return null;
		}

		$today = new DateTimeImmutable('today');
		foreach ($lessons as $lesson) {
			$lessonDate = new DateTimeImmutable((string)$lesson['lessonDate']);
			if ($lessonDate >= $today) {
				return $lesson;
			}
		}

		return $lessons[count($lessons) - 1];
	}

	private function formatLessonDate(string $lessonDate): string {
		return (new DateTimeImmutable($lessonDate))->format('d.m.Y');
	}

	private function excerptMarkdown(string $value): string {
		$text = trim(strip_tags((string)$this->renderMarkdown($value)));
		if ($text === '') {
			return '';
		}
		return $this->truncateText($text, 160);
	}

	private function truncateText(string $value, int $maxLength): string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}
		if (mb_strlen($value) <= $maxLength) {
			return $value;
		}
		return rtrim(mb_substr($value, 0, $maxLength - 1)) . '...';
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

	/**
	 * Rewrites relative links/images (e.g. "grafik.png" from the course folder)
	 * to the published asset path of the matching attachment, so images show up
	 * inline and files stay downloadable.
	 *
	 * @param array<string, mixed> $item
	 */
	private function resolveLocalRefs(string $markdown, array $item, string $assetBasePath): string {
		$attachments = $item['attachments'] ?? [];
		if ($attachments === []) {
			return $markdown;
		}

		$map = [];
		foreach ($attachments as $attachment) {
			$name = (string)$attachment['fileName'];
			$map[mb_strtolower($name)] = $assetBasePath . '/item-' . (int)$item['id'] . '/' . rawurlencode($name);
		}

		return preg_replace_callback('/(!?\[[^\]]*\]\()\s*([^)\s]+)/', static function (array $match) use ($map): string {
			$ref = trim($match[2], "<>\"'");
			if ($ref === '' || str_starts_with($ref, '#') || str_starts_with($ref, '/') || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $ref)) {
				return $match[0];
			}
			$key = mb_strtolower(rawurldecode(basename($ref)));
			return isset($map[$key]) ? $match[1] . $map[$key] : $match[0];
		}, $markdown) ?? $markdown;
	}

	private function renderMarkdown(string $value): string {
		if ($this->markdownConverter === null) {
			$environment = new Environment([
				'html_input' => 'strip',
				'allow_unsafe_links' => false,
				'external_link' => [
					// Treat every absolute link as external so it opens in a new tab.
					'internal_hosts' => [],
					'open_in_new_window' => true,
					'noopener' => 'external',
					'noreferrer' => 'external',
				],
			]);
			$environment->addExtension(new CommonMarkCoreExtension());
			$environment->addExtension(new ExternalLinkExtension());
			$environment->addExtension(new TableExtension());
			$this->markdownConverter = new MarkdownConverter($environment);
		}

		return (string)$this->markdownConverter->convert($value);
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
		$directory = rtrim($directory, '/');
		if ($directory === '' || $directory === '.') {
			return;
		}

		// Preserve whether the path is absolute (from server root) or relative
		// (from the SFTP login directory), so a configured "html" target works.
		$absolute = str_starts_with($directory, '/');
		$segments = array_values(array_filter(
			explode('/', ltrim($directory, '/')),
			static fn (string $segment): bool => $segment !== ''
		));

		$path = '';
		foreach ($segments as $segment) {
			$path = $path === '' ? ($absolute ? '/' . $segment : $segment) : $path . '/' . $segment;
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
