<?php

namespace olivestudio\olivemenus\services;

use Craft;
use craft\models\Site;
use InvalidArgumentException;
use olivestudio\olivemenus\models\OlivemenusModel;
use olivestudio\olivemenus\Olivemenus;
use olivestudio\olivemenus\records\OlivemenusItemsRecord;
use olivestudio\olivemenus\records\OlivemenusRecord;
use RuntimeException;

final class SiteVariantService
{
    public function variants(OlivemenusModel $menu): array
    {
        return OlivemenusRecord::find()
            ->where(['source_menu_uid' => $menu->uid])
            ->indexBy('site_id')
            ->all();
    }

    public function sync(OlivemenusModel $menu, Site $targetSite): int
    {
        if ($menu->source_menu_uid !== null || $menu->site_id === $targetSite->id || !$targetSite->getEnabled()) {
            throw new InvalidArgumentException(Craft::t('olivemenus', 'Choose a different enabled site from the source menu.'));
        }

        $db = Craft::$app->getDb();
        $variantId = $db->transaction(function() use ($db, $menu, $targetSite): int {
            $sourceId = $db->createCommand(
                'SELECT [[id]] FROM {{%olivemenus}} WHERE [[id]] = :id FOR UPDATE',
                [':id' => $menu->id],
            )->queryScalar();
            if ($sourceId === false) {
                throw new InvalidArgumentException(Craft::t('olivemenus', 'The source menu is unavailable.'));
            }

            $revisions = new MenuRevisions();
            $before = null;
            $target = $this->variants($menu)[$targetSite->id] ?? null;
            if ($target === null) {
                $target = $this->_createVariant($menu, $targetSite);
                (new FieldContentService())->copyValues((int)$sourceId, null, (int)$target->id, null);
            } else {
                $targetId = $db->createCommand(
                    'SELECT [[id]] FROM {{%olivemenus}} WHERE [[id]] = :id FOR UPDATE',
                    [':id' => $target->id],
                )->queryScalar();
                if ($targetId === false) {
                    throw new InvalidArgumentException(Craft::t('olivemenus', 'The destination menu changed. Reload and try again.'));
                }
                if ($revisions->hasDraft((int)$targetId)) {
                    throw new InvalidArgumentException(Craft::t('olivemenus', 'Publish or discard the destination menu draft before syncing its structure.'));
                }
                $before = $revisions->liveSnapshot((int)$targetId);
            }

            $this->_syncItems((int)$sourceId, (int)$target->id);
            if ($before !== null) {
                $actor = Craft::$app->getUser()->getId();
                $revisions->recordPublication((int)$target->id, $before, $actor ? (int)$actor : null);
            }
            return (int)$target->id;
        });

        Olivemenus::$plugin->olivemenus->invalidateMenuCache();
        return $variantId;
    }

    private function _createVariant(OlivemenusModel $menu, Site $targetSite): OlivemenusRecord
    {
        $service = Olivemenus::$plugin->olivemenus;
        if ($service->getMenuByHandle($menu->handle, $targetSite->id) ||
            $service->getMenuByName($menu->name, $targetSite->id)) {
            throw new InvalidArgumentException(Craft::t('olivemenus', 'A separate menu on this site already uses the source name or handle.'));
        }

        $record = new OlivemenusRecord();
        $record->setAttributes([
            'name' => $menu->name,
            'handle' => $menu->handle,
            'site_id' => $targetSite->id,
            'source_menu_uid' => $menu->uid,
        ], false);
        if (!$record->save()) {
            throw new RuntimeException('The site variant could not be created.');
        }
        return $record;
    }

    private function _syncItems(int $sourceId, int $targetId): void
    {
        $fieldContent = new FieldContentService();
        $sourceItems = $this->_items($sourceId);
        $targetItems = $this->_items($targetId);
        $linked = [];
        foreach ($targetItems as $item) {
            if ($item->source_item_uid !== null) {
                $linked[$item->source_item_uid] = $item;
            }
        }

        $matches = [];
        $linkedIds = [];
        $order = 0;
        foreach ($sourceItems as $sourceItem) {
            $item = $linked[$sourceItem->uid] ?? new OlivemenusItemsRecord();
            if ($item->getIsNewRecord()) {
                $item->setAttributes($sourceItem->getAttributes([
                    ...PortableMenuService::NODE_ATTRIBUTES,
                    'entry_id',
                    'target_site_uid',
                    'source_section_uid',
                ]), false);
                $item->setAttributes([
                    'menu_id' => $targetId,
                    'parent_id' => 0,
                    'source_item_uid' => $sourceItem->uid,
                ], false);
                if (!$item->save()) {
                    throw new RuntimeException('An item could not be added to the site variant.');
                }
                $fieldContent->copyValues($sourceId, (int)$sourceItem->id, $targetId, (int)$item->id);
            }
            $matches[$sourceItem->id] = $item;
            $linkedIds[$item->id] = true;
        }

        foreach ($sourceItems as $sourceItem) {
            $item = $matches[$sourceItem->id];
            $item->parent_id = $matches[$sourceItem->parent_id]->id ?? 0;
            $item->item_order = $order++;
            if (!$item->save(false, ['parent_id', 'item_order'])) {
                throw new RuntimeException('The site variant structure could not be saved.');
            }
        }

        // Keep local and formerly shared items after the shared items on each level.
        foreach ($targetItems as $item) {
            if (isset($linkedIds[$item->id])) {
                continue;
            }
            $item->item_order = $order++;
            if (!$item->save(false, ['item_order'])) {
                throw new RuntimeException('Local items in the site variant could not be saved.');
            }
        }
    }

    private function _items(int $menuId): array
    {
        return OlivemenusItemsRecord::find()
            ->where(['menu_id' => $menuId])
            ->orderBy(['item_order' => SORT_ASC, 'id' => SORT_ASC])
            ->all();
    }
}
