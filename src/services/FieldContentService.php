<?php

namespace olivestudio\olivemenus\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\db\EntryQuery;
use craft\fields\Checkboxes;
use craft\fields\Color;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\Email;
use craft\fields\Lightswitch;
use craft\fields\Matrix;
use craft\fields\MultiSelect;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\RadioButtons;
use craft\fields\Time;
use craft\fields\Url;
use craft\helpers\DateTimeHelper;
use InvalidArgumentException;
use olivestudio\olivemenus\elements\MenuFieldContent;
use olivestudio\olivemenus\elements\NavigationFieldContent;
use olivestudio\olivemenus\elements\NodeFieldContent;
use olivestudio\olivemenus\records\OlivemenusItemsRecord;
use olivestudio\olivemenus\records\OlivemenusRecord;
use RuntimeException;

final class FieldContentService
{
    public const TABLE = '{{%olivemenus_field_content}}';

    private const PORTABLE_TYPES = [
        Checkboxes::class,
        Color::class,
        Date::class,
        Dropdown::class,
        Email::class,
        Lightswitch::class,
        MultiSelect::class,
        Number::class,
        PlainText::class,
        RadioButtons::class,
        Time::class,
        Url::class,
    ];

    public function getOwnerMenuId(?int $elementId): ?int
    {
        if ($elementId === null) {
            return null;
        }

        $menuId = (new Query())->select('menuId')->from(self::TABLE)->where(['elementId' => $elementId])->scalar();
        return $menuId === false ? null : (int)$menuId;
    }

    public function getContent(int $menuId, ?int $itemId = null): ?NavigationFieldContent
    {
        $elementId = $this->elementId($menuId, $itemId);
        if ($elementId === null) {
            return null;
        }

        $menu = OlivemenusRecord::findOne($menuId);
        if (!$menu) {
            return null;
        }

        $type = $itemId === null ? MenuFieldContent::class : NodeFieldContent::class;
        return $type::find()->id($elementId)->siteId((int)$menu->site_id)->status(null)->one();
    }

    public function getNodeContent(int|string $itemId): ?NavigationFieldContent
    {
        if (!ctype_digit((string)$itemId)) {
            return null;
        }

        $node = OlivemenusItemsRecord::findOne((int)$itemId);
        return $node ? $this->getContent((int)$node->menu_id, (int)$node->id) : null;
    }

    public function getEditorContent(int $menuId, ?int $itemId = null): NavigationFieldContent
    {
        $menu = OlivemenusRecord::findOne($menuId);
        if (!$menu || ($itemId !== null && !OlivemenusItemsRecord::find()->where(['id' => $itemId, 'menu_id' => $menuId])->exists())) {
            throw new RuntimeException('The owner of this field content does not exist.');
        }

        $scope = $itemId === null ? FieldLayoutsService::MENU : FieldLayoutsService::NODE;
        $type = $itemId === null ? MenuFieldContent::class : NodeFieldContent::class;
        $content = $this->getContent($menuId, $itemId) ?? new $type(['siteId' => (int)$menu->site_id]);
        $content->fieldLayoutId = FieldLayoutsService::layout($scope)->id;
        $content->ownerMenuId = $menuId;
        $content->setFieldParamNamespace('fields');
        $content->setScenario(Element::SCENARIO_LIVE);
        return $content;
    }

    public function saveContent(NavigationFieldContent $content, int $menuId, ?int $itemId = null): bool
    {
        $db = Craft::$app->getDb();
        return $db->transaction(function() use ($db, $content, $menuId, $itemId): bool {
            $siteId = $db->createCommand(
                'SELECT [[site_id]] FROM {{%olivemenus}} WHERE [[id]] = :id FOR UPDATE',
                [':id' => $menuId],
            )->queryScalar();
            if ($siteId === false || (int)$siteId !== $content->siteId) {
                throw new RuntimeException('The field content site does not match its menu.');
            }

            if ($itemId !== null && !OlivemenusItemsRecord::find()->where(['id' => $itemId, 'menu_id' => $menuId])->exists()) {
                throw new RuntimeException('The field content item no longer belongs to this menu.');
            }

            $elementId = $this->elementId($menuId, $itemId);
            $type = $itemId === null ? MenuFieldContent::class : NodeFieldContent::class;
            if (!$content instanceof $type || $elementId !== $content->id) {
                throw new RuntimeException('The field content owner changed. Reload and try again.');
            }

            if (!Craft::$app->getElements()->saveElement($content)) {
                return false;
            }

            if ($elementId === null) {
                $this->attachContent($content, $menuId, $itemId);
            }

            return true;
        });
    }

