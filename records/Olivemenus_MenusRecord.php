<?php
namespace Craft;

class Olivemenus_MenusRecord extends BaseRecord
{
    public function getTableName()
    {
        return 'olivemenus_menus';
    }

    protected function defineAttributes()
    {
        return array(
            'name' => [
                'type'      => AttributeType::String,
                'required'  => true
            ],
            'handle' => [
                'type'      => AttributeType::String,
                'required'  => true,
				'unique' 	=> true
            ]
        );
    }
}