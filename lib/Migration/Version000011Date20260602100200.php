<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000011Date20260602100200 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('sp_students')) {
			$table = $schema->createTable('sp_students');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('course_id', 'integer', ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('name', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('note', 'text', ['notnull' => false]);
			$table->addColumn('sort_order', 'integer', ['unsigned' => true, 'notnull' => true, 'default' => 0]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['course_id'], 'sp_students_course_idx');
		}

		if (!$schema->hasTable('sp_student_groups')) {
			$table = $schema->createTable('sp_student_groups');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('course_id', 'integer', ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('name', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['course_id'], 'sp_student_groups_course_idx');
		}

		if (!$schema->hasTable('sp_student_group_members')) {
			$table = $schema->createTable('sp_student_group_members');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('group_id', 'integer', ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('student_id', 'integer', ['unsigned' => true, 'notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['group_id', 'student_id'], 'sp_sgm_group_student_idx');
			$table->addIndex(['student_id'], 'sp_sgm_student_idx');
		}

		return $schema;
	}
}
