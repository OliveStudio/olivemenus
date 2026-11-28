<?php

namespace olivestudio\olivemenus\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use olivestudio\olivemenus\migrations\MenuRevisionTables;
use olivestudio\olivemenus\models\OlivemenusItemsModel;
use olivestudio\olivemenus\models\OlivemenusModel;
use olivestudio\olivemenus\Olivemenus;
use olivestudio\olivemenus\records\OlivemenusItemsRecord;
use RuntimeException;
use yii\web\ConflictHttpException;
use yii\web\NotFoundHttpException;

final class MenuRevisions
{
    private const FORMAT = 1;

    public const PAGE_SIZE = 30;

    private const ITEM_FIELDS = [
        'name',
        'entry_id',
        'custom_url',
        'url_suffix',
        'kind',
        'target_site_uid',
        'source_section_uid',
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
        'source_item_uid',
    ];

    public function liveSnapshot(int $menuId): array
    {
        $items = Olivemenus::$plugin->olivemenuItems->getMenuItems($menuId, recoverDetached: true);
        $identities = OlivemenusItemsRecord::find()
            ->select(['id', 'uid'])
            ->where(['menu_id' => $menuId])
            ->indexBy('id')
            ->asArray()
            ->all();
        $fields = $this->_fields();

        return [
            'items' => $this->_flatten($items, $identities, $fields, $menuId),
            'fields' => $fields?->exportValues($menuId) ?? [],
        ];
    }

    public function hasDraft(int $menuId): bool
    {
        return $this->_draftQuery($menuId)->exists();
    }

    public function getDraft(int $menuId, ?string $liveVersion = null): ?array
    {
        $row = $this->_draftQuery($menuId)->one();
        if (!$row) {
            return null;
        }

        return [
            ...$this->_decode($row['snapshot']),
            'version' => $row['version'],
            'savedAt' => $row['saved_at'],
            'isStale' => !hash_equals($row['base_hash'], $liveVersion ?? $this->liveVersion($menuId)),
        ];
    }

    public function liveVersion(int $menuId): string
    {
        return $this->_hash($this->liveSnapshot($menuId));
    }

    public function snapshotVersion(array $snapshot): string
    {
        return $this->_hash($snapshot);
    }

    public function startDraft(OlivemenusModel $menu, ?int $actorId): void
    {
        $this->withMenuLock($menu, function() use ($menu, $actorId): void {
            $live = $this->liveSnapshot($menu->id);
            $this->_storeDraft($menu->id, $live, $this->_hash($live), null, $actorId);
        });
    }

    public function storeDraft(OlivemenusModel $menu, string $expectedVersion, callable $edit, ?int $actorId): void
    {
        $this->withMenuLock($menu, function() use ($menu, $expectedVersion, $edit, $actorId): void {
            $row = $this->_requireDraft($menu->id, $expectedVersion);
            $snapshot = $this->_decode($row['snapshot']);
            $snapshot['items'] = $edit($snapshot['items']);
            $this->_storeDraft($menu->id, $snapshot, $row['base_hash'], $expectedVersion, $actorId);
        });
    }

    /** Stages a validated canonical snapshot without writing the live menu. */
    public function stageSnapshot(OlivemenusModel $menu, array $snapshot, ?string $expectedVersion, ?int $actorId): void
    {
        $this->withMenuLock($menu, function() use ($menu, $snapshot, $expectedVersion, $actorId): void {
            $current = $expectedVersion === null ? null : $this->_requireDraft($menu->id, $expectedVersion);
            $baseHash = $current['base_hash'] ?? $this->liveVersion($menu->id);
            $this->_storeDraft($menu->id, $snapshot, $baseHash, $expectedVersion, $actorId);
        });
    }

    public function getHistory(int $menuId, ?int $before = null): array
    {
        $history = $this->_revisionQuery($menuId)
            ->select(['id', 'actor_id', 'published_at'])
            ->orderBy(['id' => SORT_DESC])
            ->limit(self::PAGE_SIZE + 1);

        if ($before !== null) {
            $history->andWhere(['<', 'id', $before]);
        }

        return $history->all();
    }

    public function getRevision(int $menuId, int $revisionId): ?array
    {
        $row = $this->_revisionQuery($menuId)->andWhere(['id' => $revisionId])->one();

        return $row ? $this->_decode($row['snapshot']) : null;
    }

    public function restore(OlivemenusModel $menu, int $revisionId, ?int $actorId): void
    {
        $this->withMenuLock($menu, function() use ($menu, $revisionId, $actorId): void {
            $snapshot = $this->getRevision($menu->id, $revisionId);
            if ($snapshot === null) {
                throw new NotFoundHttpException(Craft::t('olivemenus', 'Revision not found.'));
            }

            $this->_storeDraft($menu->id, $snapshot, $this->liveVersion($menu->id), null, $actorId);
        });
    }

    public function discard(OlivemenusModel $menu, string $expectedVersion): void
    {
        $this->withMenuLock($menu, function() use ($menu, $expectedVersion): void {
            $this->_requireDraft($menu->id, $expectedVersion);
            $this->_deleteDraft($menu->id);
        });
    }

