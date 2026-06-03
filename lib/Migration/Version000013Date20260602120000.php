<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000013Date20260602120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('schoolplanner_courses')) {
			$table = $schema->getTable('schoolplanner_courses');
			if (!$table->hasColumn('participation_scale')) {
				$table->addColumn('participation_scale', 'string', [
					'length' => 16,
					'notnull' => false,
					'default' => '',
				]);
			}
		}

		return $schema;
	}
}
