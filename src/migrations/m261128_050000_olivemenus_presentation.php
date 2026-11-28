<?php

namespace olivestudio\olivemenus\migrations;

use craft\db\Migration;

class m261128_050000_olivemenus_presentation extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%olivemenus_items}}';
        if (!$this->db->columnExists($table, 'presentation')) {
            $this->addColumn($table, 'presentation', $this->text());
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "Reverting would remove stored menu item visibility and layout settings.\n";
        return false;
    }
}
