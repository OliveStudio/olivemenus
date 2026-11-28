<?php

/**
 * Olivemenus plugin for Craft CMS 5.x
 *
 * OliveStudio menu
 *
 * @link      http://www.olivestudio.net/
 * @copyright Copyright (c) 2018 Olivestudio
 */

namespace olivestudio\olivemenus;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Plugins;
use craft\services\Sites;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use olivestudio\olivemenus\services\OlivemenuItemsService;
use olivestudio\olivemenus\services\OlivemenusService;

use yii\base\Event;

/**
 * Craft plugins are very much like little applications in and of themselves. We’ve made
 * it as simple as we can, but the training wheels are off. A little prior knowledge is
 * going to be required to write a plugin.
 *
 * For the purposes of the plugin docs, we’re going to assume that you know PHP and SQL,
 * as well as some semi-advanced concepts like object-oriented programming and PHP namespaces.
 *
 * https://craftcms.com/docs/plugins/introduction
 *
 * @author    Olivestudio
 * @package   Olivemenus
 * @since     1.0.0
 *
 * @property OlivemenusService $olivemenus
 * @property OlivemenuItemsService $olivemenuItems
 */
class Olivemenus extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * Olivemenus::$plugin
     *
     * @var Olivemenus
     */
    public static Plugin $plugin;

    // Public Properties
    // =========================================================================

    /**
     * To execute your plugin’s migrations, you’ll need to increase its schema version.
     *
     * @var string
     */
    public string $schemaVersion = '1.1.21';

    // Public Methods
    // =========================================================================

    /**
     * Set our $plugin static property to this class so that it can be accessed via
     * Olivemenus::$plugin
     *
     * Called after the plugin class is instantiated; do any one-time initialization
     * here such as hooks and events.
     *
     * If you have a '/vendor/autoload.php' file, it will be loaded for you automatically;
     * you do not need to load it in your init() method.
     *
     */
    public function init(): void
    {
        parent::init();
        $this->setComponents([
            'olivemenus' => services\OlivemenusService::class,
            'olivemenuItems' => services\OlivemenuItemsService::class,
        ]);
        self::$plugin = $this;
        services\FieldLayoutsService::register();

        // Register our CP routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['olivemenus'] = 'olivemenus/menu';
                $event->rules['olivemenus/<siteHandle:\w+>'] = 'olivemenus/menu';
                $event->rules['olivemenus/menu-new/<siteHandle:\w+>'] = 'olivemenus/menu/menu-new';
                $event->rules['olivemenus/delete-menu'] = 'olivemenus/menu/delete-menu';
                $event->rules['olivemenus/delete-menu/<menuId:\d+>'] = 'olivemenus/menu/delete-menu';
                $event->rules['olivemenus/menu-edit/<menuId:\d+>'] = 'olivemenus/menu/menu-edit';
                $event->rules['olivemenus/menu-edit/'] = 'olivemenus/menu';
                $event->rules['olivemenus/menu-items/<menuId:\d+>'] = 'olivemenus/menu-items/edit';
                $event->rules['olivemenus/menu-history/<menuId:\d+>'] = 'olivemenus/menu-revisions/history';
                $event->rules['olivemenus/menu-preview/<menuId:\d+>'] = 'olivemenus/menu-revisions/preview';
                $event->rules['olivemenus/field-layouts/<scope:menu|node>'] = 'olivemenus/field-layout/edit';
                $event->rules['olivemenus/menu-fields/<menuId:\d+>'] = 'olivemenus/field-content/edit';
                $event->rules['olivemenus/node-fields/<menuId:\d+>/<itemId:\d+>'] = 'olivemenus/field-content/edit';
                $event->rules['olivemenus/import/<siteId:\d+>'] = 'olivemenus/menu-transfer/import';
                $event->rules['olivemenus/export/<menuId:\d+>'] = 'olivemenus/menu-transfer/export';
                $event->rules['olivemenus/site-variants/<menuId:\d+>'] = 'olivemenus/site-variants/index';
            }
        );

        gql\MenuQuery::register();

        // Site links resolve the current base URL, so keep cached navigation in sync with site changes.
        foreach ([Sites::EVENT_AFTER_SAVE_SITE, Sites::EVENT_AFTER_DELETE_SITE] as $eventName) {
            Event::on(Sites::class, $eventName, function(): void {
                $this->olivemenus->invalidateMenuCache();
            });
        }

        // Register our variables
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('olivemenus', variables\OlivemenusVariable::class);
            }
        );

        /**
         * Logging in Craft involves using one of the following methods:
         *
         * Craft::trace(): record a message to trace how a piece of code runs. This is mainly for development use.
         * Craft::info(): record a message that conveys some useful information.
         * Craft::warning(): record a warning message that indicates something unexpected has happened.
         * Craft::error(): record a fatal error that should be investigated as soon as possible.
         *
         * Unless `devMode` is on, only Craft::warning() & Craft::error() will log to `craft/storage/logs/web.log`
         *
         * It's recommended that you pass in the magic constant `__METHOD__` as the second parameter, which sets
         * the category to the method (prefixed with the fully qualified class name) where the constant appears.
         *
         * To enable the Yii debug toolbar, go to your user account in the AdminCP and check the
         * [] Show the debug toolbar on the front end & [] Show the debug toolbar on the Control Panel
         *
         * http://www.yiiframework.com/doc-2.0/guide-runtime-logging.html
         */
        Craft::info(
            Craft::t(
                'olivemenus',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }
}
