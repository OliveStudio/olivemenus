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
use craft\elements\Entry;

use craft\web\View;
use olivestudio\olivemenus\models\OlivemenusItemsModel;
use olivestudio\olivemenus\Olivemenus;
use olivestudio\olivemenus\records\OlivemenusItemsRecord;

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
class OlivemenuItemsService extends Component
{
    // Public Methods
    // =========================================================================
    public function getSectionsWithEntries(int $site_id): mixed
    {
        $sections = $this->_getSections($site_id);

        if ($sections) {
            foreach ($sections as $handle => $values) {
                if (!empty($sections[$handle])) {
                    foreach ($values as $index => $value) {
                        $sections[$handle][$index]['entries'] = $this->_getFirstEntriesBySection($value['handle'], $site_id);
                    }
                }
            }
        }

        return $sections;
    }

    public function getMenuItem(int $id): ?OlivemenusItemsModel
    {
        $record = OlivemenusItemsRecord::findOne([
            'id' => $id,
        ]);
        if ($record === null) {
            return null;
        }

        $attributes = array_filter($record->getAttributes(), static fn (mixed $value): bool => $value !== null);

        return new OlivemenusItemsModel($attributes);
    }

    public function saveMenuItem(OlivemenusItemsModel $model): ?int
    {
        $record = $model->id
            ? OlivemenusItemsRecord::findOne(['id' => $model->id, 'menu_id' => $model->menu_id])
            : new OlivemenusItemsRecord();
        if ($record === null) {
            return null;
        }

        $record->menu_id = $model->menu_id;
        $record->parent_id = $model->parent_id;
        $record->item_order = $model->item_order;
        $record->name = $model->name;
        $record->entry_id = $model->entry_id;
        $record->custom_url = $model->custom_url;
        $record->class = $model->class;
        $record->class_parent = $model->class_parent;
        $record->data_json = $model->data_json;
        $record->target = $model->target;
        $record->enabled = $model->enabled;
        $record->use_element_title = $model->use_element_title;
        $record->hide_when_unavailable = $model->hide_when_unavailable;

        $save = $record->save();
        if (!$save) {
            Craft::error($record->getErrors(), __METHOD__);
            return null;
        }

        return (int)$record->id;
    }

    public function deleteMenuItem(int $id): mixed
    {
        $record = OlivemenusItemsRecord::findOne([
            'id' => $id,
        ]);

        if ($record) {
            if ($record->delete()) {
                return 1;
            };
        }

        return 0;
    }

    public function deleteItemsByMenuId($record): mixed
    {
        $records = OlivemenusItemsRecord::findAll([
            'menu_id' => $record->id,
        ]);

        foreach ($records as $record) {
            $record->delete();
        }
        return null;
    }

    public function getMenuItems(int $menuId, bool $recoverDetached = false): array
    {
        $arrMenuItems = [];

        $menuItems = OlivemenusItemsRecord::find()
                        ->where(['menu_id' => $menuId])
                        ->orderBy(['item_order' => SORT_ASC, 'id' => SORT_ASC])
                        ->all();

        foreach ($menuItems as $intKey => $objItem) {
            $arrMenuItems[$intKey]['id'] = $objItem->id;
            $arrMenuItems[$intKey]['menu_id'] = $objItem->menu_id;
            $arrMenuItems[$intKey]['parent_id'] = $objItem->parent_id;
            $arrMenuItems[$intKey]['item_order'] = $objItem->item_order;
            $arrMenuItems[$intKey]['name'] = $objItem->name;
            $arrMenuItems[$intKey]['entry_id'] = $objItem->entry_id;
            $arrMenuItems[$intKey]['custom_url'] = $objItem->custom_url;
            $arrMenuItems[$intKey]['class'] = $objItem->class;
            $arrMenuItems[$intKey]['class_parent'] = $objItem->class_parent;
            $arrMenuItems[$intKey]['data_json'] = $objItem->data_json;
            $arrMenuItems[$intKey]['target'] = $objItem->target;
            $arrMenuItems[$intKey]['enabled'] = (bool)$objItem->enabled;
            $arrMenuItems[$intKey]['use_element_title'] = (bool)$objItem->use_element_title;
            $arrMenuItems[$intKey]['hide_when_unavailable'] = (bool)$objItem->hide_when_unavailable;
        }

        if ($arrMenuItems) {
            return $this->_sortMenuItemsByParents($arrMenuItems, $recoverDetached);
        }
        return $arrMenuItems;
    }

    public function getMenuItemsAdminMarkup(int $menuId, ?int $siteId = null): string
    {
        $items = $this->getMenuItems($menuId, recoverDetached: true);
        if (!$items) {
            return '';
        }

        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;
        $elementIds = OlivemenusItemsRecord::find()
            ->select(['entry_id'])
            ->where(['menu_id' => $menuId])
            ->andWhere(['>', 'entry_id', 0])
            ->distinct()
            ->column();
        $menus = Olivemenus::$plugin->olivemenus;
        $links = [];
        foreach ($menus->getLinkedElements($elementIds, $siteId) as $id => $element) {
            $links[$id] = [
                'url' => $menus->getSafeLinkUrl($element->url ?? ''),
                'title' => $element->title,
            ];
        }

        return Craft::$app->getView()->renderTemplate(
            'olivemenus/_menu-item',
            ['items' => $items, 'elements' => $links],
            View::TEMPLATE_MODE_CP,
        );
    }

    private function _getSections(int $siteId): array
    {
        $sections = ['single' => [], 'structure' => [], 'channel' => []];
        foreach (Craft::$app->getEntries()->getEditableSections() as $section) {
            if (isset($section->getSiteSettings()[$siteId], $sections[$section->type])) {
                $sections[$section->type][] = [
                    'name' => $section->name,
                    'handle' => $section->handle,
                ];
            }
        }

        return $sections;
    }

    private function _getFirstEntriesBySection(string $handle,int $site_id): mixed
    {
        return Entry::find()
                    ->section($handle)
                    ->siteId($site_id)
                    ->one();
    }

    private function _sortMenuItemsByParents(array $items, bool $recoverDetached): array
    {
        $byParent = [];
        foreach ($items as $item) {
            $byParent[$item['parent_id']][] = $item;
        }

        $tree = $this->_buildMenuTree($byParent, 0);
        if (!$recoverDetached) {
            return $tree;
        }

        // Show legacy items with a missing or cyclic parent so the editor can repair them on save.
        while ($byParent) {
            $parent = array_key_first($byParent);
            $item = array_shift($byParent[$parent]);
            if (!$byParent[$parent]) {
                unset($byParent[$parent]);
            }

            $item['parent_id'] = 0;
            $children = $this->_buildMenuTree($byParent, (int)$item['id']);
            if ($children) {
                $item['children'] = $children;
            }
            $tree[] = $item;
        }

        return $tree;
    }

    private function _buildMenuTree(array &$byParent, int $parentId): array
    {
        $items = $byParent[$parentId] ?? [];
        unset($byParent[$parentId]);
        foreach ($items as &$item) {
            if (isset($byParent[$item['id']])) {
                $item['children'] = $this->_buildMenuTree($byParent, (int)$item['id']);
            }
        }

        return $items;
    }
}
