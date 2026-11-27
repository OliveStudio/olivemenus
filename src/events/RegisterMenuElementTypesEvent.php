<?php

namespace olivestudio\olivemenus\events;

use craft\base\ElementInterface;
use yii\base\Event;

class RegisterMenuElementTypesEvent extends Event
{
    /**
     * @var array<class-string<ElementInterface>, string> Element classes and their control panel labels.
     */
    public array $types = [];
}
