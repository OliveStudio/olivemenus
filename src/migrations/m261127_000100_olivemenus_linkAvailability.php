<?php

namespace olivestudio\olivemenus\migrations;

use craft\db\Migration;

class m261127_000100_olivemenus_linkAvailability extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%olivemenus_items}}';
        if (!$this->db->columnExists($table, 'hide_when_unavailable')) {
            $this->addColumn($table, 'hide_when_unavailable', $this->boolean()->notNull()->defaultValue(false));
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "Reverting would remove stored link visibility settings.\n";

        return false;
    }
}
