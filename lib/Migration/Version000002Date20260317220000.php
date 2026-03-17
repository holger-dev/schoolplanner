<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000002Date20260317220000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('schoolplanner_attachments')) {
			$table = $schema->createTable('schoolplanner_attachments');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('item_id', 'integer', ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('file_name', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('stored_name', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('mime_type', 'string', ['length' => 255, 'notnull' => false]);
			$table->addColumn('size', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['item_id'], 'sp_attachments_item_idx');
		}

		return $schema;
	}
}
