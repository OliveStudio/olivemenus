<?php

namespace olivestudio\olivemenus\migrations;

use craft\db\Migration;

class m261127_000000_olivemenus_itemOptions extends Migration
{
    public function safeUp(): bool
    {
        $menus = '{{%olivemenus}}';
        foreach (['name', 'handle'] as $column) {
            $this->createIndexIfMissing($menus, [$column, 'site_id'], true);
            $this->dropIndexIfExists($menus, $column, true);
        }

        $items = '{{%olivemenus_items}}';
        if (!$this->db->columnExists($items, 'enabled')) {
            $this->addColumn($items, 'enabled', $this->boolean()->notNull()->defaultValue(true));
        }
        if (!$this->db->columnExists($items, 'use_element_title')) {
            $this->addColumn($items, 'use_element_title', $this->boolean()->notNull()->defaultValue(false));
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "Reverting would remove stored menu item settings.\n";

        return false;
    }
}
