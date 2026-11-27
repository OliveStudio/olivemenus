<?php

namespace olivestudio\olivemenus\services;

use Craft;
use olivestudio\olivemenus\models\OlivemenusItemsModel;
use olivestudio\olivemenus\models\OlivemenusModel;
use olivestudio\olivemenus\Olivemenus;
use olivestudio\olivemenus\records\OlivemenusRecord;
use RuntimeException;
use verbb\navigation\elements\Node;
use verbb\navigation\Navigation;
use yii\base\UserException;
use yii\db\IntegrityException;

final class VerbbNavigationImportService
{
    /**
     * Reads and validates a single Navigation 3 menu and site.
     *
     * @return array{
     *     menu: OlivemenusModel,
     *     items: list<array{sourceId: int, parentId: int, model: OlivemenusItemsModel}>,
     *     warnings: list<string>,
     *     problems: list<string>
     * }
     */
    public function prepare(string $navigationHandle, string $siteHandle, ?string $targetHandle, ?string $targetName): array
    {
        $plugins = Craft::$app->getPlugins();
        $navigation = $plugins->getPlugin('navigation');
        if (!$navigation instanceof Navigation || !$plugins->isPluginEnabled('navigation')) {
            throw new UserException('Install and enable Verbb Navigation 3.x on this Craft project.');
        }

        $version = $navigation->getVersion();
        if (version_compare($version, '3.0.0', '<') || version_compare($version, '4.0.0', '>=')) {
            throw new UserException('This importer supports Verbb Navigation 3.x.');
        }

        $sites = Craft::$app->getSites();
        $site = $sites->getSiteByHandle($siteHandle);
        if ($site === null) {
            throw new UserException('The specified Craft site does not exist.');
        }

        $nav = $navigation->getNavs()->getNavByHandle($navigationHandle);
        if ($nav === null || !in_array($site->id, $nav->getSiteIds(), true)) {
            throw new UserException('The specified Navigation menu is unavailable on this site.');
        }

        $menu = new OlivemenusModel([
            'name' => $targetName ?? $nav->name ?? '',
            'handle' => $targetHandle ?? $nav->handle ?? '',
            'site_id' => $site->id,
        ]);
        if (mb_strlen($menu->name) > 255 || strlen($menu->handle) > 255 || !$menu->validate()) {
            throw new UserException('Check the destination name and handle. Both must be valid and unused on the selected site.');
        }

        $originalSite = $sites->getCurrentSite();
        $sites->setCurrentSite($site);
        try {
            $nodes = Node::find()
                ->nav($nav)
                ->siteId($site->id)
                ->status(null)
                ->orderBy(['structureelements.lft' => SORT_ASC])
                ->all();
            $plan = $this->_prepareItems($nodes, $site->id);
        } finally {
            $sites->setCurrentSite($originalSite);
        }

        $customFields = count($nav->getFieldLayout()?->getCustomFields() ?? []);
        if ($customFields > 0) {
            $plan['warnings'][] = "$customFields configured Verbb node field(s) have no equivalent in Olive Menus and will be omitted.";
        }

        return ['menu' => $menu] + $plan;
    }

    /**
     * Creates a new menu and saves all mapped nodes in a single transaction.
     *
     * @param list<array{sourceId: int, parentId: int, model: OlivemenusItemsModel}> $items
     * @throws UserException if the destination menu already exists
     */
    public function save(OlivemenusModel $menu, array $items): int
    {
        if ($menu->id !== 0) {
            throw new UserException('Choose a new menu name and handle before importing.');
        }

        $destination = clone $menu;
        $plugin = Olivemenus::$plugin;

        try {
            $menuId = Craft::$app->getDb()->transaction(function() use ($plugin, $destination, $items): int {
                if (!$plugin->olivemenus->saveMenu($destination, invalidateCache: false)) {
                    throw new RuntimeException('The destination menu could not be saved.');
                }

                $copiedIds = [];
                foreach ($items as $item) {
                    $copy = clone $item['model'];
                    $copy->menu_id = $destination->id;
                    $copy->parent_id = $item['parentId'] === 0
                        ? 0
                        : ($copiedIds[$item['parentId']] ?? throw new RuntimeException('A menu item is missing its imported parent.'));
                    $itemId = $plugin->olivemenuItems->saveMenuItem($copy);
                    if ($itemId === null) {
                        throw new RuntimeException('An imported menu item could not be saved.');
                    }

                    $copiedIds[$item['sourceId']] = $itemId;
                }

                return $destination->id;
            });
            $plugin->olivemenus->invalidateMenuCache();

            return $menuId;
        } catch (IntegrityException $exception) {
            if (OlivemenusRecord::find()
                ->where(['site_id' => $menu->site_id])
                ->andWhere(['or', ['name' => $menu->name], ['handle' => $menu->handle]])
                ->exists()) {
                throw new UserException('The destination name or handle is already in use on this site.');
            }

            throw $exception;
        }
    }

