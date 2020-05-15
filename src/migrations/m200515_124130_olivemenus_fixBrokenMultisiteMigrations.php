<?php

namespace olivestudio\olivemenus\migrations;

use Craft;
use craft\db\Migration;

/**
 * Migration: Set site ID for existing menus
 * @since 1.1.10
 */
class m200515_124130_olivemenus_fixBrokenMultisiteMigrations extends Migration
{
	/**
	 * @inheritdoc
	 */
	public function safeUp()
	{
        $this->update('{{%olivemenus}}', ['site_id' => Craft::$app->sites->primarySite->id], ['site_id' => null]);
	}

	/**
	 * @inheritdoc
	 */
	public function safeDown()
	{
        return true;
	}
}
