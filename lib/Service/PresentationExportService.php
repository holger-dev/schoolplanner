<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Service;

/**
 * Exports a course as a ZIP of OpenDocument presentations (.odp) – one per
 * lesson, in the dark look of the published website – plus the attachment files
 * each lesson needs. No server/SFTP required.
 */
class PresentationExportService {
	private const MIME = 'application/vnd.oasis.opendocument.presentation';
	private const BG = '#0b1220';
	private const ACCENT = '#38bdf8';
	private const ACCENT_DARK = '#0ea5e9';
	private const CARD = '#162033';
	private const INK = '#e5eefb';
	private const MUTED = '#95a7c2';

	public function __construct(
		private PlannerService $plannerService,
		private AttachmentService $attachmentService,
	) {
	}

	/**
	 * Export ALL courses of the user in one ZIP. One folder per course; inside,
	 * one .odp per lesson plus every attachment, named with its lesson.
	 *
	 * @return array{content: string, fileName: string}
	 */
	public function exportAll(string $userId): array {
		if (!class_exists(\ZipArchive::class)) {
			throw new \RuntimeException('ZipArchive ist auf dem Server nicht verfügbar.');
		}

		$courses = $this->plannerService->getBootstrap($userId)['courses'];

		$tempFile = tempnam(sys_get_temp_dir(), 'schoolplanner-odp-');
		if ($tempFile === false) {
			throw new \RuntimeException('Temporäre Datei konnte nicht angelegt werden.');
		}

		$zip = new \ZipArchive();
		if ($zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('ZIP konnte nicht erstellt werden.');
		}

		$usedCourseFolders = [];
		foreach ($courses as $course) {
			$this->addCourse($zip, $course, $usedCourseFolders);
		}

		$zip->close();
		$content = (string)file_get_contents($tempFile);
		@unlink($tempFile);

		return [
			'content' => $content,
			'fileName' => 'Schoolplanner_' . date('Y-m-d_His') . '.zip',
		];
	}

