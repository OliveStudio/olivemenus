<?php

namespace olivestudio\olivemenus\services;

use Craft;
use craft\helpers\Json;
use InvalidArgumentException;
use JsonException;
use olivestudio\olivemenus\models\MenuItemPresentation;
use olivestudio\olivemenus\models\OlivemenusItemsModel;
use olivestudio\olivemenus\models\OlivemenusModel;
use olivestudio\olivemenus\Olivemenus;
use RuntimeException;

final class PortableMenuService
{
    public const MAX_BYTES = 1048576;
    private const MAX_NODES = 1000;
    private const MAX_DEPTH = 32;
    public const NODE_ATTRIBUTES = [
        'name',
        'custom_url',
        'url_suffix',
        'kind',
        'source_sort',
        'source_limit',
        'class',
        'class_parent',
        'data_json',
        'target',
        'enabled',
        'use_element_title',
        'hide_when_unavailable',
        'presentation',
    ];

    public function export(OlivemenusModel $menu): string
    {
        $fields = new FieldContentService();
        $menuFields = $fields->exportPortableValues((int)$menu->id);
        $warnings = $menuFields['warnings'];
        $rows = [];
        $this->_exportNodes((int)$menu->id, Olivemenus::$plugin->olivemenuItems->getMenuItems($menu->id, recoverDetached: true), 0, $rows, $warnings);

        return Json::encode([
            'format' => 'olive-menus',
            'version' => 1,
            'menu' => ['name' => $menu->name, 'handle' => $menu->handle, 'fields' => $menuFields['values']],
            'items' => $rows,
            'warnings' => array_values(array_unique($warnings)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function review(string $json, int $siteId): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException(Craft::t('olivemenus', 'Choose an Olive Menus JSON file of 1 MB or less.'));
        }

        try {
            $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException(Craft::t('olivemenus', 'This file does not contain valid JSON.'));
        }

        if (!is_array($document) || ($document['format'] ?? null) !== 'olive-menus' ||
            ($document['version'] ?? null) !== 1 || !is_array($document['menu'] ?? null) ||
            !is_array($document['items'] ?? null) || !array_is_list($document['items']) ||
            count($document['items']) > self::MAX_NODES) {
            throw new InvalidArgumentException(Craft::t('olivemenus', 'Choose an Olive Menus version 1 export with at most {count} items.', [
                'count' => self::MAX_NODES,
            ]));
        }

        $name = $document['menu']['name'] ?? null;
        $handle = $document['menu']['handle'] ?? null;
        if (!is_string($name) || $name === '' || mb_strlen($name) > 255 ||
            !is_string($handle) || $handle === '' || mb_strlen($handle) > 255) {
            throw new InvalidArgumentException(Craft::t('olivemenus', 'The menu name or handle in this file is invalid.'));
        }

        $rows = [];
        $fileWarnings = $document['warnings'] ?? [];
        $warnings = array_slice(array_values(array_filter(
            is_array($fileWarnings) ? $fileWarnings : [],
            static fn (mixed $warning): bool => is_string($warning) && mb_strlen($warning) <= 255,
        )), 0, 30);
        $menuFields = $this->_fieldValues($document['menu'], FieldLayoutsService::MENU, $warnings);
        $depths = [0 => 0];
        foreach ($document['items'] as $index => $node) {
            if (!is_array($node) || !is_int($node['key'] ?? null) || $node['key'] < 1 ||
                isset($depths[$node['key']]) || !is_int($node['parent'] ?? null) ||
                !isset($depths[$node['parent']]) || $depths[$node['parent']] >= self::MAX_DEPTH ||
                !is_array($node['settings'] ?? null)) {
                throw new InvalidArgumentException(Craft::t('olivemenus', 'Check the hierarchy for item {number}.', ['number' => $index + 1]));
            }

            $model = new OlivemenusItemsModel(['menu_id' => 1, 'item_order' => $index]);
            foreach (self::NODE_ATTRIBUTES as $attribute) {
                if (!array_key_exists($attribute, $node['settings'])) {
                    continue;
                }
                $value = $node['settings'][$attribute];
                $expected = $model->$attribute;
                if ($attribute === 'presentation') {
                    if ($value !== null && (!is_string($value) || MenuItemPresentation::read($value)['audience'] === 'invalid')) {
                        throw new InvalidArgumentException(Craft::t('olivemenus', 'Item {number} has invalid visibility or layout settings.', ['number' => $index + 1]));
                    }
                    $model->presentation = $value;
                    continue;
                }
                if ($value === null && is_string($expected)) {
                    continue;
                }
                if (get_debug_type($value) !== get_debug_type($expected)) {
                    throw new InvalidArgumentException(Craft::t('olivemenus', 'Item {number} has an invalid setting.', ['number' => $index + 1]));
                }
                $model->$attribute = $value;
            }

            $this->_resolveReferences($model, $node, $siteId, $warnings);
            if (!$model->validate() || ($model->custom_url !== '' &&
                Olivemenus::$plugin->olivemenus->getSafeLinkUrl($model->custom_url) === '')) {
                throw new InvalidArgumentException(Craft::t('olivemenus', 'Check the settings for item {number} in the export.', ['number' => $index + 1]));
            }

            $depths[$node['key']] = $depths[$node['parent']] + 1;
            $fields = $this->_fieldValues($node, FieldLayoutsService::NODE, $warnings);
            $rows[] = ['key' => $node['key'], 'parent' => $node['parent'], 'model' => $model, 'fields' => $fields];
        }

        return [
            'name' => $name,
            'handle' => $handle,
            'fields' => $menuFields,
            'items' => $rows,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function import(string $document, int $siteId, string $name, string $handle): int
    {
        $plan = $this->review($document, $siteId);
        $plugin = Olivemenus::$plugin;
        $menu = new OlivemenusModel(['name' => $name, 'handle' => $handle, 'site_id' => $siteId]);
        if (!$menu->validate()) {
            throw new InvalidArgumentException(implode(' ', $menu->getFirstErrors()));
        }

        $menuId = Craft::$app->getDb()->transaction(function() use ($plugin, $menu, $plan): int {
            if (!$plugin->olivemenus->saveMenu($menu)) {
                throw new RuntimeException('The imported menu could not be saved.');
            }

            $fields = new FieldContentService();
            $fields->importPortableValues((int)$menu->id, null, $plan['fields']);
            $ids = [0 => 0];
            foreach ($plan['items'] as $row) {
                $model = $row['model'];
                $model->menu_id = (int)$menu->id;
                $model->parent_id = $ids[$row['parent']];
                $ids[$row['key']] = $plugin->olivemenuItems->saveMenuItem($model)
                    ?? throw new RuntimeException('An imported menu item could not be saved.');
                $fields->importPortableValues((int)$menu->id, $ids[$row['key']], $row['fields']);
            }

            return (int)$menu->id;
        });

        $plugin->olivemenus->invalidateMenuCache();
        return $menuId;
    }

    private function _fieldValues(array $source, string $scope, array &$warnings): array
    {
        $input = $source['fields'] ?? [];
        if (!is_array($input)) {
            throw new InvalidArgumentException(Craft::t('olivemenus', 'The exported custom field values are invalid.'));
        }
        $review = (new FieldContentService())->reviewPortableValues($scope, $input);
        array_push($warnings, ...$review['warnings']);
        return $review['values'];
    }

    private function _exportNodes(int $menuId, array $nodes, int $parent, array &$rows, array &$warnings): void
    {
        $elements = Craft::$app->getElements();
        $sections = Craft::$app->getEntries();
        $sites = Craft::$app->getSites();
        $fields = new FieldContentService();
        foreach ($nodes as $item) {
            $key = (int)$item['id'];
            $section = empty($item['source_section_uid']) ? null : $sections->getSectionByUid($item['source_section_uid']);
            $targetSite = null;
            if (!empty($item['target_site_uid'])) {
                foreach ($sites->getAllSites(true) as $site) {
                    if ($site->uid === $item['target_site_uid']) {
                        $targetSite = $site;
                        break;
                    }
                }
            }
            $itemFields = $fields->exportPortableValues($menuId, $key);
            array_push($warnings, ...$itemFields['warnings']);
            $rows[] = [
                'key' => $key,
                'fields' => $itemFields['values'],
                'parent' => $parent,
                'settings' => array_intersect_key($item, array_flip(self::NODE_ATTRIBUTES)),
                'element' => !empty($item['entry_id']) ? $elements->getElementById((int)$item['entry_id'], siteId: '*')?->uid : null,
                'site' => $targetSite ? ['uid' => $targetSite->uid, 'handle' => $targetSite->handle] : null,
                'section' => $section ? ['uid' => $section->uid, 'handle' => $section->handle] : null,
            ];

            $this->_exportNodes($menuId, $item['children'] ?? [], $key, $rows, $warnings);
        }
    }

    private function _resolveReferences(OlivemenusItemsModel $item, array $node, int $siteId, array &$warnings): void
    {
        $elementUid = $node['element'] ?? null;
        if (is_string($elementUid) && strlen($elementUid) === 36 && $item->kind === OlivemenusItemsModel::KIND_LINK) {
            $element = Craft::$app->getElements()->getElementByUid($elementUid, siteId: $siteId);
            if ($element && isset(Olivemenus::$plugin->olivemenus->getLinkableElementTypes()[$element::class])) {
                $item->entry_id = (int)$element->id;
            } else {
                $warnings[] = Craft::t('olivemenus', '“{item}” has a linked element that could not be matched on this site.', ['item' => $item->name]);
                $item->url_suffix = '';
            }
        }

        if ($item->kind === OlivemenusItemsModel::KIND_SITE) {
            $siteRef = $node['site'] ?? [];
            foreach (Craft::$app->getSites()->getAllSites(false) as $site) {
                if (is_array($siteRef) && ($site->uid === ($siteRef['uid'] ?? null) ||
                    $site->handle === ($siteRef['handle'] ?? null)) && $site->hasUrls) {
                    $item->target_site_uid = $site->uid;
                    break;
                }
            }
            if ($item->target_site_uid === '') {
                $item->kind = OlivemenusItemsModel::KIND_HEADING;
                $warnings[] = Craft::t('olivemenus', '“{item}” will be a heading because its destination site is unavailable.', ['item' => $item->name]);
            }
        }

        $sectionRef = $node['section'] ?? null;
        if ($sectionRef !== null && $item->kind === OlivemenusItemsModel::KIND_GROUP) {
            $entries = Craft::$app->getEntries();
            $section = isset($sectionRef['uid']) && is_string($sectionRef['uid'])
                ? $entries->getSectionByUid($sectionRef['uid'])
                : null;
            if (!$section && isset($sectionRef['handle']) && is_string($sectionRef['handle'])) {
                $section = $entries->getSectionByHandle($sectionRef['handle']);
            }
            $options = Olivemenus::$plugin->olivemenuItems->getDynamicSectionOptions($siteId);
            if ($section && isset($options[$section->uid])) {
                $item->source_section_uid = $section->uid;
            } else {
                $warnings[] = Craft::t('olivemenus', '“{item}” will keep its manual links. Its entry section is unavailable.', ['item' => $item->name]);
            }
        }
    }
}