    /**
     * Captures same-installation values, including local IDs used by native relation fields.
     */
    public function exportValues(int $menuId, ?int $itemId = null): array
    {
        $content = $this->getContent($menuId, $itemId);
        return $content ? $this->snapshotValues($content) : [];
    }

    public function importValues(int $menuId, ?int $itemId, array $values): void
    {
        if (!$values) {
            $this->deleteWhere(['menuId' => $menuId, 'ownerKey' => $this->ownerKey($menuId, $itemId)]);
            return;
        }

        $content = $this->getEditorContent($menuId, $itemId);
        $fields = $this->layoutFields($content);
        if ($unknown = array_diff_key($values, $fields)) {
            throw new RuntimeException('Missing field layout fields: ' . implode(', ', array_keys($unknown)));
        }

        if ($this->snapshotValues($content) === $values && $content->id !== null) {
            return;
        }

        $content->setFieldValues($values);
        if (!$this->saveContent($content, $menuId, $itemId)) {
            throw new RuntimeException('The menu field values did not pass Craft validation.');
        }
    }

    public function copyValues(int $fromMenuId, ?int $fromItemId, int $toMenuId, ?int $toItemId): void
    {
        $source = $this->getContent($fromMenuId, $fromItemId);
        if (!$source) {
            return;
        }

        $target = $this->getEditorContent($toMenuId, $toItemId);
        if ($target->id !== null) {
            return;
        }
        if ($target::class !== $source::class || !$target->fieldLayoutId) {
            throw new RuntimeException('The destination field layout is incompatible.');
        }

        Craft::$app->getDb()->transaction(function() use ($source, $target, $toMenuId, $toItemId): void {
            $copy = Craft::$app->getElements()->duplicateElement($source, [
                'siteId' => $target->siteId,
                'fieldLayoutId' => $target->fieldLayoutId,
                'ownerMenuId' => $target->ownerMenuId,
            ], placeInStructure: false);
            $this->attachContent($copy, $toMenuId, $toItemId);
        });
    }

    /**
     * @return array{values: array, warnings: string[]}
     */
    public function exportPortableValues(int $menuId, ?int $itemId = null): array
    {
        $content = $this->getContent($menuId, $itemId);
        if (!$content) {
            return ['values' => [], 'warnings' => []];
        }

        return $this->portableValues($this->layoutFields($content), $this->snapshotValues($content));
    }

    /** @return array{values: array, warnings: string[]} */
    public function reviewPortableValues(string $scope, array $values): array
    {
        if (!in_array($scope, [FieldLayoutsService::MENU, FieldLayoutsService::NODE], true)) {
            throw new InvalidArgumentException('Unknown field layout scope.');
        }

        $fields = [];
        foreach (FieldLayoutsService::layout($scope)->getCustomFields() as $field) {
            $fields[$field->handle] = $field;
        }

        return $this->portableValues($fields, $values);
    }

    /** @return string[] Warnings for values that could not be matched to portable fields. */
    public function importPortableValues(int $menuId, ?int $itemId, array $values): array
    {
        if (!$values) {
            return [];
        }

        $content = $this->getEditorContent($menuId, $itemId);
        $scope = $itemId === null ? FieldLayoutsService::MENU : FieldLayoutsService::NODE;
        ['values' => $portable, 'warnings' => $warnings] = $this->reviewPortableValues($scope, $values);
        if ($portable) {
            $content->setFieldValues($portable);
            if (!$this->saveContent($content, $menuId, $itemId)) {
                throw new RuntimeException('The imported menu field values did not pass Craft validation.');
            }
        }

        return $warnings;
    }

    public function deleteForMenu(int $menuId): void
    {
        $this->deleteWhere(['menuId' => $menuId]);
    }

    public function deleteForItem(int $itemId): void
    {
        $this->deleteWhere(['itemId' => $itemId]);
    }

    public function deleteAll(): void
    {
        if (Craft::$app->getDb()->tableExists(self::TABLE)) {
            $this->deleteWhere([]);
        }
    }

