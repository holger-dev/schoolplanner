<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Server;

class Version000008Date20260323090000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
		$connection = Server::get(\OCP\IDBConnection::class);
		if (!$connection->tableExists('schoolplanner_attachments') || $connection->tableExists('sp_attachments')) {
			return;
		}

		$prefix = method_exists($connection, 'getPrefix') ? $connection->getPrefix() : 'oc_';
		$oldTable = $prefix . 'schoolplanner_attachments';
		$newTable = $prefix . 'sp_attachments';

		$platform = method_exists($connection, 'getDatabasePlatform') ? $connection->getDatabasePlatform()->getName() : '';
		if ($platform === 'sqlite') {
			$connection->executeStatement('ALTER TABLE "' . $oldTable . '" RENAME TO "' . $newTable . '"');
			return;
		}

		$connection->executeStatement('RENAME TABLE `' . $oldTable . '` TO `' . $newTable . '`');
	}
}
