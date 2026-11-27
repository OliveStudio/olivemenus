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
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\Html;
use craft\web\Request;
use olivestudio\olivemenus\events\RegisterMenuElementTypesEvent;
use olivestudio\olivemenus\gql\MenuQuery;
use olivestudio\olivemenus\models\OlivemenusModel;
use olivestudio\olivemenus\Olivemenus;
use olivestudio\olivemenus\records\OlivemenusRecord;
use yii\caching\TagDependency;

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
    /**
     * @event RegisterMenuElementTypesEvent Fired when the supported menu element types are collected.
     */
    public const EVENT_REGISTER_MENU_ELEMENT_TYPES = 'registerMenuElementTypes';

    /**
     * @return array<class-string<ElementInterface>, string> Element types and their control panel labels.
     */
    public function getLinkableElementTypes(): array
    {
        $types = [
            Entry::class => Craft::t('olivemenus', 'Entries'),
            Category::class => Craft::t('olivemenus', 'Categories'),
            Asset::class => Craft::t('olivemenus', 'Assets'),
        ];
        if (Craft::$app->getPlugins()->isPluginEnabled('commerce') && class_exists(\craft\commerce\elements\Product::class)) {
            $types[\craft\commerce\elements\Product::class] = Craft::t('olivemenus', 'Products');
        }

        if ($this->hasEventHandlers(self::EVENT_REGISTER_MENU_ELEMENT_TYPES)) {
            $event = new RegisterMenuElementTypesEvent(['types' => $types]);
            $this->trigger(self::EVENT_REGISTER_MENU_ELEMENT_TYPES, $event);
            $types = $event->types;
        }

        return array_filter($types, static fn (string $label, string $type): bool => is_subclass_of(
            $type,
            ElementInterface::class,
        ) && $label !== '', ARRAY_FILTER_USE_BOTH);
    }

    // Public Methods
    // =========================================================================

    public function getAllMenus(int $siteId): mixed
    {
        return OlivemenusRecord::find()
                    ->where(['site_id' => $siteId])
                    ->all();
    }

    public function getMenuById(int $id): ?OlivemenusModel
    {
        $record = OlivemenusRecord::findOne([
            'id' => $id,
        ]);
        if ($record === null) {
            return null;
        }

        return new OlivemenusModel(array_filter(
            $record->getAttributes(),
            static fn (mixed $value): bool => $value !== null,
        ));
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

    public function deleteMenuById(int $id): ?int
    {
        $record = OlivemenusRecord::findOne($id);

        // The menu foreign key cascades to its items.
        if (!$record?->delete()) {
            return null;
        }

        $this->invalidateMenuCache();

        return 1;
    }

    public function saveMenu(OlivemenusModel $model): bool
    {
        $record = $model->id
            ? OlivemenusRecord::findOne($model->id)
            : new OlivemenusRecord();
        if ($record === null) {
            return false;
        }

        $record->name = $model->name;
        $record->handle = $model->handle;
        $record->site_id = $model->site_id;

        if (!$record->save()) {
            Craft::error($record->getErrors(), __METHOD__);
            return false;
        }

        $model->id = (int)$record->id;
        $this->invalidateMenuCache();

        return true;
    }

    public function invalidateMenuCache(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), MenuQuery::CACHE_TAG);
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

        $menuItems = $this->getMenuDataById((int)$menu->id, $siteId);
        foreach ($menuItems as $menuItem) {
            $localHTML .= $this->_getMenuItemHTML($menuItem, $config);
        }

        if ($withoutUl !== true) {
            $localHTML = '<ul class="' . $ulClass . '">' . $localHTML . '</ul>';
        }

        if ($withoutContainer !== true) {
            $navLabel = trim((string)($config['nav-label'] ?? ''));
            $tag = $navLabel === '' ? 'div' : 'nav';
            $labelAttr = $navLabel === '' ? '' : ' aria-label="' . Html::encode($navLabel) . '"';
            $localHTML = '<' . $tag . $menuIdAttr . ' class="menu' . $menuClass . '"' . $labelAttr . '>' .
                $localHTML . '</' . $tag . '>';
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

        return $this->getMenuDataById((int)$menu->id, $siteId);
    }

    public function getMenuDataById(int $menuId, int $siteId): array
    {
        return $this->_enrichMenuItems(
            Olivemenus::$plugin->olivemenuItems->getMenuItems($menuId),
            $siteId,
        );
    }

    /**
     * Returns the first active item's path from the root of the menu, including the item itself.
     */
    public function getBreadcrumbs(string $handle, ?int $siteId = null): array
    {
        return $this->_findActiveTrail($this->getMenuData($handle, $siteId));
    }

    private function _findActiveTrail(array $items): array
    {
        foreach ($items as $item) {
            if ($item['isActive']) {
                return [$item];
            }
            if ($item['isAncestorActive']) {
                return [$item, ...$this->_findActiveTrail($item['children'])];
            }
        }

        return [];
    }

    private function _enrichMenuItems(array $items, int $siteId): array
    {
        if (empty($items)) {
            return [];
        }

        $items = array_values(array_filter($items, static fn (array $item): bool => (bool)($item['enabled'] ?? true)));
        $elements = $this->getLinkedElements($this->_collectEntryIds($items), $siteId);
        $items = $this->_applyItemEnrichment($items, $elements);
        $this->_markAncestorActive($items);

        return $items;
    }

    private function _collectEntryIds(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (!($item['enabled'] ?? true)) {
                continue;
            }
            if (!empty($item['entry_id']) && empty($item['custom_url'])) {
                $ids[] = (int)$item['entry_id'];
            }

            if (!empty($item['children'])) {
                $ids = array_merge($ids, $this->_collectEntryIds($item['children']));
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param int[] $ids
     * @return array<int, ElementInterface> Enabled elements indexed by ID.
     */
    public function getLinkedElements(array $ids, int $siteId): array
    {
        if (!$ids) {
            return [];
        }

        $service = Craft::$app->getElements();
        $types = array_intersect($service->getElementTypesByIds($ids), array_keys($this->getLinkableElementTypes()));
        $elements = [];
        foreach ($types as $type) {
            $query = $type::find()
                ->id($ids)
                ->siteId($siteId);
            foreach ($query->all() as $element) {
                $elements[$element->id] = $element;
            }
        }

        return $elements;
    }

    private function _applyItemEnrichment(array $items, array $elements): array
    {
        $enriched = [];
        foreach ($items as $item) {
            $item['url'] = $this->_resolveMenuItemUrl($item, $elements);
            if ($item['url'] === '' && !empty($item['entry_id']) && empty($item['custom_url']) && !empty($item['hide_when_unavailable'])) {
                continue;
            }
            $item['element'] = $this->_resolveMenuItemElement($item, $elements);
            if (!empty($item['use_element_title']) && $item['element'] && $item['element']->title !== '') {
                $item['name'] = $item['element']->title ?? $item['name'];
            }
            $item['target'] = in_array($item['target'] ?? '', ['_self', '_blank'], true)
                ? $item['target']
                : '_self';
            $item['dataAttributes'] = $this->_parseDataAttributes($item['data_json'] ?? '');
            $item['isActive'] = $this->_isUrlActive($item['url']);
            $item['isAncestorActive'] = false;

            if (!empty($item['children'])) {
                $children = array_filter($item['children'], static fn (array $child): bool => (bool)($child['enabled'] ?? true));
                $item['children'] = $this->_applyItemEnrichment(array_values($children), $elements);
            }

            $enriched[] = $item;
        }

        return $enriched;
    }

    private function _markAncestorActive(array &$items): bool
    {
        $hasActiveDescendant = false;

        foreach ($items as &$item) {
            $childHasActive = false;

            if (!empty($item['children'])) {
                $childHasActive = $this->_markAncestorActive($item['children']);
            }

            $item['isAncestorActive'] = $childHasActive;

            if ($item['isActive'] || $childHasActive) {
                $hasActiveDescendant = true;
            }
        }

        return $hasActiveDescendant;
    }

    private function _resolveMenuItemUrl(array $item, array $elements): string
    {
        if (!empty($item['custom_url'])) {
            return $this->getSafeLinkUrl($this->_replaceEnvironmentVariables($item['custom_url']));
        }

        $entryId = (int)($item['entry_id'] ?? 0);
        return $this->getSafeLinkUrl($elements[$entryId]->url ?? '');
    }

    public function getSafeLinkUrl(string $url): string
    {
        $url = trim($url);
        if (preg_match('/[\x00-\x1f\x7f]/', $url)) {
            return '';
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        return $scheme === null || in_array(strtolower((string)$scheme), ['http', 'https', 'mailto', 'tel', 'sms', 'ftp'], true)
            ? $url
            : '';
    }

    private function _resolveMenuItemElement(array $item, array $elements): ?ElementInterface
    {
        if (!empty($item['custom_url'])) {
            return null;
        }

        $entryId = (int)($item['entry_id'] ?? 0);

        return $elements[$entryId] ?? null;
    }

    private function _isUrlActive(string $url): bool
    {
        $request = Craft::$app->getRequest();
        if (!$request instanceof Request || $request->getIsCpRequest() || $url === '') {
            return false;
        }

        $current = parse_url($request->getAbsoluteUrl());
        $target = parse_url($url);
        if ($current === false || $target === false) {
            return false;
        }

        if (isset($target['host'])) {
            $scheme = strtolower($target['scheme'] ?? $current['scheme'] ?? 'http');
            if (!in_array($scheme, ['http', 'https'], true)) {
                return false;
            }

            $port = $target['port'] ?? ($scheme === 'https' ? 443 : 80);
            $currentPort = $current['port'] ?? (($current['scheme'] ?? '') === 'https' ? 443 : 80);
            if (strcasecmp($target['host'], $current['host'] ?? '') !== 0 || $port !== $currentPort) {
                return false;
            }
        } elseif (isset($target['scheme']) || !str_starts_with($target['path'] ?? '', '/')) {
            return false;
        }

        return rtrim($target['path'] ?? '/', '/') === rtrim($current['path'] ?? '/', '/');
    }

    private function _getMenuItemHTML(array $menuItem, array $config): string
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
        if ($menuItem['isAncestorActive']) {
            $menuItemClass .= ' current-menu-ancestor';
        }

        $dataAttributes = $this->_buildDataAttributesFromArray($menuItem['dataAttributes'] ?? []);
        $label = Html::encode(Craft::t('olivemenus', $menuItem['name']));
        $menuClassAttr = Html::encode(trim($menuClass));
        $menuItemClassAttr = Html::encode(trim($menuItemClass));
        $menuItemUrl = $menuItem['url'] ?? '';
        $target = $menuItem['target'] ?? '_self';

        $localHTML = '<li id="menu-item-' . (int)$menuItem['id'] . '" class="' . $menuItemClassAttr . '">';

        if ($menuItemUrl) {
            $rel = $target === '_blank' ? ' rel="noopener"' : '';
            $current = $menuItem['isActive'] ? ' aria-current="page"' : '';
            $localHTML .= '<a class="' . $menuClassAttr . '" target="' . Html::encode($target) . '" href="' .
                Html::encode($menuItemUrl) . '"' . $rel . $current . $dataAttributes . '>' . $label . '</a>';
        } else {
            $localHTML .= '<span class="' . $menuClassAttr . '"' . $dataAttributes . '>' . $label . '</span>';
        }

        if (!empty($menuItem['children'])) {
            if (isset($config['sub-menu-ul-class'])) {
                $ulClass = Html::encode($config['sub-menu-ul-class']);
            }

            $localHTML .= '<ul class="' . $ulClass . '">';
            foreach ($menuItem['children'] as $child) {
                $localHTML .= $this->_getMenuItemHTML($child, $config);
            }
            $localHTML .= '</ul>';
        }
        $localHTML .= '</li>';

        return $localHTML;
    }

    private function _parseDataAttributes(?string $dataJson): array
    {
        if (empty($dataJson)) {
            return [];
        }

        $attributes = [];

        foreach (preg_split('/\r\n|\r|\n/', $dataJson) as $dataItem) {
            $parts = explode(':', $dataItem, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            if (!preg_match('/^(data|aria)-[a-z0-9_.:-]+$/D', $name) || $name === 'aria-current') {
                continue;
            }

            $attributes[$name] = $value;
        }

        return $attributes;
    }

    private function _buildDataAttributesFromArray(array $dataAttributes): string
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

    private function _replaceEnvironmentVariables(string $url): string
    {
        $replacements = [];
        foreach (Craft::$app->getConfig()->getGeneral()->aliases as $name => $value) {
            $token = '{' . $name . '}';
            if (!str_contains($url, $token)) {
                continue;
            }

            // Aliases can point to private filesystem paths as well as public URLs.
            $aliasUrl = is_string($value) ? $this->getSafeLinkUrl($value) : '';
            $scheme = parse_url($aliasUrl, PHP_URL_SCHEME);
            $isPublicUrl = in_array(strtolower((string)$scheme), ['http', 'https'], true) &&
                is_string(parse_url($aliasUrl, PHP_URL_HOST)) &&
                parse_url($aliasUrl, PHP_URL_USER) === null &&
                parse_url($aliasUrl, PHP_URL_PASS) === null;
            $isRelativeWebAlias = $name === '@web' &&
                str_starts_with($aliasUrl, '/') &&
                !str_starts_with($aliasUrl, '//');
            if (!$isPublicUrl && !$isRelativeWebAlias) {
                return '';
            }
            $replacements[$token] = $aliasUrl;
        }

        return strtr($url, $replacements);
    }
}
