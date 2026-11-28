<?php

namespace olivestudio\olivemenus\models;

use Craft;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

final class MenuItemPresentation
{
    public const AUDIENCES = ['everyone', 'guests', 'signed-in', 'groups'];
    private const COLUMNS = [0, 2, 3, 4];
    private const DATE_FORMAT = 'Y-m-d\TH:i';
    private const DEFAULTS = [
        'audience' => 'everyone',
        'groupUids' => [],
        'siteUids' => [],
        'startsAt' => null,
        'endsAt' => null,
        'columns' => 0,
        'description' => '',
    ];

    public static function read(?string $stored): array
    {
        if ($stored === null || $stored === '') {
            return self::DEFAULTS;
        }

        try {
            $decoded = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['audience' => 'invalid'] + self::DEFAULTS;
        }

        if (!is_array($decoded) || array_diff_key($decoded, self::DEFAULTS)) {
            return ['audience' => 'invalid'] + self::DEFAULTS;
        }

        $settings = $decoded + self::DEFAULTS;
        if (
            !in_array($settings['audience'], self::AUDIENCES, true)
            || !self::validUidList($settings['groupUids'])
            || !self::validUidList($settings['siteUids'])
            || ($settings['startsAt'] !== null && !is_int($settings['startsAt']))
            || ($settings['endsAt'] !== null && !is_int($settings['endsAt']))
            || ($settings['startsAt'] !== null && $settings['endsAt'] !== null && $settings['startsAt'] >= $settings['endsAt'])
            || !in_array($settings['columns'], self::COLUMNS, true)
            || !is_string($settings['description'])
            || mb_strlen($settings['description']) > 255
        ) {
            return ['audience' => 'invalid'] + self::DEFAULTS;
        }

        return $settings;
    }

    public static function fromSubmission(mixed $input, string $kind, ?string $existing = null): ?string
    {
        if ($input === null) {
            return $existing;
        }
        if (!is_array($input)) {
            throw new InvalidArgumentException(Craft::t('olivemenus', 'Check the item visibility and layout settings.'));
        }

        $stored = self::read($existing);
        $audience = $input['audience'] ?? 'everyone';
        if (!in_array($audience, self::AUDIENCES, true)) {
            throw new InvalidArgumentException(Craft::t('olivemenus', 'Choose who can see this item.'));
        }

        $groupUids = $audience === 'groups'
            ? self::validateUidList($input['groupUids'] ?? [], self::groupOptions(), $stored['groupUids'])
            : [];
        if ($audience === 'groups' && $groupUids === []) {
            throw new InvalidArgumentException(Craft::t('olivemenus', 'Select at least one user group.'));
        }

        $siteUids = self::validateUidList($input['siteUids'] ?? [], self::siteOptions(), $stored['siteUids']);
        $startsAt = self::parseDate($input['startsAt'] ?? null, $stored['startsAt']);
        $endsAt = self::parseDate($input['endsAt'] ?? null, $stored['endsAt']);
        if ($startsAt !== null && $endsAt !== null && $startsAt >= $endsAt) {
            throw new InvalidArgumentException(Craft::t('olivemenus', 'Hide after must be later than Show from.'));
        }

        $selectedColumns = $kind === OlivemenusItemsModel::KIND_GROUP ? ($input['columns'] ?? 0) : 0;
        $columns = filter_var($selectedColumns, FILTER_VALIDATE_INT);
        if (!in_array($columns, self::COLUMNS, true)) {
            throw new InvalidArgumentException(Craft::t('olivemenus', 'Choose a menu layout from the list.'));
        }

        $description = $columns !== 0 ? ($input['description'] ?? '') : '';
        if (!is_string($description) || mb_strlen($description) > 255) {
            throw new InvalidArgumentException(Craft::t('olivemenus', 'Use 255 characters or fewer for the mega menu description.'));
        }

        $settings = [
            'audience' => $audience,
            'groupUids' => $groupUids,
            'siteUids' => $siteUids,
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'columns' => $columns,
            'description' => trim($description),
        ];

        $changed = array_filter(
            $settings,
            static fn (mixed $value, string $key): bool => $value !== self::DEFAULTS[$key],
            ARRAY_FILTER_USE_BOTH,
        );
        return $changed ? json_encode($changed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null;
    }

    public static function forEditor(?string $stored): array
    {
        $settings = self::read($stored);
        $settings['startsAt'] = self::formatDate($settings['startsAt']);
        $settings['endsAt'] = self::formatDate($settings['endsAt']);
        return $settings;
    }

    public static function groupOptions(): array
    {
        $options = [];
        foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
            $options[$group->uid] = $group->name;
        }
        return $options;
    }

    public static function siteOptions(): array
    {
        $options = [];
        foreach (Craft::$app->getSites()->getAllSites(false) as $site) {
            $options[$site->uid] = $site->getName();
        }
        return $options;
    }

    private static function validateUidList(mixed $uids, array $options, array $existing): array
    {
        if (!self::validUidList($uids)) {
            throw new InvalidArgumentException(Craft::t('olivemenus', 'Choose user groups and sites from the available options.'));
        }
        $valid = array_merge(array_keys($options), $existing);
        if (array_diff($uids, $valid)) {
            throw new InvalidArgumentException(Craft::t('olivemenus', 'Choose user groups and sites from the available options.'));
        }
        $uids = array_values(array_unique($uids));
        sort($uids);
        return $uids;
    }

    private static function validUidList(mixed $uids): bool
    {
        if (!is_array($uids) || !array_is_list($uids)) {
            return false;
        }
        foreach ($uids as $uid) {
            if (!is_string($uid) || !preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $uid)) {
                return false;
            }
        }
        return true;
    }

    private static function parseDate(mixed $value, ?int $current): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $zone = new DateTimeZone(Craft::$app->getTimeZone());
        $date = is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/', $value)
            ? DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value, $zone)
            : false;
        if (!$date || $date->format(self::DATE_FORMAT) !== $value) {
            throw new InvalidArgumentException(Craft::t('olivemenus', 'Enter a valid date and time in the Craft system time zone.'));
        }

        if ($current !== null && self::formatDate($current) === $value) {
            return $current;
        }

        $timestamp = $date->getTimestamp();
        $wallTime = $timestamp + $date->getOffset();
        $offsets = array_column($zone->getTransitions($timestamp - 86400, $timestamp + 86400) ?: [], 'offset');
        foreach (array_unique($offsets) as $offset) {
            $candidate = $wallTime - $offset;
            if ($candidate !== $timestamp && self::formatDate($candidate) === $value) {
                throw new InvalidArgumentException(Craft::t('olivemenus', 'Choose a time outside the repeated hour when clocks change.'));
            }
        }

        return $timestamp;
    }

    private static function formatDate(?int $timestamp): string
    {
        return $timestamp === null ? '' : (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()))
            ->format(self::DATE_FORMAT);
    }
}
