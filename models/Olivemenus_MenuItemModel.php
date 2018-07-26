<?php
namespace Craft;

class Olivemenus_MenuItemModel extends BaseModel
{
    protected function defineAttributes()
    {
        return array(
			'id' => AttributeType::Number,
            'menu_id' => [
                'type'      => AttributeType::Number,
                'required'  => true
            ],			
            'parent_id' => [
                'type'      => AttributeType::Number,
                'required'  => true
            ],
			'item_order' => [
                'type'      => AttributeType::Number,
                'required'  => true
            ],
            'name' => [
                'type'      => AttributeType::String,
                'required'  => true
            ],
            'entry_id' => [
                'type'      => AttributeType::String,
            ],
            'custom_url' => [
                'type'      => AttributeType::String,
            ],
            'class' => [
                'type'      => AttributeType::String,
            ],
            'class_parent' => [
                'type'      => AttributeType::String,
            ],
            'data_json' => [
                'type'      => AttributeType::String,
            ]
        );
    }
}