    /**
     * @param Node[] $nodes Nodes in preorder for the selected site
     * @return array{
     *     items: list<array{sourceId: int, parentId: int, model: OlivemenusItemsModel}>,
     *     warnings: list<string>,
     *     problems: list<string>
     * }
     */
    private function _prepareItems(array $nodes, int $siteId): array
    {
        $items = [];
        $problems = [];
        $warnings = [];
        $ancestors = [];
        $previousLevel = 0;
        $omittedAttributes = 0;
        $hiddenDescendants = 0;
        $linkableTypes = Olivemenus::$plugin->olivemenus->getLinkableElementTypes();

        foreach ($nodes as $order => $node) {
            $level = $node->level;
            $sourceId = $node->id;
            if ($sourceId === null || $level === null || $level < 1 || $level > $previousLevel + 1) {
                throw new UserException('The source tree contains an item without a valid parent on this site.');
            }

            $parent = $ancestors[$level - 1] ?? ['id' => 0, 'hidden' => false];
            $enabled = $node->enabled && $node->getEnabledForSite();
            $hiddenDescendants += (int)($enabled && $parent['hidden']);
            $ancestors[$level] = ['id' => $sourceId, 'hidden' => $parent['hidden'] || !$enabled];
            $previousLevel = $level;

            try {
                $model = $this->_mapNode($node, $siteId, $order, $linkableTypes, $omittedAttributes);
                $items[] = ['sourceId' => $sourceId, 'parentId' => $parent['id'], 'model' => $model];
            } catch (UserException $exception) {
                $problems[] = "Node $sourceId: " . $exception->getMessage();
            }
        }

        if ($omittedAttributes > 0) {
            $warnings[] = "$omittedAttributes custom link attribute(s) cannot be represented and will be omitted. Olive Menus supports data-* and aria-* except aria-current.";
        }

        if ($hiddenDescendants > 0) {
            $warnings[] = "$hiddenDescendants enabled node(s) sit under disabled parents. Olive Menus hides disabled branches and their descendants.";
        }

        return ['items' => $items, 'warnings' => $warnings, 'problems' => $problems];
    }

    /**
     * @param array<class-string, string> $linkableTypes
     */
    private function _mapNode(Node $node, int $siteId, int $order, array $linkableTypes, int &$omittedAttributes): OlivemenusItemsModel
    {
        $url = '';
        $entryId = 0;
        $useElementTitle = false;
        $label = $node->title ?? '';

        if ($node->isElement()) {
            if (!isset($linkableTypes[$node->type]) || !$node->elementId) {
                throw new UserException('The linked element type or ID is unsupported.');
            }

            if ($node->getElementSiteId() !== $siteId) {
                throw new UserException('The node links to an element on a different site.');
            }

            if ($node->urlSuffix !== null && $node->urlSuffix !== '') {
                throw new UserException('Olive Menus cannot append a URL suffix to a linked element.');
            }

            $entryId = $node->elementId;
            $useElementTitle = $label !== '' && $node->getElement()?->title === $label;
        } elseif ($node->isCustom()) {
            $url = $this->_literalUrl($node->getRawUrl() ?? '', $node->urlSuffix ?? '');
        } elseif (!$node->isPassive()) {
            throw new UserException('The node type does not have an equivalent in Olive Menus.');
        }

        $model = new OlivemenusItemsModel([
            // The real menu ID is assigned after the destination has been created.
            'menu_id' => 1,
            'item_order' => $order,
            'name' => $label,
            'entry_id' => $entryId,
            'custom_url' => $url,
            'class' => $node->classes ?? '',
            'data_json' => $this->_formatAttributes($node->customAttributes, $omittedAttributes),
            'target' => $node->newWindow ? '_blank' : '_self',
            'enabled' => $node->enabled && $node->getEnabledForSite(),
            'use_element_title' => $useElementTitle,
        ]);
        if (!$model->validate()) {
            throw new UserException('The item has a missing label or one of its fields exceeds the supported length.');
        }

        return $model;
    }

    private function _literalUrl(string $url, string $suffix): string
    {
        $url = trim($url) . $suffix;
        if ($url === '') {
            return '';
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($url === '0' || preg_match('/[\x00-\x1f\x7f{}]|^[@$]/', $url) || $scheme === false ||
            ($scheme !== null && !in_array(strtolower($scheme), ['http', 'https', 'mailto', 'tel', 'sms', 'ftp'], true))) {
            throw new UserException('Replace dynamic or unsupported URLs with literal links before importing.');
        }

        return $url;
    }

    private function _formatAttributes(array $attributes, int &$omittedAttributes): string
    {
        $lines = [];
        $seen = [];

        foreach ($attributes as $attribute) {
            $name = $attribute['attribute'] ?? null;
            $value = $attribute['value'] ?? null;
            if (!is_string($name) || !is_string($value)) {
                $omittedAttributes++;
                continue;
            }

            $name = strtolower(trim($name));
            if (!preg_match('/^(?:data|aria)-[a-z0-9_.-]+$/D', $name) || $name === 'aria-current' || isset($seen[$name]) ||
                preg_match('/[\x00-\x1f\x7f{}]/u', $value) || trim($value) !== $value) {
                $omittedAttributes++;
                continue;
            }

            $seen[$name] = true;
            $lines[] = "$name: $value";
        }

        return implode("\n", $lines);
    }
}
