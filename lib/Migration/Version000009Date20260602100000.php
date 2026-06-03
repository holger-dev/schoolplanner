<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000009Date20260602100000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('sp_course_links')) {
			$table = $schema->createTable('sp_course_links');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('course_id', 'integer', ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('label', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('url', 'string', ['length' => 2000, 'notnull' => true]);
			$table->addColumn('sort_order', 'integer', ['unsigned' => true, 'notnull' => true, 'default' => 0]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['course_id'], 'sp_course_links_course_idx');
		}

		return $schema;
	}
}