    public function publish(OlivemenusModel $menu, string $expectedVersion, ?int $actorId): void
    {
        $this->withMenuLock($menu, function() use ($menu, $expectedVersion, $actorId): void {
            $row = $this->_requireDraft($menu->id, $expectedVersion);
            $live = $this->liveSnapshot($menu->id);
            if (!hash_equals($row['base_hash'], $this->_hash($live))) {
                throw new ConflictHttpException(Craft::t('olivemenus', 'The live menu changed after this draft was started. Review the draft and live menu before discarding it or starting over.'));
            }

            $this->applySnapshot($menu, $this->_decode($row['snapshot']));
            $this->recordPublication($menu->id, $live, $actorId);
            $this->_deleteDraft($menu->id);
        });

        Olivemenus::$plugin->olivemenus->invalidateMenuCache();
    }

    /** Records an atomic live change. Call within the transaction holding the menu row lock. */
    public function recordPublication(int $menuId, array $before, ?int $actorId): void
    {
        $after = $this->liveSnapshot($menuId);
        $previousHash = $this->_hash($before);
        if (hash_equals($previousHash, $this->_hash($after))) {
            return;
        }

        $last = $this->_revisionQuery($menuId)->select('snapshot_hash')->orderBy(['id' => SORT_DESC])->scalar();
        if ($last !== $previousHash) {
            $this->_appendRevision($menuId, $before, null);
        }

        $this->_appendRevision($menuId, $after, $actorId);
    }

    /** Serializes editorial operations that change the same menu. */
    public function withMenuLock(OlivemenusModel $menu, callable $callback): mixed
    {
        $db = Craft::$app->getDb();

        return $db->transaction(function() use ($db, $menu, $callback): mixed {
            $siteId = $db->createCommand(
                'SELECT [[site_id]] FROM {{%olivemenus}} WHERE [[id]] = :id FOR UPDATE',
                [':id' => $menu->id],
            )->queryScalar();
            if ($siteId === false || (int)$siteId !== $menu->site_id) {
                throw new NotFoundHttpException(Craft::t('olivemenus', 'Menu not found.'));
            }

            return $callback();
        });
    }

    /** Applies an internally validated snapshot while its menu row is locked. */
    public function applySnapshot(OlivemenusModel $menu, array $snapshot): void
    {
        $current = OlivemenusItemsRecord::find()->where(['menu_id' => $menu->id])->indexBy('id')->all();
        $byUid = [];
        foreach ($current as $record) {
            if ($record->uid) {
                $byUid[$record->uid] = (int)$record->id;
            }
        }

        $liveIds = [];
        $retained = [];
        $uids = [];
        foreach ($snapshot['items'] as $item) {
            $id = $item['id'];
            $parentId = (int)$item['parent_id'];
            $uid = $item['uid'];
            if (isset($liveIds[$id]) || ($parentId && !isset($liveIds[$parentId])) || ($uid !== '' && isset($uids[$uid]))) {
                throw new RuntimeException('Invalid item identity or parent order in the menu snapshot.');
            }

            $legacyId = $item['live_id'] ?? 0;
            $liveId = $uid === ''
                ? (isset($current[$legacyId]) && !$current[$legacyId]->uid ? (int)$legacyId : 0)
                : ($byUid[$uid] ?? 0);
            $liveIds[$id] = $liveId;
            $retained[$liveId] = true;
            $uids[$uid] = true;
        }

        $service = Olivemenus::$plugin->olivemenuItems;
        foreach (array_diff_key($current, $retained) as $record) {
            if (!$service->deleteMenuItem((int)$record->id)) {
                throw new RuntimeException('Could not remove an item omitted from the menu snapshot.');
            }
        }

        $ids = [];
        $fields = $this->_fields();
        foreach ($snapshot['items'] as $index => $item) {
            $model = new OlivemenusItemsModel($this->_modelValues($item) + [
                'id' => $liveIds[$item['id']],
                'uid' => $item['uid'],
                'menu_id' => $menu->id,
                'parent_id' => $ids[$item['parent_id']] ?? 0,
                'item_order' => $index,
            ]);
            $savedId = $service->saveMenuItem($model, restoreSourceItemUid: true);
            if ($savedId === null) {
                throw new RuntimeException('Could not apply a menu snapshot item.');
            }

            $ids[$item['id']] = $savedId;
            if (($item['field_values'] ?? []) !== ($fields?->exportValues($menu->id, $savedId) ?? [])) {
                $fields?->importValues($menu->id, $savedId, $item['field_values'] ?? []);
            }
        }

        if (($snapshot['fields'] ?? []) !== ($fields?->exportValues($menu->id) ?? [])) {
            $fields?->importValues($menu->id, null, $snapshot['fields'] ?? []);
        }
    }

    /** Converts a parent-first draft list into the same tree shape used by the editor. */
    public function itemsForEditor(int $menuId, array $items): array
    {
        $defaults = (new OlivemenusItemsModel())->getAttributes(self::ITEM_FIELDS);
        $byParent = [];
        foreach ($items as $item) {
            // Snapshots omit null values. Fill editor fields without changing stored revisions.
            $item = array_replace($item, $this->_modelValues($item) + $defaults);
            $item['menu_id'] = $menuId;
            $byParent[$item['parent_id']][] = $item;
        }

        return $this->_tree($byParent, 0);
    }

