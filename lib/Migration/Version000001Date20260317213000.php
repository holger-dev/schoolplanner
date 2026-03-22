<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000001Date20260317213000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('schoolplanner_courses')) {
			$table = $schema->createTable('schoolplanner_courses');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('user_id', 'string', ['length' => 64, 'notnull' => true]);
			$table->addColumn('name', 'string', ['length' => 200, 'notnull' => true]);
			$table->addColumn('description', 'text', ['notnull' => false]);
			$table->addColumn('publish_slug', 'string', ['length' => 200, 'notnull' => true]);
			$table->addColumn('published_url', 'string', ['length' => 400, 'notnull' => false]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'sp_courses_user_idx');
		}

		if (!$schema->hasTable('schoolplanner_lessons')) {
			$table = $schema->createTable('schoolplanner_lessons');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('course_id', 'integer', ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('lesson_date', 'date', ['notnull' => true]);
			$table->addColumn('title', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('description', 'text', ['notnull' => false]);
			$table->addColumn('sort_order', 'integer', ['unsigned' => true, 'notnull' => true, 'default' => 0]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['course_id', 'lesson_date'], 'sp_lessons_course_date_idx');
		}

		if (!$schema->hasTable('schoolplanner_items')) {
			$table = $schema->createTable('schoolplanner_items');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('lesson_id', 'integer', ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('title', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('description', 'text', ['notnull' => false]);
			$table->addColumn('published', 'integer', ['unsigned' => true, 'notnull' => true, 'default' => 0]);
			$table->addColumn('sort_order', 'integer', ['unsigned' => true, 'notnull' => true, 'default' => 0]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['lesson_id'], 'sp_items_lesson_idx');
		}

		return $schema;
	}
}
