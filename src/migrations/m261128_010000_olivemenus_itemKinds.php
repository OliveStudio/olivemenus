<?php

namespace olivestudio\olivemenus\migrations;

use craft\db\Migration;

class m261128_010000_olivemenus_itemKinds extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%olivemenus_items}}';
        if (!$this->db->columnExists($table, 'kind')) {
            $this->addColumn($table, 'kind', $this->string(16)->notNull()->defaultValue('link'));
        }
        if (!$this->db->columnExists($table, 'target_site_uid')) {
            $this->addColumn($table, 'target_site_uid', $this->string(36)->notNull()->defaultValue(''));
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "Reverting would remove stored menu item types and site destinations.\n";

        return false;
    }
}