    /** Copies the model's persistent values without accepting identity fields. */
    public function itemValues(OlivemenusItemsModel $model): array
    {
        return $this->_modelValues($model->getAttributes());
    }

    private function _modelValues(array $item): array
    {
        $values = array_intersect_key(array_filter($item, static fn (mixed $value): bool => $value !== null), array_flip(self::ITEM_FIELDS));
        if (isset($values['entry_id'])) {
            $values['entry_id'] = (int)$values['entry_id'];
        }

        return $values;
    }

    private function _flatten(array $items, array $identities, ?FieldContentService $fields, int $menuId): array
    {
        $flat = [];
        foreach ($items as $item) {
            $id = (int)$item['id'];
            $flat[] = [
                'id' => $id,
                'live_id' => $id,
                'uid' => (string)($identities[$id]['uid'] ?? ''),
                'parent_id' => (int)$item['parent_id'],
                'item_order' => (int)$item['item_order'],
                ...$this->_modelValues($item),
                'field_values' => $fields?->exportValues($menuId, $id) ?? [],
            ];
            if (!empty($item['children'])) {
                $flat = [...$flat, ...$this->_flatten($item['children'], $identities, $fields, $menuId)];
            }
        }

        return $flat;
    }

    private function _tree(array $byParent, int $parentId): array
    {
        $items = $byParent[$parentId] ?? [];
        foreach ($items as &$item) {
            $item['children'] = $this->_tree($byParent, $item['id']);
        }

        return $items;
    }

    private function _fields(): ?FieldContentService
    {
        return class_exists(FieldContentService::class) ? new FieldContentService() : null;
    }

    private function _draftQuery(int $menuId): Query
    {
        return (new Query())->from(MenuRevisionTables::DRAFTS)->where(['menu_id' => $menuId]);
    }

    private function _revisionQuery(int $menuId): Query
    {
        return (new Query())->from(MenuRevisionTables::REVISIONS)->where(['menu_id' => $menuId]);
    }

    private function _requireDraft(int $menuId, string $version): array
    {
        $row = $this->_draftQuery($menuId)->one();
        if (!$row || !hash_equals($row['version'], $version)) {
            throw new ConflictHttpException(Craft::t('olivemenus', 'The draft has changed. Reload the menu and try again.'));
        }

        return $row;
    }

    private function _storeDraft(int $menuId, array $snapshot, string $baseHash, ?string $expectedVersion, ?int $actorId): void
    {
        $command = Craft::$app->getDb()->createCommand();
        $data = [
            'version' => StringHelper::UUID(),
            'base_hash' => $baseHash,
            'snapshot' => $this->_encode($snapshot),
            'actor_id' => $actorId,
            'saved_at' => Db::prepareDateForDb(new \DateTime()),
        ];

        if ($expectedVersion === null) {
            if ($this->hasDraft($menuId)) {
                throw new ConflictHttpException(Craft::t('olivemenus', 'This menu already has a draft. Publish or discard it before starting another.'));
            }

            $command->insert(MenuRevisionTables::DRAFTS, ['menu_id' => $menuId] + $data)->execute();
            return;
        }

        if ($command->update(MenuRevisionTables::DRAFTS, $data, ['menu_id' => $menuId, 'version' => $expectedVersion])->execute() !== 1) {
            throw new ConflictHttpException(Craft::t('olivemenus', 'The draft has changed. Reload the menu and try again.'));
        }
    }

    private function _deleteDraft(int $menuId): void
    {
        Craft::$app->getDb()->createCommand()->delete(MenuRevisionTables::DRAFTS, ['menu_id' => $menuId])->execute();
    }

    private function _appendRevision(int $menuId, array $snapshot, ?int $actorId): void
    {
        $encoded = $this->_encode($snapshot);
        Craft::$app->getDb()->createCommand()->insert(MenuRevisionTables::REVISIONS, [
            'menu_id' => $menuId,
            'snapshot' => $encoded,
            'snapshot_hash' => hash('sha256', $encoded),
            'actor_id' => $actorId,
            'published_at' => Db::prepareDateForDb(new \DateTime()),
        ])->execute();
    }

    private function _hash(array $snapshot): string
    {
        return hash('sha256', $this->_encode($snapshot));
    }

    private function _encode(array $snapshot): string
    {
        return json_encode(['format' => self::FORMAT, 'items' => $snapshot['items'], 'fields' => $snapshot['fields']], JSON_THROW_ON_ERROR);
    }

    private function _decode(string $encoded): array
    {
        $snapshot = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        if (($snapshot['format'] ?? null) !== self::FORMAT || !is_array($snapshot['items'] ?? null) || !is_array($snapshot['fields'] ?? null)) {
            throw new RuntimeException('Unsupported Olive Menus snapshot format.');
        }

        return ['items' => $snapshot['items'], 'fields' => $snapshot['fields']];
    }
}
