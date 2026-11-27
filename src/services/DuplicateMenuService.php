<?php

namespace olivestudio\olivemenus\services;

use Craft;
use craft\helpers\StringHelper;
use craft\validators\HandleValidator;
use olivestudio\olivemenus\models\OlivemenusModel;
use olivestudio\olivemenus\Olivemenus;
use olivestudio\olivemenus\records\OlivemenusItemsRecord;
use olivestudio\olivemenus\records\OlivemenusRecord;
use RuntimeException;
use yii\db\IntegrityException;

final class DuplicateMenuService
{
    private const MAX_MENU_LABEL_LENGTH = 255;

    private const ITEM_ATTRIBUTES = [
        'item_order',
        'name',
        'entry_id',
        'custom_url',
        'url_suffix',
        'kind',
        'target_site_uid',
        'class',
        'class_parent',
        'data_json',
        'target',
        'enabled',
        'use_element_title',
        'hide_when_unavailable',
    ];

    public function duplicate(OlivemenusModel $source, ?int $targetSiteId = null): int
    {
        $targetSiteId ??= $source->site_id;
        if (Craft::$app->getSites()->getSiteById($targetSiteId) === null) {
            throw new RuntimeException('The destination site does not exist.');
        }

        $db = Craft::$app->getDb();
        $name = trim($source->name);
        if ($name === '') {
            $name = Craft::t('olivemenus', 'Menu');
        }
        $handle = preg_match(sprintf('/^%s$/D', HandleValidator::$handlePattern), $source->handle)
            ? $source->handle
            : (StringHelper::toHandle($source->handle) ?: 'menu');

        // Matching handles let the same Twig template render the menu on either site.
        $firstCopyNumber = $targetSiteId === $source->site_id ? 1 : 0;
        for ($copyNumber = $firstCopyNumber; ; $copyNumber++) {
            $nameSuffix = match ($copyNumber) {
                0 => '',
                1 => Craft::t('olivemenus', ' (copy)'),
                default => Craft::t('olivemenus', ' (copy {number})', ['number' => $copyNumber]),
            };
            $handleSuffix = match ($copyNumber) {
                0 => '',
                1 => 'Copy',
                default => "Copy$copyNumber",
            };
            $copyName = rtrim(mb_substr($name, 0, self::MAX_MENU_LABEL_LENGTH - mb_strlen($nameSuffix))) . $nameSuffix;
            $copyHandle = substr($handle, 0, self::MAX_MENU_LABEL_LENGTH - strlen($handleSuffix)) . $handleSuffix;

            if ($this->_identityExists($targetSiteId, $copyName, $copyHandle)) {
                continue;
            }

            try {
                $menuId = $db->transaction(fn (): int => $this->_insertCopy($source, $targetSiteId, $copyName, $copyHandle));
                Olivemenus::$plugin->olivemenus->invalidateMenuCache();

                return $menuId;
            } catch (IntegrityException $exception) {
                // Another request may have claimed either value after the existence check.
                if (!$this->_identityExists($targetSiteId, $copyName, $copyHandle)) {
                    throw $exception;
                }
            }
        }
    }

    private function _identityExists(int $siteId, string $name, string $handle): bool
    {
        return OlivemenusRecord::find()
            ->where(['site_id' => $siteId])
            ->andWhere(['or', ['name' => $name], ['handle' => $handle]])
            ->exists();
    }

    private function _insertCopy(OlivemenusModel $source, int $targetSiteId, string $name, string $handle): int
    {
        if (!OlivemenusRecord::find()->where(['id' => $source->id, 'site_id' => $source->site_id])->exists()) {
            throw new RuntimeException('The source menu no longer exists.');
        }

        $duplicate = new OlivemenusRecord();
        $duplicate->name = $name;
        $duplicate->handle = $handle;
        $duplicate->site_id = $targetSiteId;
        if (!$duplicate->save()) {
            throw new RuntimeException('The duplicate menu could not be saved.');
        }

        $items = OlivemenusItemsRecord::find()
            ->where(['menu_id' => $source->id])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        // Allocate new IDs before restoring parent links, regardless of insertion order.
        $copies = [];
        foreach ($items as $item) {
            $copy = new OlivemenusItemsRecord();
            $copy->setAttributes($item->getAttributes(self::ITEM_ATTRIBUTES), false);
            $copy->menu_id = $duplicate->id;
            $copy->parent_id = 0;
            if (!$copy->save()) {
                throw new RuntimeException('A duplicate menu item could not be saved.');
            }

            $copies[$item->id] = $copy;
        }

        foreach ($items as $item) {
            $parentId = (int)$item->parent_id;
            if ($parentId === 0) {
                continue;
            }

            $parent = $copies[$parentId] ?? null;
            if ($parent === null) {
                throw new RuntimeException('A menu item is missing its parent.');
            }

            $copy = $copies[$item->id];
            $copy->parent_id = $parent->id;
            if (!$copy->save(false, ['parent_id'])) {
                throw new RuntimeException('A duplicate menu item parent could not be saved.');
            }
        }

        return (int)$duplicate->id;
    }
}
