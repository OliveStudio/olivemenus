<?php

namespace olivestudio\olivemenus\services;

use Craft;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\web\Request;
use DateTime;
use olivestudio\olivemenus\models\MenuItemPresentation;
use olivestudio\olivemenus\models\OlivemenusItemsModel;

final class MenuItemPresentationFilter
{
    public static function apply(array $items, int $siteId, bool $forGraphql): array
    {
        $siteUid = Craft::$app->getSites()->getSiteById($siteId)?->uid;
        $webRequest = Craft::$app->getRequest() instanceof Request;
        $sharedTemplate = !$forGraphql && Craft::$app->getElements()->getIsCollectingCacheInfo();
        $identity = static function() use ($webRequest, $forGraphql, $sharedTemplate): ?User {
            if ($webRequest && !$forGraphql && !$sharedTemplate) {
                return Craft::$app->getUser()->getIdentity();
            }
            return null;
        };
        $noStore = false;
        $items = self::filter($items, $siteUid, $identity, $sharedTemplate, $noStore);
        if ($noStore && $webRequest) {
            Craft::$app->getResponse()->setNoCacheHeaders();
        }
        return $items;
    }

    private static function filter(
        array $items,
        ?string $siteUid,
        callable $identity,
        bool $sharedTemplate,
        bool &$noStore,
    ): array {
        $visible = [];
        foreach ($items as $item) {
            if (!($item['enabled'] ?? true)) {
                continue;
            }

            $settings = MenuItemPresentation::read($item['presentation'] ?? null);
            if ($settings['siteUids'] && !in_array($siteUid, $settings['siteUids'], true)) {
                continue;
            }

            $audience = $settings['audience'];
            $start = $settings['startsAt'];
            $end = $settings['endsAt'];
            if ($audience !== 'everyone' || $start !== null || $end !== null) {
                $noStore = true;
            }
            $now = DateTimeHelper::currentTimeStamp();
            foreach ([$start, $end] as $boundary) {
                if ($boundary !== null && $boundary > $now) {
                    Craft::$app->getElements()->setCacheExpiryDate(new DateTime('@' . $boundary));
                }
            }
            if (($start !== null && $now < $start) || ($end !== null && $now >= $end)) {
                continue;
            }
            if ($audience !== 'everyone' && ($sharedTemplate || !self::allows($audience, $settings['groupUids'], $identity()))) {
                continue;
            }

            if (!empty($item['children'])) {
                if (($item['kind'] ?? null) === OlivemenusItemsModel::KIND_GROUP && !empty($item['source_section_uid'])) {
                    // A manually restricted link must not reappear through the group's automatic source.
                    $item['manualGroupEntryIds'] = [];
                    foreach ($item['children'] as $child) {
                        if (!empty($child['entry_id']) && empty($child['custom_url'])) {
                            $item['manualGroupEntryIds'][] = (int)$child['entry_id'];
                        }
                    }
                }
                $item['children'] = self::filter($item['children'], $siteUid, $identity, $sharedTemplate, $noStore);
            }
            if ($settings['columns'] && ($item['kind'] ?? null) === OlivemenusItemsModel::KIND_GROUP) {
                $item['megaMenu'] = [
                    'columns' => $settings['columns'],
                    'description' => $settings['description'],
                ];
            }
            unset($item['presentation']);
            $visible[] = $item;
        }
        return $visible;
    }

    private static function allows(string $audience, array $groups, ?User $identity): bool
    {
        return match ($audience) {
            'guests' => $identity === null,
            'signed-in' => $identity !== null,
            'groups' => $identity !== null && (bool)array_intersect(
                $groups,
                array_column($identity->getGroups(), 'uid'),
            ),
            default => false,
        };
    }
}
