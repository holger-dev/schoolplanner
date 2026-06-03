<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000012Date20260602110000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('sp_participation')) {
			$table = $schema->createTable('sp_participation');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('lesson_id', 'integer', ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('student_id', 'integer', ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('status', 'string', ['length' => 32, 'notnull' => false, 'default' => '']);
			$table->addColumn('scale', 'string', ['length' => 16, 'notnull' => false, 'default' => '']);
			$table->addColumn('grade', 'string', ['length' => 16, 'notnull' => false, 'default' => '']);
			$table->addColumn('note', 'text', ['notnull' => false]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['lesson_id', 'student_id'], 'sp_part_lesson_student_idx');
			$table->addIndex(['student_id'], 'sp_part_student_idx');
		}

		return $schema;
	}
}
