<?php

namespace olivestudio\olivemenus\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Response;
use InvalidArgumentException;
use JsonException;
use olivestudio\olivemenus\assetbundles\olivemenus\OlivemenuItemsAsset;
use olivestudio\olivemenus\models\MenuItemPresentation;
use olivestudio\olivemenus\models\OlivemenusItemsModel;
use olivestudio\olivemenus\Olivemenus;
use olivestudio\olivemenus\services\FieldLayoutsService;
use olivestudio\olivemenus\services\MenuRevisions;
use RuntimeException;
use yii\db\Exception as DbException;
use yii\web\BadRequestHttpException;
use yii\web\ConflictHttpException;

class MenuItemsController extends MenuAdminController
{
    public function actionEdit(?int $menuId = null): Response
    {
        $menu = $this->editableMenu($this->positiveId($menuId));
        $items = Olivemenus::$plugin->olivemenuItems;
        $revisions = new MenuRevisions();
        $isDraft = $this->request->getQueryParam('draft') === '1';
        [$draft, $live] = $revisions->withMenuLock($menu, function() use ($revisions, $menu, $isDraft): array {
            $live = $isDraft ? null : $revisions->liveSnapshot($menu->id);
            $liveVersion = $live === null ? null : $revisions->snapshotVersion($live);

            return [$revisions->getDraft($menu->id, $liveVersion), $live];
        });
        if ($isDraft && $draft === null) {
            return $this->redirect(UrlHelper::cpUrl('olivemenus/menu-items/' . $menu->id));
        }

        $tree = $revisions->itemsForEditor($menu->id, ($isDraft ? $draft : $live)['items']);
        $this->response->getHeaders()->set('Cache-Control', 'private, no-store');
        $this->view->registerAssetBundle(OlivemenuItemsAsset::class);

        return $this->renderTemplate('olivemenus/_menu-items', [
            'menu' => $menu,
            'elementTypes' => Olivemenus::$plugin->olivemenus->getLinkableElementTypes(),
            'siteLinkOptions' => $items->getSiteLinkOptions(),
            'dynamicSectionOptions' => $items->getDynamicSectionOptions($menu->site_id),
            'menuItemsMarkup' => $items->getMenuItemsAdminMarkup($menu->id, $menu->site_id, $tree, $draft === null),
            'hasMenuFieldLayout' => $draft === null && FieldLayoutsService::hasLayout(FieldLayoutsService::MENU),
            'draft' => $draft,
            'isDraft' => $isDraft,
            'liveVersion' => $live === null ? null : $revisions->snapshotVersion($live),
            'objSite' => $this->editableSite($menu->site_id),
        ]);
    }

    public function actionSaveMenuItems(): Response
    {
        $this->requirePostRequest();
        $menu = $this->editableMenu($this->positiveId($this->request->getRequiredBodyParam('menu-id')));
        $redirect = UrlHelper::cpUrl('olivemenus/menu-items/' . $menu->id);

        try {
            $submittedItems = $this->_submittedItems();
            $deletedIds = $this->_deletedItemIds();

            $revisions = new MenuRevisions();
            $revisions->withMenuLock($menu, function() use ($revisions, $menu, $submittedItems, $deletedIds): void {
                $before = $revisions->liveSnapshot($menu->id);
                $expectedVersion = $this->request->getBodyParam('live-version');
                if ($expectedVersion !== null && (!is_string($expectedVersion) || !hash_equals($revisions->snapshotVersion($before), $expectedVersion))) {
                    throw new ConflictHttpException(Craft::t('olivemenus', 'The live menu has changed. Reload and try again.'));
                }

                $after = $before;
                $after['items'] = $this->_prepareItems($revisions, $menu->id, $menu->site_id, $before['items'], $submittedItems, $deletedIds);
                $revisions->applySnapshot($menu, $after);
                $revisions->recordPublication($menu->id, $before, Craft::$app->getUser()->getId());
            });
        } catch (BadRequestHttpException|ConflictHttpException $exception) {
            return $this->_itemSaveFailure($exception->getMessage(), $redirect);
        } catch (DbException|RuntimeException $exception) {
            Craft::error($exception->getMessage(), __METHOD__);

            return $this->_itemSaveFailure(
                Craft::t('olivemenus', 'Menu items could not be saved. Please try again.'),
                $redirect,
            );
        }

        Olivemenus::$plugin->olivemenus->invalidateMenuCache();
        $message = Craft::t('olivemenus', 'Menu items saved successfully.');
        if ($this->request->getAcceptsJson()) {
            $this->setSuccessFlash($message);
        }

        return $this->asSuccess($message, redirect: $redirect);
    }

