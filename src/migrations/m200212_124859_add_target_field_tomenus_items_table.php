<?php

namespace olivestudio\olivemenus\migrations;

use Craft;
use craft\db\Migration;

/**
 * m200212_124859_add_target_field_tomenus_items_table migration.
 */
class m200212_124859_add_target_field_tomenus_items_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        // Place migration code here...
		$this->addColumn('{{%olivemenus_items}}', 'target', $this->string(255));
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        echo "m200212_124859_add_target_field_tomenus_items_table cannot be reverted.\n";
        return false;
    }
}
