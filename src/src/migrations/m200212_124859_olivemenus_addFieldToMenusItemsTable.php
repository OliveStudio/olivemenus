<?php

namespace olivestudio\olivemenus\migrations;

use craft\db\Migration;

/**
 * Migration: Add target field to menus_items_table migration.
 * @since 1.1.1
 */
class m200212_124859_olivemenus_addFieldToMenusItemsTable extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        // Place migration code here...
		
        if (!$this->db->columnExists('{{%olivemenus_items}}', 'target')) {
			$this->addColumn('{{%olivemenus_items}}', 'target', $this->string(255)->after('data_json'));
		}
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        echo "m200212_124859_olivemenus_addFieldToMenusItemsTable cannot be reverted.\n";
        return false;
    }
}