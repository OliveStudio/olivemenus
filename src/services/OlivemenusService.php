<?php
/**
 * Olivemenus plugin for Craft CMS 5.x
 *
 * OliveStudio menu
 *
 * @link      http://www.olivestudio.net/
 * @copyright Copyright (c) 2018 Olivestudio
 */

namespace olivestudio\olivemenus\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\elements\Entry;
use craft\elements\Category;
use craft\helpers\Html;
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

    public function getAllMenus(int $siteId): mixed
    {
        return OlivemenusRecord::find()
                    ->where(['site_id' => $siteId])
                    ->all();
    }

    public function getMenuById(int $id): mixed 
    {
        $record = OlivemenusRecord::findOne([
            'id' => $id
        ]);
        return new OlivemenusModel($record->getAttributes());
    }

    public function getMenuByHandle(string $handle, ?int $siteId = null): mixed
    {
        return OlivemenusRecord::findOne([
            'handle' => $handle,
            'site_id' => $siteId ?? Craft::$app->getSites()->getCurrentSite()->id,
        ]);
    }

    public function getMenuByName(string $name, ?int $siteId = null): mixed
    {
        return OlivemenusRecord::findOne([
            'name' => $name,
            'site_id' => $siteId ?? Craft::$app->getSites()->getCurrentSite()->id,
        ]);
    }

    public function deleteMenuById(int $id): mixed 
    {
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

    public function saveMenu(OlivemenusModel $model): mixed 
    {
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
    public function getMenuHTML($handle = false, array $config = [], ?int $siteId = null): string
    {
        $siteId ??= isset($config['site-id']) ? (int)$config['site-id'] : Craft::$app->getSites()->getCurrentSite()->id;

        if ($handle === false || ($menu = $this->getMenuByHandle($handle, $siteId)) === null) {
            return '<p>' . Html::encode(Craft::t('olivemenus', 'A menu with this handle does not exist!')) . '</p>';
        }

        $menuIdAttr = '';
        $menuClass = '';
        $ulClass = '';
        $withoutContainer = false;
        $withoutUl = false;

        if (!empty($config)) {
            if (isset($config['menu-id'])) {
                $menuIdAttr = ' id="' . Html::encode($config['menu-id']) . '"';
            }
            if (isset($config['menu-class'])) {
                $menuClass = ' ' . Html::encode($config['menu-class']);
            }
            if (isset($config['ul-class'])) {
                $ulClass = Html::encode($config['ul-class']);
            }
            if (isset($config['without-container'])) {
                $withoutContainer = $config['without-container'];
            }
            if (isset($config['without-ul'])) {
                $withoutUl = $config['without-ul'];
            }
        }

        $localHTML = '';

        $menuItems = $this->enrichMenuItems(
            Olivemenus::$plugin->olivemenuItems->getMenuItems($menu->id)
        );
        foreach ($menuItems as $menuItem) {
            $localHTML .= $this->getMenuItemHTML($menuItem, $config);
        }

        if ($withoutUl !== true) {
            $localHTML = '<ul class="' . $ulClass . '">' . $localHTML . '</ul>';
        }

        if ($withoutContainer !== true) {
            $localHTML = '<div' . $menuIdAttr . ' class="menu' . $menuClass . '">' . $localHTML . '</div>';
        }

        return $localHTML;
    }

    public function getMenuData(string $handle, ?int $siteId = null): array
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        if ($handle === '' || ($menu = $this->getMenuByHandle($handle, $siteId)) === null) {
            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                Craft::warning(
                    Craft::t('olivemenus', 'A menu with this handle does not exist!'),
                    __METHOD__
                );
            }
            return [];
        }

        return $this->enrichMenuItems(
            Olivemenus::$plugin->olivemenuItems->getMenuItems($menu->id)
        );
    }

    private function enrichMenuItems(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $elements = $this->loadElementsByIds($this->collectEntryIds($items));
        $items = $this->applyItemEnrichment($items, $elements);
        $this->markAncestorActive($items);

        return $items;
    }

    private function collectEntryIds(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (!empty($item['entry_id']) && empty($item['custom_url'])) {
                $ids[] = (int)$item['entry_id'];
            }

            if (!empty($item['children'])) {
                $ids = array_merge($ids, $this->collectEntryIds($item['children']));
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function loadElementsByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $elements = [];

        foreach (Entry::find()->id($ids)->all() as $entry) {
            $elements[$entry->id] = $entry;
        }

        $remainingIds = array_diff($ids, array_keys($elements));
        if ($remainingIds) {
            foreach (Category::find()->id($remainingIds)->all() as $category) {
                $elements[$category->id] = $category;
            }
        }

        return $elements;
    }

    private function applyItemEnrichment(array $items, array $elements): array
    {
        foreach ($items as $key => $item) {
            $item['url'] = $this->resolveMenuItemUrl($item, $elements);
            $item['element'] = $this->resolveMenuItemElement($item, $elements);
            $item['target'] = in_array($item['target'] ?? '', ['_self', '_blank'], true)
                ? $item['target']
                : '_self';
            $item['dataAttributes'] = $this->parseDataAttributes($item['data_json'] ?? '');
            $item['isActive'] = $this->isUrlActive($item['url']);
            $item['isAncestorActive'] = false;

            if (!empty($item['children'])) {
                $item['children'] = $this->applyItemEnrichment($item['children'], $elements);
            }

            $items[$key] = $item;
        }

        return $items;
    }

    private function markAncestorActive(array &$items): bool
    {
        $hasActiveDescendant = false;

        foreach ($items as &$item) {
            $childHasActive = false;

            if (!empty($item['children'])) {
                $childHasActive = $this->markAncestorActive($item['children']);
            }

            $item['isAncestorActive'] = $childHasActive;

            if ($item['isActive'] || $childHasActive) {
                $hasActiveDescendant = true;
            }
        }

        return $hasActiveDescendant;
    }

    private function resolveMenuItemUrl(array $item, array $elements): string
    {
        if (!empty($item['custom_url'])) {
            return $this->replaceEnvironmentVariables($item['custom_url']) ?? '';
        }

        $entryId = (int)($item['entry_id'] ?? 0);
        if ($entryId && isset($elements[$entryId])) {
            return $elements[$entryId]->url ?? '';
        }

        return '';
    }

    private function resolveMenuItemElement(array $item, array $elements): ?Element
    {
        if (!empty($item['custom_url'])) {
            return null;
        }

        $entryId = (int)($item['entry_id'] ?? 0);

        return $elements[$entryId] ?? null;
    }

    private function isUrlActive(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $currentActiveUrl = Craft::$app->request->getServerName() . Craft::$app->request->getUrl();
        if ($currentActiveUrl === '') {
            return false;
        }

        $urlFiltered = preg_replace('#^https?://#', '', $url);
        $currentActiveUrl = preg_replace('/\?.*/', '', $currentActiveUrl);

        return $currentActiveUrl === $urlFiltered;
    }

    private function getMenuItemHTML(array $menuItem, array $config): string
    {
        $ulClass = '';
        $menuItemClass = 'menu-item';
        $menuClass = $menuItem['class'] ?? '';
        $menuItemClass .= ' ' . ($menuItem['class_parent'] ?? '');

        if (!empty($config)) {
            if (isset($config['li-class'])) {
                $menuItemClass .= ' ' . $config['li-class'];
            }

            if (isset($config['link-class'])) {
                $menuClass .= ' ' . $config['link-class'];
            }
        }

        if ($menuItem['isActive']) {
            $menuClass .= ' active';
            $menuItemClass .= ' current-menu-item';
        }

        $dataAttributes = $this->buildDataAttributesFromArray($menuItem['dataAttributes'] ?? []);
        $label = Html::encode(Craft::t('olivemenus', $menuItem['name']));
        $menuClassAttr = Html::encode(trim($menuClass));
        $menuItemClassAttr = Html::encode(trim($menuItemClass));
        $menuItemUrl = $menuItem['url'] ?? '';
        $target = $menuItem['target'] ?? '_self';

        $localHTML = '<li id="menu-item-' . (int)$menuItem['id'] . '" class="' . $menuItemClassAttr . '">';

        if ($menuItemUrl) {
            $localHTML .= '<a class="' . $menuClassAttr . '" target="' . Html::encode($target) . '" href="' . Html::encode($menuItemUrl) . '"' . $dataAttributes . '>' . $label . '</a>';
        } else {
            $localHTML .= '<span class="' . $menuClassAttr . '"' . $dataAttributes . '>' . $label . '</span>';
        }

        if (!empty($menuItem['children'])) {
            if (isset($config['sub-menu-ul-class'])) {
                $ulClass = Html::encode($config['sub-menu-ul-class']);
            }

            $localHTML .= '<ul class="' . $ulClass . '">';
            foreach ($menuItem['children'] as $child) {
                $localHTML .= $this->getMenuItemHTML($child, $config);
            }
            $localHTML .= '</ul>';
        }
        $localHTML .= '</li>';

        return $localHTML;
    }

    private function parseDataAttributes(?string $dataJson): array
    {
        if (empty($dataJson)) {
            return [];
        }

        $attributes = [];

        foreach (explode(PHP_EOL, $dataJson) as $dataItem) {
            $parts = explode(':', $dataItem, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            $value = trim($parts[1]);
            if ($name === '') {
                continue;
            }

            $attributes[$name] = $value;
        }

        return $attributes;
    }

    private function buildDataAttributesFromArray(array $dataAttributes): string
    {
        if (empty($dataAttributes)) {
            return '';
        }

        $attributes = '';

        foreach ($dataAttributes as $name => $value) {
            $attributes .= ' ' . Html::encode($name) . '="' . Html::encode($value) . '"';
        }

        return $attributes;
    }

    private function replaceEnvironmentVariables(string $str): mixed 
    {
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
