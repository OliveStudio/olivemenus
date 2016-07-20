<?php
namespace Craft;

class Olivemenus_MenusModel extends BaseModel
{
    protected function defineAttributes()
    {
        return array(
			'id' => AttributeType::Number,
            'name' => [
                'type'      => AttributeType::String,
                'required'  => true
            ],
            'handle' => [
                'type'      => AttributeType::String,
                'required'  => true
            ]
        );
    }
}