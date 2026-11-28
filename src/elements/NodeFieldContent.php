<?php

namespace olivestudio\olivemenus\elements;

use Craft;

final class NodeFieldContent extends NavigationFieldContent
{
    public static function displayName(): string
    {
        return Craft::t('olivemenus', 'Node field content');
    }
}
