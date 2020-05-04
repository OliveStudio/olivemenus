<?php

namespace olivestudio\olivemenus\migrations;

use craft\db\Migration;

/**
 * Migration: Add site_id field to olivemenus table migration.
 * @since 1.1.7
 */
class m200228_124859_olivemenus_addSiteIdFieldToMenusTable extends Migration
{
	/**
	 * @inheritdoc
	 */
	public function safeUp()
	{
		// Place migration code here...
		
		if (!$this->db->columnExists('{{%olivemenus}}', 'site_id')) {
			$this->addColumn('{{%olivemenus}}', 'site_id', $this->integer(11)->after('handle'));
		}
	}

	/**
	 * @inheritdoc
	 */
	public function safeDown()
	{
		echo "m200228_124859_olivemenus_addSiteIdFieldToMenusTable cannot be reverted.\n";
		return false;
	}
}