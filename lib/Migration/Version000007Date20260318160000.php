<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000007Date20260318160000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('schoolplanner_lessons')) {
			$table = $schema->getTable('schoolplanner_lessons');
			if (!$table->hasColumn('lesson_slot')) {
				$table->addColumn('lesson_slot', 'integer', [
					'notnull' => true,
					'default' => 1,
				]);
			}
		}

		return $schema;
	}
}
