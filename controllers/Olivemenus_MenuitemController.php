<?php
namespace Craft;

class Olivemenus_MenuitemController extends BaseController
{
	protected $allowAnonymous = true;

	public function actionSaveItems()
	{
        $this->requirePostRequest();

        $menu_id = craft()->request->getPost('menu-id');
        $menu_items_serialized = craft()->request->getPost('menu-items-serialized');
        $menu_items = json_decode($menu_items_serialized, true);

        $menu_items_filtered = array();

        $order = 0;

        foreach ( $menu_items as $menu_item )
        {
            $parent_id = 0;

            if ( isset($menu_item['parent-id']) )
            {
                $parent_id = $menu_item['parent-id'];

				foreach ( $menu_items as $element )
				{
                    if ( isset($element['item-id']['html']) && $element['item-id']['html'] == $parent_id )
					{
						$parent_id = $element['menu-item-db-id'];
						$menu_items[$order]['parent-id'] = $parent_id;
						break;
					}
				}
            }

            $menu_item_model = new Olivemenus_MenuItemModel();

            $entry_id = '';
            if ( isset($menu_item['entry-id']) ) $entry_id = $menu_item['entry-id'];

            $custom_url = '';
            if ( !isset($menu_item['entry-id']) && isset($menu_item['custom-url']) ) $custom_url = $menu_item['custom-url'];

            $class = '';
            if ( isset($menu_item['class']) ) $class = $menu_item['class'];

            $class_parent = '';
            if ( isset($menu_item['class-parent']) ) $class_parent = $menu_item['class-parent'];

            $data_json = '';
            if ( isset($menu_item['data-json']) ) $data_json = $menu_item['data-json'];

            $menu_item_model->id = null;
            if($menu_item['item-id']['db'] != null){
                $menu_item_model->id = $menu_item['item-id']['db'];
            }
            $menu_item_model->menu_id = $menu_id;
            $menu_item_model->parent_id = $parent_id;
            $menu_item_model->item_order = $order;
            $menu_item_model->name = $menu_item['name'];
            $menu_item_model->entry_id = $entry_id;
            $menu_item_model->custom_url = $custom_url;
            $menu_item_model->class = $class;
            $menu_item_model->class_parent = $class_parent;
            $menu_item_model->data_json = $data_json;

            $menu_item_db_id = craft()->olivemenus_menuitems->saveMenuItem($menu_item_model);
            if ( is_numeric($menu_item_db_id) ) $menu_items[$order]['menu-item-db-id'] = $menu_item_db_id;
            $order++;
        }

        $menu_items_deleted = craft()->request->getPost('menu-items-deleted');

        if ( $menu_items_deleted != '' )
        {
            $menu_items_deleted = explode(',', $menu_items_deleted);

            foreach ( $menu_items_deleted as $menu_item_deleted )
            {
                craft()->olivemenus_menuitems->deleteMenuItem($menu_item_deleted);
            }
        }

		craft()->userSession->setFlash('notice', 'Menu items saved successfully.');
	}

}
