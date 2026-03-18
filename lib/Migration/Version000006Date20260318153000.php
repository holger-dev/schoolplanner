<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000006Date20260318153000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('schoolplanner_items')) {
			$table = $schema->getTable('schoolplanner_items');
			if (!$table->hasColumn('teacher_note')) {
				$table->addColumn('teacher_note', 'string', [
					'length' => 255,
					'notnull' => false,
					'default' => '',
				]);
			}
		}

		return $schema;
	}
}
