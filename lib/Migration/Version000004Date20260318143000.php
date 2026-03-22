<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000004Date20260318143000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('schoolplanner_items')) {
			$table = $schema->getTable('schoolplanner_items');
			if (!$table->hasColumn('is_current')) {
				$table->addColumn('is_current', 'integer', [
					'unsigned' => true,
					'notnull' => true,
					'default' => 0,
				]);
			}
		}

		return $schema;
	}
}
