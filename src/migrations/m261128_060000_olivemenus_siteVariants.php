<?php

namespace olivestudio\olivemenus\migrations;

use craft\db\Migration;

class m261128_060000_olivemenus_siteVariants extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%olivemenus}}', 'source_menu_uid')) {
            $this->addColumn('{{%olivemenus}}', 'source_menu_uid', $this->string(36));
            $this->createIndex(null, '{{%olivemenus}}', ['source_menu_uid', 'site_id'], true);
        }
        if (!$this->db->columnExists('{{%olivemenus_items}}', 'source_item_uid')) {
            $this->addColumn('{{%olivemenus_items}}', 'source_item_uid', $this->string(36));
            $this->createIndex(null, '{{%olivemenus_items}}', ['menu_id', 'source_item_uid'], true);
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "Reverting would remove the links between menu variants.\n";

        return false;
    }
}