    public function actionStoreDraft(): Response
    {
        $this->requirePostRequest();
        $menu = $this->editableMenu($this->positiveId($this->request->getRequiredBodyParam('menu-id')));
        $redirect = UrlHelper::cpUrl('olivemenus/menu-items/' . $menu->id, ['draft' => 1]);

        try {
            $expectedVersion = $this->request->getRequiredBodyParam('draft-version');
            if (!is_string($expectedVersion)) {
                throw new BadRequestHttpException(Craft::t('olivemenus', 'Invalid draft version.'));
            }

            $submittedItems = $this->_submittedItems();
            $deletedIds = $this->_deletedItemIds();
            $revisions = new MenuRevisions();
            $revisions->storeDraft(
                $menu,
                $expectedVersion,
                fn (array $prior): array => $this->_prepareItems($revisions, $menu->id, $menu->site_id, $prior, $submittedItems, $deletedIds),
                Craft::$app->getUser()->getId(),
            );
        } catch (BadRequestHttpException|ConflictHttpException $exception) {
            return $this->_itemSaveFailure($exception->getMessage(), $redirect);
        } catch (DbException|RuntimeException $exception) {
            Craft::error($exception->getMessage(), __METHOD__);

            return $this->_itemSaveFailure(Craft::t('olivemenus', 'The menu draft could not be saved.'), $redirect);
        }

        $message = Craft::t('olivemenus', 'Menu draft saved.');
        if ($this->request->getAcceptsJson()) {
            $this->setSuccessFlash($message);
        }

        return $this->asSuccess($message, redirect: $redirect);
    }

