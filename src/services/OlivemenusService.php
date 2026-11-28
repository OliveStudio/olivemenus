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
use craft\errors\SiteNotFoundException;
use craft\helpers\Html;
use craft\web\Request;
use olivestudio\olivemenus\events\RegisterMenuElementTypesEvent;
use olivestudio\olivemenus\gql\MenuQuery;
use olivestudio\olivemenus\models\OlivemenusItemsModel;
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
        $deleted = Craft::$app->getDb()->transaction(function() use ($id): bool {
            $record = OlivemenusRecord::findOne($id);
            if (!$record) {
                return false;
            }
            (new FieldContentService())->deleteForMenu($id);
            if (!$record->delete()) {
                throw new \RuntimeException('The menu could not be removed.');
            }
            return true;
        });
        if (!$deleted) {
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

    public function getMenuDataById(
        int $menuId,
        int $siteId,
        ?callable $filterEntrySource = null,
        bool $forGraphql = false,
    ): array {
        Craft::$app->getElements()->collectCacheTags([MenuQuery::CACHE_TAG]);
        return $this->_enrichMenuItems(
            Olivemenus::$plugin->olivemenuItems->getMenuItems($menuId),
            $siteId,
            $filterEntrySource,
            $forGraphql,
        );
    }

    /** Renders an explicitly supplied tree for the authorized control panel preview. */
    public function getPreviewData(array $items, int $siteId): array
    {
        $sections = Olivemenus::$plugin->olivemenuItems->getDynamicSectionOptions($siteId);

        return $this->_enrichMenuItems($items, $siteId, static fn (\craft\models\Section $section): bool => isset($sections[$section->uid]));
    }

    /**
     * Returns the first active item's path from the root of the menu, including the item itself.
     */
    public function getBreadcrumbs(string $handle, ?int $siteId = null): array
    {
        return $this->_findItemTrail($this->getMenuData($handle, $siteId));
    }

    /**
     * Returns the active or selected item with its parent, siblings, and path.
     */
    public function getMenuContext(string $handle, ?int $siteId = null, int|string|null $itemId = null): ?array
    {
        $items = $this->getMenuData($handle, $siteId);
        $ancestors = $this->_findItemTrail($items, $itemId);
        if ($ancestors === []) {
            return null;
        }

        $item = array_pop($ancestors);
        $parent = $ancestors[array_key_last($ancestors)] ?? null;
        $siblings = array_values(array_filter(
            $parent['children'] ?? $items,
            static fn (array $sibling): bool => $sibling['id'] !== $item['id'],
        ));

        return [
            'item' => $item,
            'parent' => $parent,
            'siblings' => $siblings,
            'ancestors' => $ancestors,
            'branch' => $ancestors[0] ?? $item,
        ];
    }

    public function getMenuContent(string $handle, ?int $siteId = null, ?int $itemId = null): ?ElementInterface
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;
        $menu = $this->getMenuByHandle($handle, $siteId);
        if (!$menu || ($itemId !== null && $this->_findItemTrail($this->getMenuDataById((int)$menu->id, $siteId), $itemId) === [])) {
            return null;
        }
        Craft::$app->getElements()->collectCacheTags([MenuQuery::CACHE_TAG]);
        return (new FieldContentService())->getContent((int)$menu->id, $itemId);
    }

    private function _findItemTrail(array $items, int|string|null $itemId = null): array
    {
        foreach ($items as $item) {
            if ($itemId === null ? $item['isActive'] : (string)$item['id'] === (string)$itemId) {
                return [$item];
            }
            if ($itemId === null && !$item['isAncestorActive']) {
                continue;
            }

            $trail = $this->_findItemTrail($item['children'] ?? [], $itemId);
            if ($trail !== []) {
                return [$item, ...$trail];
            }
        }

        return [];
    }

    private function _enrichMenuItems(
        array $items,
        int $siteId,
        ?callable $filterEntrySource,
        bool $forGraphql = false,
    ): array {
        if (empty($items)) {
            return [];
        }

        $items = MenuItemPresentationFilter::apply($items, $siteId, $forGraphql);
        $elements = $this->getLinkedElements($this->_collectEntryIds($items), $siteId);
        $items = $this->_applyItemEnrichment($items, $elements);
        $this->_appendGroupSources($items, $siteId, $filterEntrySource);
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
            $item['isDynamic'] = false;
            $item['isActive'] = $this->_isUrlActive($item['url']);
            $item['isAncestorActive'] = false;

            if (!empty($item['children'])) {
                $item['children'] = $this->_applyItemEnrichment($item['children'], $elements);
            }

            $enriched[] = $item;
        }

        return $enriched;
    }

    private function _appendGroupSources(array &$items, int $siteId, ?callable $filterEntrySource): void
    {
        foreach ($items as &$item) {
            $manualEntryIds = $item['manualGroupEntryIds'] ?? [];
            unset($item['manualGroupEntryIds']);

            if (!empty($item['children'])) {
                $this->_appendGroupSources($item['children'], $siteId, $filterEntrySource);
            }
            if (($item['kind'] ?? null) !== OlivemenusItemsModel::KIND_GROUP || empty($item['source_section_uid'])) {
                continue;
            }

            $section = Craft::$app->getEntries()->getSectionByUid($item['source_section_uid']);
            if (!$section || !($section->getSiteSettings()[$siteId]->hasUrls ?? false)) {
                continue;
            }

            $order = match ($item['source_sort']) {
                'oldest' => ['entries.postDate' => SORT_ASC, 'elements.id' => SORT_ASC],
                'title' => ['title' => SORT_ASC, 'elements.id' => SORT_ASC],
                default => ['entries.postDate' => SORT_DESC, 'elements.id' => SORT_ASC],
            };
            $query = Entry::find()
                ->sectionId($section->id)
                ->siteId($siteId)
                ->status(Entry::STATUS_LIVE)
                ->drafts(false)
                ->revisions(false)
                ->orderBy($order)
                ->limit($item['source_limit']);
            if ($filterEntrySource && !$filterEntrySource($section, $query)) {
                continue;
            }

            if ($manualEntryIds !== []) {
                $query->id(['not', ...$manualEntryIds]);
            }

            foreach ($query->all() as $entry) {
                $url = $this->getSafeLinkUrl($entry->getUrl() ?? '');
                if ($url === '') {
                    continue;
                }

                $item['children'][] = [
                    'id' => "dynamic-{$item['id']}-$entry->id",
                    'menu_id' => $item['menu_id'],
                    'parent_id' => $item['id'],
                    'item_order' => count($item['children'] ?? []),
                    'entry_id' => $entry->id,
                    'name' => (string)$entry->title,
                    'custom_url' => '',
                    'url_suffix' => '',
                    'data_json' => '',
                    'enabled' => true,
                    'use_element_title' => true,
                    'hide_when_unavailable' => true,
                    'kind' => OlivemenusItemsModel::KIND_LINK,
                    'isDynamic' => true,
                    'url' => $url,
                    'element' => $entry,
                    'target' => '_self',
                    'class' => '',
                    'class_parent' => '',
                    'dataAttributes' => [],
                    'isActive' => $this->_isUrlActive($url),
                    'isAncestorActive' => false,
                    'children' => [],
                ];
            }
        }
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
        $kind = $item['kind'] ?? OlivemenusItemsModel::KIND_LINK;
        if ($kind === OlivemenusItemsModel::KIND_SITE) {
            return $this->getSiteLinkUrl($item['target_site_uid'] ?? '');
        }
        if ($kind !== OlivemenusItemsModel::KIND_LINK) {
            return '';
        }
        if (!empty($item['custom_url'])) {
            return $this->getSafeLinkUrl($this->_replaceEnvironmentVariables($item['custom_url']));
        }

        $entryId = (int)($item['entry_id'] ?? 0);
        $url = $this->getSafeLinkUrl($elements[$entryId]->url ?? '');
        $suffix = $item['url_suffix'] ?? '';
        if ($url === '' || $suffix === '') {
            return $url;
        }

        [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        [$query, $suffixFragment] = array_pad(explode('#', $suffix, 2), 2, null);
        if ($query !== '') {
            if (!str_contains($base, '?')) {
                $base .= '?';
            } elseif (!str_ends_with($base, '?') && !str_ends_with($base, '&')) {
                $base .= '&';
            }
            $base .= substr($query, 1);
        }

        $fragment = $suffixFragment ?? $fragment;
        return $fragment === null ? $base : $base . '#' . $fragment;
    }

    public function getSiteLinkUrl(string $uid): string
    {
        if ($uid === '') {
            return '';
        }

        try {
            $site = Craft::$app->getSites()->getSiteByUid($uid, false);
        } catch (SiteNotFoundException) {
            return '';
        }

        return $site->hasUrls ? $this->getSafeLinkUrl($site->getBaseUrl() ?? '') : '';
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
        $menuItemClass = match ($menuItem['kind'] ?? OlivemenusItemsModel::KIND_LINK) {
            OlivemenusItemsModel::KIND_HEADING => 'menu-item menu-item--heading',
            OlivemenusItemsModel::KIND_GROUP => 'menu-item menu-item--group',
            OlivemenusItemsModel::KIND_SITE => 'menu-item menu-item--site',
            default => 'menu-item',
        };
        $megaMenu = $menuItem['megaMenu'] ?? null;
        if ($megaMenu !== null) {
            $menuItemClass .= ' menu-item--mega';
        }
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

        $localHTML = '<li id="menu-item-' . Html::encode((string)$menuItem['id']) . '" class="' . $menuItemClassAttr . '">';

        if ($menuItemUrl) {
            $rel = $target === '_blank' ? ' rel="noopener"' : '';
            $current = $menuItem['isActive'] ? ' aria-current="page"' : '';
            $localHTML .= '<a class="' . $menuClassAttr . '" target="' . Html::encode($target) . '" href="' .
                Html::encode($menuItemUrl) . '"' . $rel . $current . $dataAttributes . '>' . $label . '</a>';
        } else {
            $localHTML .= '<span class="' . $menuClassAttr . '"' . $dataAttributes . '>' . $label . '</span>';
        }

        if ($megaMenu !== null) {
            $localHTML .= '<div class="menu-mega-panel">';
            if ($megaMenu['description'] !== '') {
                $localHTML .= '<p class="menu-mega-description">' . Html::encode($megaMenu['description']) . '</p>';
            }
        }

        if (!empty($menuItem['children'])) {
            $ulClass = $config['sub-menu-ul-class'] ?? '';
            if ($megaMenu !== null) {
                $ulClass .= ' menu-mega-columns menu-mega-columns--' . $megaMenu['columns'];
            }

            $localHTML .= '<ul class="' . Html::encode(trim($ulClass)) . '">';
            foreach ($menuItem['children'] as $child) {
                $localHTML .= $this->_getMenuItemHTML($child, $config);
            }
            $localHTML .= '</ul>';
        }
        if ($megaMenu !== null) {
            $localHTML .= '</div>';
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
