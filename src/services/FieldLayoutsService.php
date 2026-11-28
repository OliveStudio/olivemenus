<?php

namespace olivestudio\olivemenus\services;

use Craft;
use craft\events\ConfigEvent;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\services\Elements;
use craft\services\ProjectConfig;
use olivestudio\olivemenus\elements\MenuFieldContent;
use olivestudio\olivemenus\elements\NodeFieldContent;
use RuntimeException;
use yii\base\Event;

final class FieldLayoutsService
{
    public const MENU = 'menu';
    public const NODE = 'node';

    private const CONFIG_ROOT = 'olivemenus.fieldLayouts';

    private const TYPES = [
        self::MENU => MenuFieldContent::class,
        self::NODE => NodeFieldContent::class,
    ];

    public static function register(): void
    {
        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, static function(RegisterComponentTypesEvent $event): void {
            array_push($event->types, ...array_values(self::TYPES));
        });

        $projectConfig = Craft::$app->getProjectConfig();
        foreach (self::TYPES as $scope => $type) {
            $path = self::CONFIG_ROOT . '.' . $scope;
            $projectConfig
                ->onAdd($path, static fn (ConfigEvent $event) => self::apply($type, $event->newValue))
                ->onUpdate($path, static fn (ConfigEvent $event) => self::apply($type, $event->newValue))
                ->onRemove($path, static function() use ($type): void {
                    $fields = Craft::$app->getFields();
                    $layout = $fields->getLayoutByType($type, false);
                    if ($layout?->id) {
                        $fields->deleteLayoutById($layout->id);
                    }
                });
        }

        Event::on(ProjectConfig::class, ProjectConfig::EVENT_REBUILD, static function(RebuildConfigEvent $event): void {
            foreach (self::TYPES as $scope => $type) {
                $layout = Craft::$app->getFields()->getLayoutByType($type, false);
                if ($layout?->id) {
                    $event->config['olivemenus']['fieldLayouts'][$scope] = self::definition($layout);
                }
            }
        });
    }

    public static function layout(string $scope): FieldLayout
    {
        return Craft::$app->getFields()->getLayoutByType(self::TYPES[$scope]);
    }

    public static function hasLayout(string $scope): bool
    {
        return self::layout($scope)->getCustomFields() !== [];
    }

    public static function save(string $scope, FieldLayout $layout): bool
    {
        $layout->type = self::TYPES[$scope];
        $layout->uid = self::layout($scope)->uid ?? StringHelper::UUID();
        if (!$layout->validate()) {
            return false;
        }

        Craft::$app->getProjectConfig()->set(self::CONFIG_ROOT . '.' . $scope, self::definition($layout));
        return true;
    }

    public static function remove(): void
    {
        Craft::$app->getProjectConfig()->remove(self::CONFIG_ROOT);
    }

    private static function definition(FieldLayout $layout): array
    {
        return [
            'uid' => $layout->uid,
            'layout' => $layout->getConfig() ?? [],
        ];
    }

    private static function apply(string $type, array $definition): void
    {
        Craft::$app->getProjectConfig()->processConfigChanges(ProjectConfig::PATH_FIELDS);
        $fields = Craft::$app->getFields();
        $layout = FieldLayout::createFromConfig($definition['layout']);
        $layout->type = $type;
        $layout->uid = $definition['uid'];
        $layout->id = $fields->getLayoutByType($type, false)?->id;
        if (!$fields->saveLayout($layout, false)) {
            throw new RuntimeException('The Olive Menus field layout could not be applied.');
        }
    }
}
