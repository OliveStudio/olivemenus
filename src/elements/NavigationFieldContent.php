<?php

namespace olivestudio\olivemenus\elements;

use Craft;
use craft\base\Element;
use craft\elements\User;
use olivestudio\olivemenus\services\FieldContentService;
use olivestudio\olivemenus\services\MenuRevisions;

abstract class NavigationFieldContent extends Element
{
    public ?int $ownerMenuId = null;

    public static function isLocalized(): bool
    {
        return true;
    }

    public function getSupportedSites(): array
    {
        return $this->siteId ? [$this->siteId] : parent::getSupportedSites();
    }

    public function canView(User $user): bool
    {
        $site = $this->getSite();
        return $user->can('accessCp')
            && $user->can('accessPlugin-olivemenus')
            && $site !== null
            && (!Craft::$app->getIsMultiSite() || $user->can("editSite:$site->uid"));
    }

    public function canSave(User $user): bool
    {
        if (!$this->canView($user)) {
            return false;
        }

        $menuId = $this->ownerMenuId ?? (new FieldContentService())->getOwnerMenuId($this->id);
        return $menuId !== null
            && (!class_exists(MenuRevisions::class) || !(new MenuRevisions())->hasDraft($menuId));
    }
}
