<?php

namespace olivestudio\olivemenus\migrations;

use craft\db\Migration;

class m261128_000000_olivemenus_urlSuffix extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%olivemenus_items}}';
        if (!$this->db->columnExists($table, 'url_suffix')) {
            $this->addColumn($table, 'url_suffix', $this->string(255)->notNull()->defaultValue(''));
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "Reverting would remove stored menu link suffixes.\n";

        return false;
    }
}
