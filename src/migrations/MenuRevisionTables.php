<?php

namespace olivestudio\olivemenus\migrations;

use craft\db\Migration;

final class MenuRevisionTables
{
    public const DRAFTS = '{{%olivemenus_drafts}}';

    public const REVISIONS = '{{%olivemenus_revisions}}';

    public static function create(Migration $migration): void
    {
        if (!$migration->db->tableExists(self::DRAFTS)) {
            $migration->createTable(self::DRAFTS, [
                'menu_id' => $migration->integer()->notNull(),
                'version' => $migration->string(36)->notNull(),
                'base_hash' => $migration->string(64)->notNull(),
                'snapshot' => $migration->longText()->notNull(),
                'actor_id' => $migration->integer(),
                'saved_at' => $migration->dateTime()->notNull(),
                'PRIMARY KEY ([[menu_id]])',
            ]);
            self::_addMenuForeignKey($migration, self::DRAFTS);
        }

        if (!$migration->db->tableExists(self::REVISIONS)) {
            $migration->createTable(self::REVISIONS, [
                'id' => $migration->primaryKey(),
                'menu_id' => $migration->integer()->notNull(),
                'snapshot' => $migration->longText()->notNull(),
                'snapshot_hash' => $migration->string(64)->notNull(),
                'actor_id' => $migration->integer(),
                'published_at' => $migration->dateTime()->notNull(),
            ]);
            $migration->createIndex(null, self::REVISIONS, ['menu_id', 'id']);
            self::_addMenuForeignKey($migration, self::REVISIONS);
        }
    }

    public static function drop(Migration $migration): void
    {
        $migration->dropTableIfExists(self::DRAFTS);
        $migration->dropTableIfExists(self::REVISIONS);
    }

    private static function _addMenuForeignKey(Migration $migration, string $table): void
    {
        $migration->addForeignKey(
            $migration->db->getForeignKeyName($table, 'menu_id'),
            $table,
            'menu_id',
            '{{%olivemenus}}',
            'id',
            'CASCADE',
        );
    }
}