    private function snapshotValues(ElementInterface $content): array
    {
        $values = [];
        foreach ($this->layoutFields($content) as $handle => $field) {
            $value = $content->getFieldValue($handle);
            if (!$field instanceof Matrix) {
                $values[$handle] = $field->serializeValue($value, $content);
                continue;
            }

            $entries = $value instanceof EntryQuery
                ? (clone $value)->status(null)->drafts(false)->revisions(false)->all()
                : $value->all();
            $blocks = [];
            foreach ($entries as $entry) {
                $blocks[$entry->id ?? ('new' . count($blocks))] = [
                    'title' => $entry->title,
                    'slug' => $entry->slug,
                    'type' => $entry->getType()->handle,
                    'enabled' => $entry->enabled,
                    'collapsed' => $entry->collapsed,
                    'fields' => $this->snapshotValues($entry),
                ];
            }
            $values[$handle] = $blocks;
        }

        return $values;
    }

    private function portableValues(array $fields, array $values): array
    {
        $portable = [];
        $warnings = [];
        foreach ($values as $handle => $value) {
            if (!is_string($handle) || !preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,127}\z/', $handle)) {
                $warnings[] = Craft::t('olivemenus', 'Skipped a value with an invalid field handle.');
                continue;
            }

            $field = $fields[$handle] ?? null;
            if (!$field || !in_array($field::class, self::PORTABLE_TYPES, true)) {
                if ($value !== null && $value !== '' && $value !== []) {
                    $warnings[] = Craft::t('olivemenus', 'Skipped field “{field}” because it has no matching portable field type.', [
                        'field' => $handle,
                    ]);
                }
                continue;
            }

            if (!$this->acceptsPortableValue($field::class, $value)) {
                $warnings[] = Craft::t('olivemenus', 'Skipped field “{field}” because its value has an incompatible format.', [
                    'field' => $handle,
                ]);
                continue;
            }

            $portable[$handle] = $value;
        }

        return ['values' => $portable, 'warnings' => array_values(array_unique($warnings))];
    }

    private function acceptsPortableValue(string $type, mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return match ($type) {
            Checkboxes::class, MultiSelect::class => is_array($value) && array_is_list($value)
                && !array_filter($value, static fn (mixed $option): bool => !is_string($option)),
            Color::class => is_string($value) && (bool)preg_match('/\A#?(?:[a-f0-9]{3}|[a-f0-9]{6}|[a-f0-9]{8})\z/i', $value),
            Date::class => is_string($value) && DateTimeHelper::isIso8601($value),
            Lightswitch::class => is_bool($value),
            Number::class => (is_int($value) || is_float($value)) && is_finite((float)$value),
            Time::class => is_string($value) && (bool)preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\z/', $value),
            default => is_string($value),
        };
    }

    private function layoutFields(ElementInterface $content): array
    {
        $fields = [];
        foreach ($content->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $fields[$field->handle] = $field;
        }

        return $fields;
    }

    private function attachContent(NavigationFieldContent $content, int $menuId, ?int $itemId): void
    {
        Craft::$app->getDb()->createCommand()->insert(self::TABLE, [
            'elementId' => $content->id,
            'menuId' => $menuId,
            'itemId' => $itemId,
            'ownerKey' => $this->ownerKey($menuId, $itemId),
        ])->execute();
    }

    private function elementId(int $menuId, ?int $itemId): ?int
    {
        $elementId = (new Query())
            ->select('elementId')
            ->from(self::TABLE)
            ->where(['menuId' => $menuId, 'ownerKey' => $this->ownerKey($menuId, $itemId)])
            ->scalar();
        return $elementId === false ? null : (int)$elementId;
    }

    private function ownerKey(int $menuId, ?int $itemId): string
    {
        return $itemId === null ? "menu:$menuId" : "item:$itemId";
    }

    private function deleteWhere(array $condition): void
    {
        $elements = Craft::$app->getElements();
        $ids = (new Query())->select('elementId')->from(self::TABLE)->where($condition)->column();
        foreach ($ids as $id) {
            $element = $elements->getElementById((int)$id);
            if ($element && !$elements->deleteElement($element, true)) {
                throw new RuntimeException('The attached menu field content could not be deleted.');
            }
        }
    }
}
