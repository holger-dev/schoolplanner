<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000003Date20260318120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('schoolplanner_lessons')) {
			$table = $schema->getTable('schoolplanner_lessons');
			if (!$table->hasColumn('goal')) {
				$table->addColumn('goal', 'string', [
					'length' => 255,
					'notnull' => false,
					'default' => '',
				]);
			}
		}

		return $schema;
	}
}
