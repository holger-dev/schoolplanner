<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Service;

use DateTimeImmutable;
use OCA\SchoolPlanner\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;

class AttachmentService {
	public function __construct(
		private IDBConnection $connection,
		private IAppDataFactory $appDataFactory,
		private PlannerService $plannerService,
	) {
	}

	/**
	 * @param array<string, mixed> $uploadedFile
	 * @return array<string, mixed>
	 */
	public function uploadAttachment(string $userId, int $itemId, array $uploadedFile): array {
		$item = $this->plannerService->getLessonItem($itemId, $userId);
		if (!isset($uploadedFile['tmp_name'], $uploadedFile['name']) || !is_string($uploadedFile['tmp_name']) || !is_string($uploadedFile['name'])) {
			throw new \RuntimeException('Keine Datei hochgeladen.');
		}

		$sourcePath = $uploadedFile['tmp_name'];
		if (!is_file($sourcePath)) {
			throw new \RuntimeException('Upload-Datei nicht gefunden.');
		}

		$originalName = $this->sanitizeOriginalName($uploadedFile['name']);
		$storedName = uniqid('attachment_', true) . '-' . $originalName;
		$size = (int)($uploadedFile['size'] ?? filesize($sourcePath) ?: 0);
		$mimeType = is_string($uploadedFile['type'] ?? null) ? $uploadedFile['type'] : 'application/octet-stream';

		$folder = $this->getItemFolder($itemId, true);
		$stream = fopen($sourcePath, 'rb');
		if ($stream === false) {
			throw new \RuntimeException('Upload-Datei konnte nicht gelesen werden.');
		}
		$folder->newFile($storedName, $stream);

		$now = new DateTimeImmutable();
		$query = $this->connection->getQueryBuilder();
		$query->insert('schoolplanner_attachments')
			->values([
				'item_id' => $query->createNamedParameter($item['id'], IQueryBuilder::PARAM_INT),
				'file_name' => $query->createNamedParameter($originalName),
				'stored_name' => $query->createNamedParameter($storedName),
				'mime_type' => $query->createNamedParameter($mimeType),
				'size' => $query->createNamedParameter($size),
				'created_at' => $query->createNamedParameter($now->format('Y-m-d H:i:s')),
			])
			->executeStatement();

		$attachmentId = (int)$this->connection->lastInsertId('*PREFIX*schoolplanner_attachments');
		foreach ($this->getAttachmentsForItem($itemId) as $attachment) {
			if ((int)$attachment['id'] === $attachmentId) {
				return $attachment;
			}
		}

		throw new \RuntimeException('Attachment konnte nicht gelesen werden.');
	}

	/**
	 * @param array<int> $itemIds
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	public function getAttachmentsForItemIds(array $itemIds): array {
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
			$attachments[(int)$row['item_id']][] = $this->mapAttachment($row);
		}
		$result->closeCursor();
		return $attachments;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getAttachmentsForItem(int $itemId): array {
		return $this->getAttachmentsForItemIds([$itemId])[$itemId] ?? [];
	}

	/**
	 * @param array<string, mixed> $attachment
	 */
	public function readAttachmentContent(array $attachment): string {
		$folder = $this->getItemFolder((int)$attachment['itemId'], false);
		$file = $folder->getFile((string)$attachment['storedName']);
		return $file->getContent();
	}

	public function deleteAttachmentsForItem(string $userId, int $itemId): void {
		$this->plannerService->getLessonItem($itemId, $userId);

		$attachments = $this->getAttachmentsForItem($itemId);
		try {
			$folder = $this->getItemFolder($itemId, false);
			foreach ($attachments as $attachment) {
				try {
					$folder->getFile((string)$attachment['storedName'])->delete();
				} catch (NotFoundException $exception) {
				}
			}
			$folder->delete();
		} catch (NotFoundException $exception) {
		}

		$query = $this->connection->getQueryBuilder();
		$query->delete('schoolplanner_attachments')
			->where($query->expr()->eq('item_id', $query->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function getItemFolder(int $itemId, bool $createIfMissing) {
		$appData = $this->appDataFactory->get(Application::APP_ID);
		$baseFolder = $this->getOrCreateFolder($appData, 'attachments', $createIfMissing);
		return $this->getOrCreateFolder($baseFolder, 'item-' . $itemId, $createIfMissing);
	}

	private function getOrCreateFolder($root, string $name, bool $createIfMissing) {
		try {
			return $root->getFolder($name);
		} catch (NotFoundException $exception) {
			if (!$createIfMissing) {
				throw $exception;
			}
			return $root->newFolder($name);
		}
	}

	private function sanitizeOriginalName(string $name): string {
		$name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? 'datei';
		return trim($name, '-') ?: 'datei';
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function mapAttachment(array $row): array {
		return [
			'id' => (int)$row['id'],
			'itemId' => (int)$row['item_id'],
			'fileName' => (string)$row['file_name'],
			'storedName' => (string)$row['stored_name'],
			'mimeType' => (string)($row['mime_type'] ?? ''),
			'size' => (int)$row['size'],
		];
	}
}
