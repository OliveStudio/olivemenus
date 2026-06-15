<?php

namespace olivestudio\olivemenus\migrations;

use craft\db\Migration;

class m250611_000000_olivemenus_uniqueHandlePerSite extends Migration
{
    public function safeUp(): bool
    {
        $this->dropIndexIfExists('{{%olivemenus}}', 'name', true);
        $this->dropIndexIfExists('{{%olivemenus}}', 'handle', true);

        $this->createIndex(
            $this->db->getIndexName('{{%olivemenus}}', ['name', 'site_id'], true),
            '{{%olivemenus}}',
            ['name', 'site_id'],
            true
        );
        $this->createIndex(
            $this->db->getIndexName('{{%olivemenus}}', ['handle', 'site_id'], true),
            '{{%olivemenus}}',
            ['handle', 'site_id'],
            true
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropIndexIfExists('{{%olivemenus}}', ['name', 'site_id'], true);
        $this->dropIndexIfExists('{{%olivemenus}}', ['handle', 'site_id'], true);

        $this->createIndex(
            $this->db->getIndexName('{{%olivemenus}}', 'name', true),
            '{{%olivemenus}}',
            'name',
            true
        );
        $this->createIndex(
            $this->db->getIndexName('{{%olivemenus}}', 'handle', true),
            '{{%olivemenus}}',
            'handle',
            true
        );

        return true;
    }
}
