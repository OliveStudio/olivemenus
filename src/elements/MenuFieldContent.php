<?php

namespace olivestudio\olivemenus\elements;

use Craft;

final class MenuFieldContent extends NavigationFieldContent
{
    public static function displayName(): string
    {
        return Craft::t('olivemenus', 'Menu field content');
    }
}
