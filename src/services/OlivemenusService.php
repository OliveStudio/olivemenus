<?php
/**
 * Olivemenus plugin for Craft CMS 3.x
 *
 * OliveStudio menu
 *
 * @link      http://www.olivestudio.net/
 * @copyright Copyright (c) 2018 Olivestudio
 */

namespace olivestudio\olivemenus\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\Category;
use olivestudio\olivemenus\models\OlivemenusModel;
use olivestudio\olivemenus\Olivemenus;
use olivestudio\olivemenus\records\OlivemenusRecord;

/**
 * OlivemenusService Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 *
 * @author    Olivestudio
 * @package   Olivemenus
 * @since     1.0.0
 */
class OlivemenusService extends Component
{
    // Public Methods
    // =========================================================================

    public function getAllMenus($siteId) {
        return OlivemenusRecord::find()
                    ->where(['site_id' => $siteId])
                    ->all();
    }

    public function getMenuById($id) {
        $record = OlivemenusRecord::findOne([
            'id' => $id
        ]);
        return new OlivemenusModel($record->getAttributes());
    }

    public function getMenuByHandle($handle) {
        return OlivemenusRecord::findOne([
            'handle' => $handle
        ]);
    }

    public function getMenuByName($name) {
        return OlivemenusRecord::findOne([
            'name' => $name
        ]);
    }

    public function deleteMenuById($id) {
        $record = OlivemenusRecord::findOne([
            'id' => $id
        ]);

        if ($record) {
            Olivemenus::$plugin->olivemenuItems->deleteItemsByMenuId($record);
            if ($record->delete()) {
                return 1;
            };
        }
    }

    public function saveMenu(OlivemenusModel $model) {
        $record = false;
        if (isset($model->id)) {
            $record = OlivemenusRecord::findOne( [
                'id' => $model->id
            ]);
        }

        if (!$record) {
            $record = new OlivemenusRecord();
        }

        $record->name = $model->name;
        $record->handle = $model->handle;
        $record->site_id = $model->site_id;

        $save = $record->save();
        if (!$save) {
            Craft::getLogger()->log( $record->getErrors(), LOG_ERR, 'olivemenus' );
        }
        return $save;
    }

    // Front-end Methods
    // =========================================================================
    public function getMenuHTML($handle = false, $config ) {
        if ($handle === false || ($menu = $this->getMenuByHandle($handle)) === null) {
            echo '<p>' . Craft::t('olivemenus', 'A menu with this handle does not exist!') . '</p>';
            return;
        }

        $menu_id = '';
        $menu_class = '';
        $ul_class = '';
        $withoutContainer = false;
        $withoutUl = false;

        if (!empty($config)) {
            if (isset($config['menu-id'])) {
                $menu_id = ' id="' .$config['menu-id']. '"';
            }
            if (isset($config['menu-class'])) {
                $menu_class .= ' ' . $config['menu-class'];
            }
            if (isset($config['ul-class'])) {
                $ul_class = $config['ul-class'];
            }
            if (isset($config['without-container'])) {
                $withoutContainer = $config['without-container'];
            }
            if (isset($config['without-ul'])) {
                $withoutUl = $config['without-ul'];
            }
        }

        $localHTML = '';

        $menu_items = Olivemenus::$plugin->olivemenuItems->getMenuItems($menu->id);
        foreach ($menu_items as $menu_item) {
            $localHTML .= $this->getMenuItemHTML($menu_item, $config);
        }

        if ($withoutUl !== true) {
            $localHTML = '<ul class="' . $ul_class . '">' . $localHTML . '</ul>';
        }

        if ($withoutContainer !== true) {
            $localHTML = '<div' . $menu_id . ' class="menu' . $menu_class . '">' . $localHTML . '</div>';
        }

        echo $localHTML;
    }

    private function getMenuItemHTML($menu_item, $config) {
        $menu_item_url = '';
        $ul_class = '';
        $menu_item_class = 'menu-item';
        $custom_url = $menu_item['custom_url'];
        $class = $menu_item['class'];
        $class_parent = $menu_item['class_parent'];

        $data_attributes = '';
        $data_json = $menu_item['data_json'];

        $menu_class = $class;
        $menu_item_class = $menu_item_class . ' ' .$class_parent;

        if (!empty($config)) {
            if (isset($config['li-class'])) {
                $menu_item_class .= ' ' . $config['li-class'];
            }

            if (isset($config['link-class'])) {
                $menu_class .= ' ' . $config['link-class'];
            }
        }

        if ($custom_url != '') {
            $menu_item_url = $this->replaceEnvironmentVariables($custom_url);
        } else {
            $entry = Entry::find()
                ->id($menu_item['entry_id'])
                ->one();

            if (!empty($entry) ) $menu_item_url = $entry->url;
            else {
                $entry = Category::find()
                ->id($menu_item['entry_id'])
                ->one();

                if (!empty($entry) ) $menu_item_url = $entry->url;
            }
        }

        if ($data_json) {
            $data_attributes = ' ';
            $data_json = explode(PHP_EOL, $data_json);
            foreach ($data_json as $data_item) {
                $data_item = explode(':', $data_item);
                $data_attributes .= trim($data_item[0]) . '="' .trim($data_item[1]). '"';
            }

        }

        //extract target option
        $target = $menu_item['target'];

        $current_active_url = Craft::$app->request->getServerName() . Craft::$app->request->getUrl();
        if ($current_active_url != '' && $menu_item_url != '') {
            $menu_item_url_filtered = preg_replace('#^https?://#', '', $menu_item_url);
            $current_active_url = preg_replace('/\?.*/', '', $current_active_url); // Remove query string
            if ( $current_active_url == $menu_item_url_filtered ) {
                $menu_class .= ' active';
                $menu_item_class .= ' current-menu-item';
            }
        }

        $localHTML = '';
        $localHTML .= '<li id="menu-item-' .$menu_item['id']. '" class="' .$menu_item_class. '">';

        if ($menu_item_url) {
            $localHTML .= '<a class="'. $menu_class. '" target="'. $target .'" href="' .$menu_item_url. '"' .$data_attributes. '>' . Craft::t('olivemenus', $menu_item['name']) . '</a>';
        } else {
            $localHTML .= '<span class="'. $menu_class. '"' .$data_attributes. '>' . Craft::t('olivemenus', $menu_item['name']) . '</span>';
        }

        if (isset($menu_item['children'])) {

            if (isset($config['sub-menu-ul-class'])) {
                $ul_class = $config['sub-menu-ul-class'];
            }

            $localHTML .= '<ul class="'.$ul_class.'">';
                foreach ( $menu_item['children'] as $child )
                {
                   $localHTML .= $this->getMenuItemHTML($child, $config);
                }
            $localHTML .= '</ul>';
        }
        $localHTML .= '</li>';

        return $localHTML;
    }

    private function replaceEnvironmentVariables($str) {
        $environmentVariables = Craft::$app->config->general->aliases;
        if (is_array($environmentVariables)) {
            $tmp = [];
            foreach ($environmentVariables as $tag => $val) {
                $tmp[sprintf("{%s}", $tag)] = $val;
            }
            $environmentVariables = $tmp;

            return str_replace(array_keys($environmentVariables), array_values($environmentVariables), $str);
        }
    }
}
