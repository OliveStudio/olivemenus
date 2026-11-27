<?php

namespace olivestudio\olivemenus\migrations;

use craft\db\Migration;

class m261128_020000_olivemenus_groupSources extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%olivemenus_items}}';
        $columns = [
            'source_section_uid' => $this->string(36)->notNull()->defaultValue(''),
            'source_sort' => $this->string(16)->notNull()->defaultValue('newest'),
            'source_limit' => $this->smallInteger()->notNull()->defaultValue(10),
        ];

        foreach ($columns as $name => $definition) {
            if (!$this->db->columnExists($table, $name)) {
                $this->addColumn($table, $name, $definition);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "Reverting would remove stored group sources.\n";

        return false;
    }
}
