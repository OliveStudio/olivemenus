<?php

namespace olivestudio\olivemenus\gql;

use Craft;
use craft\elements\db\EntryQuery;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Gql as GqlHelper;
use craft\models\Section;
use craft\services\Gql;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use olivestudio\olivemenus\Olivemenus;
use yii\base\Event;

final class MenuQuery
{
    public const CACHE_TAG = 'olivemenus';
    private const SCOPE = 'olivemenus';

    public static function register(): void
    {
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS,
            static function(RegisterGqlSchemaComponentsEvent $event): void {
                $event->queries[Craft::t('olivemenus', 'Olive Menus')][self::SCOPE . ':read'] = [
                    'label' => Craft::t('olivemenus', 'Read navigation menus'),
                ];
            },
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            static function(RegisterGqlQueriesEvent $event): void {
                if (!GqlHelper::canSchema(self::SCOPE)) {
                    return;
                }

                $event->queries['oliveMenu'] = [
                    'type' => self::menuType(),
                    'description' => 'Find an Olive Menus menu by its handle and site.',
                    'args' => [
                        'handle' => [
                            'type' => Type::nonNull(Type::string()),
                        ],
                        'site' => [
                            'type' => Type::string(),
                            'description' => 'Craft site handle. Defaults to the current site.',
                        ],
                    ],
                    'complexity' => static fn (int $childComplexity): int => $childComplexity + 10,
                    'resolve' => self::resolve(...),
                ];
            },
        );
    }

    private static function resolve(mixed $source, array $arguments): ?array
    {
        if (!GqlHelper::canSchema(self::SCOPE)) {
            return null;
        }

        $sites = Craft::$app->getSites();
        $site = isset($arguments['site'])
            ? $sites->getSiteByHandle($arguments['site'], false)
            : $sites->getCurrentSite();

        if (!$site || !$site->getEnabled() || !GqlHelper::canSchema('sites.' . $site->uid)) {
            return null;
        }

        Craft::$app->getElements()->collectCacheTags([self::CACHE_TAG]);

        $menus = Olivemenus::getInstance()->olivemenus;
        $menu = $menus->getMenuByHandle($arguments['handle'], $site->id);

        if (!$menu) {
            return null;
        }

        return [
            'id' => $menu->id,
            'name' => $menu->name,
            'handle' => $menu->handle,
            'site' => $site->handle,
            'items' => $menus->getMenuDataById($menu->id, $site->id, self::filterEntrySource(...), forGraphql: true),
        ];
    }

    private static function filterEntrySource(Section $section, EntryQuery $query): bool
    {
        if (!GqlHelper::canSchema("sections.$section->uid")) {
            return false;
        }

        $scopes = GqlHelper::extractAllowedEntitiesFromSchema();
        if (isset($scopes['entrytypes'])) {
            $typeIds = [];
            foreach ($section->getEntryTypes() as $type) {
                if (in_array($type->uid, $scopes['entrytypes'], true)) {
                    $typeIds[] = $type->id;
                }
            }
            if (!$typeIds) {
                return false;
            }
            $query->typeId($typeIds);
        }

        // Craft 5.12 added authenticated schema users. Match core’s authorization filter.
        if (method_exists(GqlHelper::class, 'getSchemaUser') && GqlHelper::getSchemaUser() !== null) {
            $query->editable();
        }

        return true;
    }

    private static function menuType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('OliveMenu', static fn (): ObjectType => new ObjectType([
            'name' => 'OliveMenu',
            'fields' => static fn (): array => Craft::$app->getGql()->prepareFieldDefinitions([
                'id' => Type::nonNull(Type::id()),
                'name' => Type::nonNull(Type::string()),
                'handle' => Type::nonNull(Type::string()),
                'site' => Type::nonNull(Type::string()),
                'items' => Type::nonNull(Type::listOf(Type::nonNull(self::itemType()))),
            ], 'OliveMenu'),
        ]));
    }

    private static function itemType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('OliveMenuItem', static fn (): ObjectType => new ObjectType([
            'name' => 'OliveMenuItem',
            'fields' => static fn (): array => Craft::$app->getGql()->prepareFieldDefinitions([
                'id' => Type::nonNull(Type::id()),
                'name' => Type::nonNull(Type::string()),
                'url' => [
                    'type' => Type::string(),
                    'resolve' => static fn (array $item): ?string => $item['url'] === '' ? null : $item['url'],
                ],
                'kind' => Type::nonNull(Type::string()),
                'megaMenu' => self::megaMenuType(),
                'isDynamic' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'resolve' => static fn (array $item): bool => $item['isDynamic'] ?? false,
                ],
                'target' => Type::nonNull(Type::string()),
                'linkClass' => [
                    'type' => Type::string(),
                    'resolve' => static fn (array $item): ?string => $item['class'] ?? null,
                ],
                'itemClass' => [
                    'type' => Type::string(),
                    'resolve' => static fn (array $item): ?string => $item['class_parent'] ?? null,
                ],
                'attributes' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(self::attributeType()))),
                    'resolve' => static function(array $item): array {
                        $attributes = [];

                        foreach ($item['dataAttributes'] as $name => $value) {
                            $attributes[] = ['name' => $name, 'value' => $value];
                        }

                        return $attributes;
                    },
                ],
                'children' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(self::itemType()))),
                    'resolve' => static fn (array $item): array => $item['children'] ?? [],
                ],
            ], 'OliveMenuItem'),
        ]));
    }

    private static function megaMenuType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('OliveMegaMenu', static fn (): ObjectType => new ObjectType([
            'name' => 'OliveMegaMenu',
            'fields' => [
                'columns' => Type::nonNull(Type::int()),
                'description' => Type::nonNull(Type::string()),
            ],
        ]));
    }

    private static function attributeType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('OliveMenuAttribute', static fn (): ObjectType => new ObjectType([
            'name' => 'OliveMenuAttribute',
            'fields' => static fn (): array => Craft::$app->getGql()->prepareFieldDefinitions([
                'name' => Type::nonNull(Type::string()),
                'value' => Type::nonNull(Type::string()),
            ], 'OliveMenuAttribute'),
        ]));
    }
}