	/**
	 * @param array<string, mixed> $course
	 * @param array<string, bool> $usedCourseFolders
	 */
	private function addCourse(\ZipArchive $zip, array $course, array &$usedCourseFolders): void {
		$courseFolder = $this->unique($this->slug((string)$course['name']) ?: 'kurs', $usedCourseFolders);
		$zip->addEmptyDir($courseFolder);

		$usedNames = [];
		foreach ((array)($course['lessons'] ?? []) as $index => $lesson) {
			$lessonSlug = $this->slug((string)$lesson['lessonDate'] . '-' . (string)$lesson['title']) ?: ('stunde-' . ($index + 1));

			$odpName = $this->unique($lessonSlug . '.odp', $usedNames);
			$zip->addFromString($courseFolder . '/' . $odpName, $this->buildOdp($course, $lesson));

			foreach (($lesson['items'] ?? []) as $item) {
				foreach (($item['attachments'] ?? []) as $attachment) {
					$fileName = $this->unique($lessonSlug . '__' . $this->sanitizeFileName((string)$attachment['fileName']), $usedNames);
					try {
						$zip->addFromString($courseFolder . '/' . $fileName, $this->attachmentService->readAttachmentContent($attachment));
					} catch (\Throwable $exception) {
						// Skip unreadable attachments rather than failing the whole export.
					}
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $course
	 * @param array<string, mixed> $lesson
	 */
	private function buildOdp(array $course, array $lesson): string {
		$tempFile = tempnam(sys_get_temp_dir(), 'schoolplanner-odpinner-');
		if ($tempFile === false) {
			throw new \RuntimeException('Temporäre ODP-Datei konnte nicht angelegt werden.');
		}

		$zip = new \ZipArchive();
		$zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

		// The mimetype entry must be first and stored uncompressed.
		$zip->addFromString('mimetype', self::MIME);
		if (method_exists($zip, 'setCompressionName')) {
			$zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
		}
		$zip->addEmptyDir('META-INF');
		$zip->addFromString('META-INF/manifest.xml', $this->manifestXml());
		$zip->addFromString('styles.xml', $this->stylesXml());
		$zip->addFromString('meta.xml', $this->metaXml($course, $lesson));
		$zip->addFromString('content.xml', $this->contentXml($course, $lesson));
		$zip->close();

		$content = (string)file_get_contents($tempFile);
		@unlink($tempFile);
		return $content;
	}

	private function manifestXml(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">'
			. '<manifest:file-entry manifest:full-path="/" manifest:media-type="' . self::MIME . '"/>'
			. '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
			. '<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
			. '<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>'
			. '</manifest:manifest>';
	}

	/**
	 * @param array<string, mixed> $course
	 * @param array<string, mixed> $lesson
	 */
	private function metaXml(array $course, array $lesson): string {
		$title = $this->esc((string)$course['name'] . ' – ' . (string)$lesson['title']);
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:dc="http://purl.org/dc/elements/1.1/" office:version="1.2">'
			. '<office:meta><meta:generator>School Planner</meta:generator><dc:title>' . $title . '</dc:title></office:meta>'
			. '</office:document-meta>';
	}

	private function stylesXml(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-styles xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" xmlns:presentation="urn:oasis:names:tc:opendocument:xmlns:presentation:1.0" office:version="1.2">'
			. '<office:styles/>'
			. '<office:automatic-styles>'
			. '<style:page-layout style:name="PL"><style:page-layout-properties fo:margin="0cm" fo:page-width="33.867cm" fo:page-height="19.05cm" style:print-orientation="landscape"/></style:page-layout>'
			. '<style:style style:name="bg" style:family="drawing-page"><style:drawing-page-properties draw:fill="solid" draw:fill-color="' . self::BG . '"/></style:style>'
			. '</office:automatic-styles>'
			. '<office:master-styles>'
			. '<style:master-page style:name="Default" style:page-layout-name="PL" draw:style-name="bg"/>'
			. '</office:master-styles>'
			. '</office:document-styles>';
	}

	/**
	 * @param array<string, mixed> $course
	 * @param array<string, mixed> $lesson
	 */
	private function contentXml(array $course, array $lesson): string {
		$items = array_values((array)($lesson['items'] ?? []));
		$total = count($items) + 1;

		$pages = $this->titleSlide($course, $lesson);
		$no = 2;
		foreach ($items as $item) {
			$pages .= $this->itemSlide($course, $lesson, $item, $no, $total);
			$no++;
		}

		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:presentation="urn:oasis:names:tc:opendocument:xmlns:presentation:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" office:version="1.2">'
			. '<office:automatic-styles>'
			. '<style:style style:name="gr" style:family="graphic"><style:graphic-properties draw:fill="none" draw:stroke="none" draw:textarea-vertical-align="top"/></style:style>'
			. '<style:style style:name="bar" style:family="graphic"><style:graphic-properties draw:fill="solid" draw:fill-color="' . self::ACCENT . '" draw:stroke="none"/></style:style>'
			. '<style:style style:name="line" style:family="graphic"><style:graphic-properties draw:fill="solid" draw:fill-color="' . self::ACCENT_DARK . '" draw:stroke="none"/></style:style>'
			. '<style:style style:name="card" style:family="graphic"><style:graphic-properties draw:fill="solid" draw:fill-color="' . self::CARD . '" draw:stroke="none"/></style:style>'
			. '<style:style style:name="dpbg" style:family="drawing-page"><style:drawing-page-properties draw:fill="solid" draw:fill-color="' . self::BG . '"/></style:style>'
			. $this->paraStyle('pTitle', self::ACCENT, '40pt', true)
			. $this->paraStyle('pHead', self::INK, '30pt', true)
			. $this->paraStyle('pBody', self::INK, '18pt', false)
			. $this->paraStyle('pMeta', self::MUTED, '15pt', false)
			. $this->paraStyle('pLabel', self::ACCENT, '14pt', true)
			. $this->paraStyle('pFoot', self::MUTED, '11pt', false)
			. '</office:automatic-styles>'
			. '<office:body><office:presentation>'
			. $pages
			. '</office:presentation></office:body></office:document-content>';
	}

	private function paraStyle(string $name, string $color, string $size, bool $bold): string {
		return '<style:style style:name="' . $name . '" style:family="paragraph">'
			. '<style:paragraph-properties fo:margin-bottom="0.15cm"/>'
			. '<style:text-properties fo:color="' . $color . '" fo:font-size="' . $size . '"' . ($bold ? ' fo:font-weight="bold"' : '') . '/>'
			. '</style:style>';
	}

	/**
	 * @param array<string, mixed> $course
	 * @param array<string, mixed> $lesson
	 */
	private function titleSlide(array $course, array $lesson): string {
		$metaLine = trim($this->formatDate((string)$lesson['lessonDate']) . '   ·   ' . (int)$lesson['lessonSlot'] . '. Stunde');
		$goal = trim((string)($lesson['goal'] ?? ''));

		$body = $this->rect('bar', '0cm', '0cm', '0.55cm', '19.05cm');
		$body .= $this->frame('gr', '2.2cm', '2.6cm', '29cm', '1.4cm', [['pLabel', mb_strtoupper((string)$course['name'])]]);
		$body .= $this->frame('gr', '2.2cm', '4.3cm', '30cm', '6cm', [['pTitle', (string)$lesson['title']]]);
		$body .= $this->rect('card', '2.2cm', '11.3cm', '29.4cm', '5.4cm', '0.3cm');
		$cardLines = [['pMeta', $metaLine]];
		if ($goal !== '') {
			$cardLines[] = ['pBody', 'Ziel: ' . $goal];
		}
		$body .= $this->frame('gr', '2.9cm', '11.9cm', '28cm', '4.4cm', $cardLines);

		return $this->page('s1', $body);
	}

	/**
	 * @param array<string, mixed> $course
	 * @param array<string, mixed> $lesson
	 * @param array<string, mixed> $item
	 */
	private function itemSlide(array $course, array $lesson, array $item, int $no, int $total): string {
		$lines = [];
		foreach ($this->splitLines($this->markdownToPlain((string)($item['description'] ?? ''))) as $line) {
			$lines[] = ['pBody', $line];
		}
		$attachments = array_map(static fn (array $a): string => (string)$a['fileName'], $item['attachments'] ?? []);
		if ($attachments !== []) {
			$lines[] = ['pMeta', 'Dateien: ' . implode(', ', $attachments)];
		}
		if ($lines === []) {
			$lines[] = ['pBody', ' '];
		}

		$footer = (string)$course['name'] . '   ·   ' . (string)$lesson['title'] . '   ·   Folie ' . $no . '/' . $total;

		$body = $this->rect('bar', '0cm', '0cm', '0.55cm', '19.05cm');
		$body .= $this->frame('gr', '2.2cm', '1.5cm', '30cm', '2.4cm', [['pHead', (string)$item['title']]]);
		$body .= $this->rect('line', '2.25cm', '3.95cm', '6cm', '0.1cm');
		$body .= $this->frame('gr', '2.2cm', '4.7cm', '30cm', '12cm', $lines);
		$body .= $this->frame('gr', '2.2cm', '17.7cm', '30cm', '0.9cm', [['pFoot', $footer]]);

		return $this->page('s' . $no, $body);
	}

	private function page(string $name, string $content): string {
		return '<draw:page draw:name="' . $this->esc($name) . '" draw:style-name="dpbg" draw:master-page-name="Default">' . $content . '</draw:page>';
	}

	private function rect(string $style, string $x, string $y, string $w, string $h, ?string $corner = null): string {
		$cornerAttr = $corner !== null ? ' draw:corner-radius="' . $corner . '"' : '';
		return '<draw:rect draw:style-name="' . $style . '" draw:layer="layout" svg:x="' . $x . '" svg:y="' . $y . '" svg:width="' . $w . '" svg:height="' . $h . '"' . $cornerAttr . '><text:p/></draw:rect>';
	}

	/**
	 * @param array<int, array{0: string, 1: string}> $paragraphs
	 */
	private function frame(string $graphicStyle, string $x, string $y, string $w, string $h, array $paragraphs): string {
		$inner = '';
		foreach ($paragraphs as $p) {
			$inner .= '<text:p text:style-name="' . $p[0] . '">' . $this->esc($p[1]) . '</text:p>';
		}
		return '<draw:frame draw:style-name="' . $graphicStyle . '" draw:layer="layout" svg:x="' . $x . '" svg:y="' . $y . '" svg:width="' . $w . '" svg:height="' . $h . '">'
			. '<draw:text-box>' . $inner . '</draw:text-box></draw:frame>';
	}

	private function markdownToPlain(string $text): string {
		$text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $text) ?? $text; // images
		$text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1 ($2)', $text) ?? $text; // links
		$text = preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $text) ?? $text; // headings
		$text = preg_replace('/[`*_>]+/', '', $text) ?? $text; // emphasis/quote/code marks
		return trim($text);
	}

	/**
	 * @return array<int, string>
	 */
	private function splitLines(string $text): array {
		$lines = preg_split('/\R/', $text) ?: [];
		$result = [];
		foreach ($lines as $line) {
			$line = rtrim($line);
			if ($line !== '') {
				$result[] = $line;
			}
		}
		return $result;
	}

	private function formatDate(string $date): string {
		try {
			return (new \DateTimeImmutable($date))->format('d.m.Y');
		} catch (\Throwable $exception) {
			return $date;
		}
	}

	private function slug(string $value): string {
		$value = strtolower(trim($value));
		// Replace umlauts for nicer file names.
		$value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
		$value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
		return trim($value, '-');
	}

	private function sanitizeFileName(string $name): string {
		$name = preg_replace('/[\\\\\/:*?"<>|]+/', '_', $name) ?? $name;
		$name = trim($name);
		return $name !== '' ? $name : 'datei';
	}

	/**
	 * @param array<string, bool> $used
	 */
	private function unique(string $name, array &$used): string {
		$candidate = $name;
		$i = 2;
		while (isset($used[mb_strtolower($candidate)])) {
			if (str_contains($name, '.')) {
				$dot = strrpos($name, '.');
				$candidate = substr($name, 0, $dot) . '-' . $i . substr($name, $dot);
			} else {
				$candidate = $name . '-' . $i;
			}
			$i++;
		}
		$used[mb_strtolower($candidate)] = true;
		return $candidate;
	}

	private function esc(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
	}
}