    private function _itemSaveFailure(string $message, string $redirect): Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asFailure($message);
        }

        $this->setFailFlash($message);

        return $this->redirect($redirect);
    }

    private function _submittedItems(): array
    {
        $serialized = $this->request->getRequiredBodyParam('menu-items-serialized');

        if (is_string($serialized)) {
            try {
                $items = json_decode($serialized, true, flags: JSON_THROW_ON_ERROR);
                if (is_array($items) && array_is_list($items)) {
                    return $items;
                }
            } catch (JsonException) {
                // Handle invalid JSON the same way as an invalid list.
            }
        }

        throw new BadRequestHttpException(Craft::t('olivemenus', 'Invalid menu items.'));
    }

    private function _deletedItemIds(): array
    {
        $deletedItems = $this->request->getBodyParam('menu-items-deleted', '');

        if (!is_string($deletedItems)) {
            throw new BadRequestHttpException(Craft::t('olivemenus', 'Invalid deleted menu items.'));
        }

        if ($deletedItems === '') {
            return [];
        }

        return array_unique(array_map($this->positiveId(...), explode(',', $deletedItems)));
    }

    private function _prepareItems(
        MenuRevisions $revisions,
        int $menuId,
        int $siteId,
        array $previousItems,
        array $submittedItems,
        array $deletedIds,
    ): array {
        $service = Olivemenus::$plugin->olivemenuItems;
        $existingItems = array_column($previousItems, null, 'id');
        $nextId = max([0, ...array_keys($existingItems)]) + 1;
        $items = [];
        $deletedIds = array_fill_keys($deletedIds, true);
        $resolvedParentIds = [];
        $savedIds = [];
        $dynamicSectionOptions = $service->getDynamicSectionOptions($siteId);

        if (!empty(array_diff_key($deletedIds, $existingItems))) {
            throw new BadRequestHttpException(Craft::t('olivemenus', 'A menu item is missing or belongs to another menu. Reload and try again.'));
        }

        foreach ($submittedItems as $order => $item) {
            if (!is_array($item) || !is_array($item['item-id'] ?? null)) {
                throw new BadRequestHttpException(Craft::t('olivemenus', 'Invalid menu item.'));
            }

            $clientId = $this->positiveId($item['item-id']['html'] ?? null);
            $savedId = $this->optionalId($item['item-id']['db'] ?? null);
            $parentReference = $item['parent-id'] ?? null;
            $parentClientId = $parentReference === 0 || $parentReference === '0'
                ? null
                : $this->optionalId($parentReference);

            if (isset($resolvedParentIds[$clientId]) || ($parentClientId !== null && !isset($resolvedParentIds[$parentClientId]))) {
                throw new BadRequestHttpException(Craft::t('olivemenus', 'The menu item order is invalid. Reload and try again.'));
            }

            if ($savedId !== null && (!isset($existingItems[$savedId]) || isset($deletedIds[$savedId]) || isset($savedIds[$savedId]))) {
                throw new BadRequestHttpException(Craft::t('olivemenus', 'A menu item is missing or belongs to another menu. Reload and try again.'));
            }

            $existingItem = $existingItems[$savedId] ?? null;
            $kind = $this->_itemText($item, 'kind', $existingItem['kind'] ?? OlivemenusItemsModel::KIND_LINK);
            $targetSiteUid = '';
            if ($kind === OlivemenusItemsModel::KIND_SITE) {
                $targetSiteUid = $this->_itemText($item, 'target-site-uid', $existingItem['target_site_uid'] ?? '');
                // A previously selected destination may have been disabled since this menu was saved.
                if ($targetSiteUid === '' || (($existingItem['target_site_uid'] ?? '') !== $targetSiteUid
                    && Olivemenus::$plugin->olivemenus->getSiteLinkUrl($targetSiteUid) === '')) {
                    throw new BadRequestHttpException(Craft::t('olivemenus', 'Choose an enabled destination site for menu item {number}.', [
                        'number' => $order + 1,
                    ]));
                }
            }
            $sourceSectionUid = '';
            $sourceSort = 'newest';
            $sourceLimit = OlivemenusItemsModel::DEFAULT_SOURCE_LIMIT;
            if ($kind === OlivemenusItemsModel::KIND_GROUP) {
                $sourceSectionUid = $this->_itemText($item, 'source-section-uid', $existingItem['source_section_uid'] ?? '');
                if ($sourceSectionUid !== '') {
                    $sourceSort = $this->_itemText($item, 'source-sort', $existingItem['source_sort'] ?? $sourceSort);
                    $sourceLimit = $this->positiveId($item['source-limit'] ?? $existingItem['source_limit'] ?? $sourceLimit);
                    $sourceUnchanged = $sourceSectionUid === ($existingItem['source_section_uid'] ?? '')
                        && $sourceSort === ($existingItem['source_sort'] ?? null)
                        && $sourceLimit === (int)($existingItem['source_limit'] ?? 0);
                    if (!$sourceUnchanged && !isset($dynamicSectionOptions[$sourceSectionUid])) {
                        throw new BadRequestHttpException(Craft::t('olivemenus', 'Choose an available section for menu item {number}.', [
                            'number' => $order + 1,
                        ]));
                    }
                }
            }
            $entryId = $kind === OlivemenusItemsModel::KIND_LINK ? ($item['entry-id'] ?? null) : null;
            $linkedId = $entryId === 0 || $entryId === '0' ? 0 : ($this->optionalId($entryId) ?? 0);
            try {
                $presentation = class_exists(MenuItemPresentation::class)
                    ? MenuItemPresentation::fromSubmission($item['presentation'] ?? null, $kind, $existingItem['presentation'] ?? null)
                    : null;
            } catch (InvalidArgumentException $exception) {
                throw new BadRequestHttpException(Craft::t('olivemenus', 'Menu item {number}: {message}', [
                    'number' => $order + 1,
                    'message' => $exception->getMessage(),
                ]));
            }

            $itemModel = new OlivemenusItemsModel([
                'id' => $savedId ?? 0,
                'menu_id' => $menuId,
                'parent_id' => $resolvedParentIds[$parentClientId] ?? 0,
                'item_order' => $order,
                'name' => $this->_itemText($item, 'name'),
                'entry_id' => $linkedId,
                'url_suffix' => $linkedId !== 0
                    ? trim($this->_itemText($item, 'url-suffix', $existingItem['url_suffix'] ?? ''))
                    : '',
                'custom_url' => $kind === OlivemenusItemsModel::KIND_LINK ? $this->_itemText($item, 'custom-url') : '',
                'kind' => $kind,
                'target_site_uid' => $targetSiteUid,
                'source_section_uid' => $sourceSectionUid,
                'source_sort' => $sourceSort,
                'source_limit' => $sourceLimit,
                'class' => $this->_itemText($item, 'class'),
                'class_parent' => $this->_itemText($item, 'class-parent'),
                'data_json' => $this->_itemText($item, 'data-json'),
                'target' => $this->_itemText($item, 'target'),
                'enabled' => $this->_itemFlag($item, 'item-enabled', (bool)($existingItem['enabled'] ?? true)),
                'use_element_title' => $this->_itemFlag($item, 'item-use-element-title', (bool)($existingItem['use_element_title'] ?? false)),
                'hide_when_unavailable' => $this->_itemFlag($item, 'item-hide-when-unavailable', (bool)($existingItem['hide_when_unavailable'] ?? $entryId)),
                ...($presentation !== null ? ['presentation' => $presentation] : []),
            ]);

            if (!$itemModel->validate()) {
                $fieldError = $itemModel->getFirstError('url_suffix')
                    ?? $itemModel->getFirstError('source_sort')
                    ?? $itemModel->getFirstError('source_limit');
                throw new BadRequestHttpException($fieldError !== null
                    ? Craft::t('olivemenus', 'Menu item {number}: {message}', [
                        'number' => $order + 1,
                        'message' => $fieldError,
                    ])
                    : Craft::t('olivemenus', 'Check the settings for menu item {number}.', ['number' => $order + 1]));
            }

            $editorId = $savedId ?? $nextId++;
            $items[] = [
                'id' => $editorId,
                'live_id' => $existingItem['live_id'] ?? null,
                'source_item_uid' => $existingItem['source_item_uid'] ?? null,
                'uid' => $existingItem['uid'] ?? StringHelper::UUID(),
                'parent_id' => $itemModel->parent_id,
                'item_order' => $order,
                ...$revisions->itemValues($itemModel),
                'field_values' => $existingItem['field_values'] ?? [],
            ];
            $resolvedParentIds[$clientId] = $editorId;
            $savedIds[$editorId] = true;
        }

        if (array_diff_key($existingItems, $savedIds, $deletedIds)) {
            throw new BadRequestHttpException(Craft::t('olivemenus', 'The menu has changed. Reload and try again.'));
        }

        return $items;
    }

    private function _itemText(array $item, string $key, string $default = ''): string
    {
        $value = $item[$key] ?? $default;

        if (!is_string($value)) {
            throw new BadRequestHttpException(Craft::t('olivemenus', 'Invalid menu item text.'));
        }

        return $value;
    }

    private function _itemFlag(array $item, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $item)) {
            return $default;
        }

        if (!is_bool($item[$key])) {
            throw new BadRequestHttpException(Craft::t('olivemenus', 'Invalid menu item settings.'));
        }

        return $item[$key];
    }
}
