<?php

namespace olivestudio\olivemenus\migrations;

use craft\db\Migration;
use craft\db\Table;
use olivestudio\olivemenus\services\FieldContentService;

class m261128_030000_olivemenus_fields extends Migration
{
    public function safeUp(): bool
    {
        self::installFieldContentTable($this);
        return true;
    }

    public static function installFieldContentTable(Migration $migration): void
    {
        $table = FieldContentService::TABLE;
        if ($migration->db->tableExists($table)) {
            return;
        }

        $migration->createTable($table, [
            'elementId' => $migration->integer()->notNull(),
            'menuId' => $migration->integer()->notNull(),
            'itemId' => $migration->integer(),
            'ownerKey' => $migration->string(32)->notNull(),
            'PRIMARY KEY ([[elementId]])',
        ]);
        $migration->createIndex(null, $table, ['ownerKey'], true);
        $migration->addForeignKey(null, $table, 'elementId', Table::ELEMENTS, 'id', 'CASCADE');
        $migration->addForeignKey(null, $table, 'menuId', '{{%olivemenus}}', 'id', 'CASCADE');
        $migration->addForeignKey(null, $table, 'itemId', '{{%olivemenus_items}}', 'id', 'CASCADE');
    }

    public function safeDown(): bool
    {
        echo "Reverting would remove menu and node field content.\n";
        return false;
    }
}
