<?php

namespace olivestudio\olivemenus\migrations;


use Craft;
use craft\config\DbConfig;
use craft\db\Migration;

/**
 * m180517_142452_olivemenus_table migration.
 */
class Install extends Migration
{
    // Public Properties
    // =========================================================================

    /**
     * @var string The database driver to use
     */
    public $driver;

    // Public Methods
    // =========================================================================

    /**
     * This method contains the logic to be executed when applying this migration.
     * This method differs from [[up()]] in that the DB logic implemented here will
     * be enclosed within a DB transaction.
     * Child classes may implement this method instead of [[up()]] if the DB logic
     * needs to be within a transaction.
     *
     * @return boolean return a false value to indicate the migration fails
     * and should not proceed further. All other return values mean the migration succeeds.
     */
    public function safeUp()
    {
        $this->driver = Craft::$app->getConfig()->getDb()->driver;
        if ($this->createTables()) {
            $this->createIndexes();
            $this->addForeignKeys();
            // Refresh the db schema caches
            Craft::$app->db->schema->refresh();
            $this->insertDefaultData();
        }

        return true;
    }

    /**
     * This method contains the logic to be executed when removing this migration.
     * This method differs from [[down()]] in that the DB logic implemented here will
     * be enclosed within a DB transaction.
     * Child classes may implement this method instead of [[down()]] if the DB logic
     * needs to be within a transaction.
     *
     * @return boolean return a false value to indicate the migration fails
     * and should not proceed further. All other return values mean the migration succeeds.
     */
    public function safeDown()
    {
        $this->driver = Craft::$app->getConfig()->getDb()->driver;
        $this->removeTables();

        return true;
    }

    // Protected Methods
    // =========================================================================

    /**
     * Creates the tables needed for the Records used by the plugin
     *
     * @return bool
     */
    protected function createTables()
    {
        $tablesCreated = false;

        // olivemenus table
        $tableSchema = Craft::$app->db->schema->getTableSchema('{{%olivemenus}}');
        if ($tableSchema === null) {
            $tablesCreated = true;
            $this->createTable(
                '{{%olivemenus}}',
                [
                    'id' => $this->primaryKey(),
                    'name' => $this->string(255)->notNull()->defaultValue(''),
                    'handle' => $this->string(255)->notNull()->defaultValue(''),
					'site_id' => $this->integer(11),
                    'dateCreated' => $this->dateTime()->notNull(),
                    'dateUpdated' => $this->dateTime()->notNull(),
                    'uid' => $this->uid(),
                ]
            );
        }

        //olivemenus_items table
        $tableSchema = Craft::$app->db->schema->getTableSchema('{{%olivemenus_items}}');
        if ($tableSchema === null) {
            $tablesCreated = true;
            $this->createTable(
                '{{%olivemenus_items}}',
                [
                    'id' => $this->primaryKey(),
                    'menu_id' => $this->integer(11)->notNull(),
                    'parent_id' => $this->integer(11)->notNull(),
                    'item_order' => $this->integer(11)->notNull()->defaultValue(0),
                    'name' => $this->string(255)->notNull()->defaultValue(''),
                    'entry_id' => $this->integer(11),
                    'custom_url' => $this->string(255),
                    'class' => $this->string(255),
                    'class_parent' => $this->string(255),
                    'data_json' => $this->string(255),
                    'target' => $this->string(255),
                    'dateCreated' => $this->dateTime()->notNull(),
                    'dateUpdated' => $this->dateTime()->notNull(),
                    'uid' => $this->uid(),
                ]
            );
        }
        return $tablesCreated;
    }

    /**
     * Creates the indexes needed for the Records used by the plugin
     *
     * @return void
     */
    protected function createIndexes()
    {
        // olivemenus table
        $this->createIndex(
            $this->db->getIndexName(
                '{{%olivemenus}}',
                'name',
                true
            ),
            '{{%olivemenus}}',
            'name',
            true
        );
        $this->createIndex(
            $this->db->getIndexName(
                '{{%olivemenus}}',
                'handle',
                true
            ),
            '{{%olivemenus}}',
            'handle',
            true
        );
        // Additional commands depending on the db driver
        switch ($this->driver) {
            case DbConfig::DRIVER_MYSQL:
                break;
            case DbConfig::DRIVER_PGSQL:
                break;
        }
    }

    /**
     * Creates the foreign keys needed for the Records used by the plugin
     *
     * @return void
     */
    protected function addForeignKeys()
    {
        $this->addForeignKey(
            $this->db->getForeignKeyName('{{%olivemenus_items}}', 'menu_id'),
            '{{%olivemenus_items}}',
            'menu_id',
            '{{%olivemenus}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
    }

    /**
     * Populates the DB with the default data.
     *
     * @return void
     */
    protected function insertDefaultData()
    {
    }

    /**
     * Removes the tables needed for the Menus used by the plugin
     *
     * @return void
     */
    protected function removeTables()
    {
        $this->dropTableIfExists('{{%olivemenus_items}}');
        $this->dropTableIfExists('{{%olivemenus}}');
    }
}
