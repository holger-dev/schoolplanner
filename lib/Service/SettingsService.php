<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Service;

use OCP\IConfig;

class SettingsService {
	private const KEYS = [
		'sftp_host',
		'sftp_username',
		'sftp_password',
		'public_base_url',
	];

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * @return array<string, string>
	 */
	public function getSettings(string $userId): array {
		$settings = [];
		foreach (self::KEYS as $key) {
			$settings[$this->normalizeKey($key)] = $this->config->getUserValue($userId, 'schoolplanner', $key, '');
		}
		return $settings;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, string>
	 */
	public function saveSettings(string $userId, array $payload): array {
		foreach (self::KEYS as $key) {
			$normalized = $this->normalizeKey($key);
			if (array_key_exists($normalized, $payload)) {
				$this->config->setUserValue($userId, 'schoolplanner', $key, trim((string)$payload[$normalized]));
			}
		}
		return $this->getSettings($userId);
	}

	private function normalizeKey(string $key): string {
		return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
	}
}
