<?php

namespace olivestudio\olivemenus\migrations;

use craft\db\Migration;

class m261128_040000_olivemenus_revisions extends Migration
{
    public function safeUp(): bool
    {
        MenuRevisionTables::create($this);

        return true;
    }

    public function safeDown(): bool
    {
        echo "Reverting would remove stored menu drafts and revisions.\n";

        return false;
    }
}
