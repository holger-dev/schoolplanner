<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000010Date20260602100100 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('schoolplanner_courses')) {
			$table = $schema->getTable('schoolplanner_courses');
			if (!$table->hasColumn('deck_board_id')) {
				$table->addColumn('deck_board_id', 'integer', ['notnull' => false, 'default' => null]);
			}
			if (!$table->hasColumn('deck_stack_id')) {
				$table->addColumn('deck_stack_id', 'integer', ['notnull' => false, 'default' => null]);
			}
		}

		return $schema;
	}
}
