<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000008Date20260323090000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('schoolplanner_attachments') && !$schema->hasTable('sp_attachments')) {
			$schema->renameTable('schoolplanner_attachments', 'sp_attachments');
		}

		return $schema;
	}
}
