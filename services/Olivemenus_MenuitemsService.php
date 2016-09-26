<?php
namespace Craft;

class Olivemenus_MenuitemsService extends BaseApplicationComponent
{
	public function saveMenuItem(Olivemenus_MenuItemModel $menu_item)
	{            
        $menu_item_record = $this->getMenuItemByID($menu_item->id);
        if ( !$menu_item_record ) $menu_item_record = new Olivemenus_MenuItemRecord();       
        
        $menu_item_record->menu_id = $menu_item->menu_id;
        $menu_item_record->parent_id = $menu_item->parent_id;
        $menu_item_record->item_order = $menu_item->item_order;
        $menu_item_record->name = $menu_item->name;
        $menu_item_record->entry_id = $menu_item->entry_id;
        $menu_item_record->custom_url = $menu_item->custom_url;
        $menu_item_record->class = $menu_item->class;
        $menu_item_record->class_parent = $menu_item->class_parent;
        $menu_item_record->data_json = $menu_item->data_json;
        
		$menu_item_record->validate();
		$menu_item->addErrors($menu_item_record->getErrors());	
		
		if ( !$menu_item->hasErrors() ) 
        {
            $menu_item_record->save(false);
            return $menu_item_record->getPrimaryKey();
        }
		else return false;	
	}
    
    public function deleteMenuItem($menu_item_id)
    {
        if ( $menu_item_id == '' ) return false;
        else return craft()->db->createCommand()->delete('olivemenus_menus_items', array('id' => $menu_item_id));
    }
    
	public function getMenuItemByID($menu_item_id = null)
	{
		if ( $menu_item_id != null )
		{
			$menu_record = Olivemenus_MenuItemRecord::model()->findById($menu_item_id);
			
			if ( $menu_record ) return $menu_record;
			else return null;
		}
		else return null;
	}    
    
    public function getMenuItems($menu_id)
    {
        $menu_items_array = array();
		$menu_items = Olivemenus_MenuItemRecord::model()->findAllByAttributes( array( 'menu_id' => $menu_id ), array('order' => 'item_order ASC') );
        
        $item_index = 0;
        foreach ( $menu_items as $menu_item )
        {
            $menu_items_array[$item_index]['id'] = $menu_item->id;
            $menu_items_array[$item_index]['menu_id'] = $menu_item->menu_id;
            $menu_items_array[$item_index]['parent_id'] = $menu_item->parent_id;
            $menu_items_array[$item_index]['item_order'] = $menu_item->item_order;
            $menu_items_array[$item_index]['name'] = $menu_item->name;
            $menu_items_array[$item_index]['entry_id'] = $menu_item->entry_id;
            $menu_items_array[$item_index]['custom_url'] = $menu_item->custom_url;
            $menu_items_array[$item_index]['class'] = $menu_item->class;
            $menu_items_array[$item_index]['class_parent'] = $menu_item->class_parent;
            $menu_items_array[$item_index]['data_json'] = $menu_item->data_json;
            
            $item_index++;
        }
        
        $menu_items = $this->sortMenuItemsByParents($menu_items_array);
        
		return $menu_items;        
    }
    
    public function sortMenuItemsByParents($menu_items)
    {
        $counter = 0;
        $menu_items_sorted = array();
        
        foreach ( $menu_items as $menu_item )
        {
            if ( $menu_item['parent_id'] == 0 ) 
            {
                $menu_items_sorted[] = $menu_item;
            }
        }        
        
        foreach ( $menu_items_sorted as $menu_item )
        {
            $menu_items_sorted[$counter] = $this->addChildToParent($menu_items,$menu_item);
            $counter++;
        }
        
        return $menu_items_sorted;
    }
    
    public function addChildToParent($menu_items, $menu_item)
    {
        $parent_id = $menu_item['id'];
        foreach ( $menu_items as $menu_sub_item )
        {
            if ( $menu_sub_item['parent_id'] == $parent_id )
            {
                $menu_sub_item = $this->addChildToParent($menu_items,$menu_sub_item);
                $menu_item['children'][] = $menu_sub_item;
            }
        }
        
        return $menu_item;
    }
    
    public function getMenuItemsAdminMarkup($menu_id)
    {
        $localHTML = '';
        $menu_items_array = $this->getMenuItems($menu_id);
        
        foreach ( $menu_items_array as $menu_item )
        {
            $localHTML .= $this->getItemAdminMarkup($menu_item);
        }

        return $localHTML;
    }
    
    public function getItemAdminMarkup($menu_item)
    {
        $localHTML = '';
        
        $criteria = craft()->elements->getCriteria(ElementType::Entry);
        $criteria->id = $menu_item['entry_id'];
        $entry = $criteria->first();
   
        $localHTML .= '<li id="menu-item-' .$menu_item['id']. '">';
            $localHTML .= '<div>';
                $localHTML .= '<div class="item-heading">';
                    $localHTML .= '<span class="settings-toggle"></span>';
                    $localHTML .= '<span class="menu-title">' . $menu_item['name'] . '</span>';
                    $localHTML .= '<span class="delete-menu btn small" data-id="' .$menu_item['id']. '">Delete</span>';
                $localHTML .= '</div>';
                $localHTML .= '<div class="item-content">';
                $localHTML .= '<input type="hidden" name="item-id" value="' .$menu_item['id']. '" />';
                    if ( $menu_item['custom_url'] == '' ) $localHTML .= '<input type="hidden" name="item-entry-id" value="' .$menu_item['entry_id']. '" />';                
                    $localHTML .= '<div class="inner">';
                        $localHTML .= '<div class="row field">';
                            $localHTML .= '<div class="heading">';
                                $localHTML .= '<label>Name:</label>';
                            $localHTML .= '</div>';
                            $localHTML .= '<div class="input">';
                                $localHTML .= '<input class="text nicetext fullwidth" type="text" name="item-name" value="' .$menu_item['name']. '" />';
                            $localHTML .= '</div>';
						$localHTML .= '</div>';
                        if ( $menu_item['custom_url'] != '' )
                        {
                            $localHTML .= '<div class="row field">';
                                $localHTML .= '<div class="heading">';
                                    $localHTML .= '<label>Custom URL:</label>';
                                $localHTML .= '</div>';
                                $localHTML .= '<div class="input">';
                                    $localHTML .= '<input class="text nicetext fullwidth" type="text" name="custom-url" value="' .$menu_item['custom_url']. '" />';
                                $localHTML .= '</div>';
                            $localHTML .= '</div>';
                        }						
						$localHTML .= '<div class="row field">';
                            $localHTML .= '<div class="heading">';
                                $localHTML .= '<label>Class:</label>';
                            $localHTML .= '</div>';
                            $localHTML .= '<div class="input">';
                                $localHTML .= '<input class="text nicetext fullwidth" type="text" name="class" value="' .$menu_item['class']. '" />';
                            $localHTML .= '</div>';
						$localHTML .= '</div>';
						$localHTML .= '<div class="row field">';							
                            $localHTML .= '<div class="heading">';
                                $localHTML .= '<label>Class parent:</label>';
                            $localHTML .= '</div>';
                            $localHTML .= '<div class="input">';
                                $localHTML .= '<input class="text nicetext fullwidth" type="text" name="class-parent" value="' .$menu_item['class_parent']. '" />';
                            $localHTML .= '</div>';
						$localHTML .= '</div>';
						$localHTML .= '<div class="row field">';							
                            $localHTML .= '<div class="heading">';
                                $localHTML .= '<label>Data JSON:</label>';
                            $localHTML .= '</div>';
                            $localHTML .= '<div class="input">';
                                $localHTML .= '<textarea class="text nicetext fullwidth" name="data-json">' .$menu_item['data_json']. '</textarea>';
                            $localHTML .= '</div>';							
                        $localHTML .= '</div>';
                        if ( $menu_item['custom_url'] == '' )
                        {
                            $localHTML .= '<div class="row field">';
                                $localHTML .= '<div class="heading">';
                                    if ( $entry ) $localHTML .= '<label>Original:</label> <a href="' .$entry->url. '" target="_blank">' .$entry->title. '</a>';
                                $localHTML .= '</div>';
                            $localHTML .= '</div>';                        
                        }
                    $localHTML .= '</div>';
                $localHTML .= '</div>';
            $localHTML .= '</div>';
            if ( isset($menu_item['children']) )
            {
                $localHTML .= '<ol>';
                    foreach ( $menu_item['children'] as $child )
                    {
                       $localHTML .= $this->getItemAdminMarkup($child); 
                    }
                $localHTML .= '</ol>';
            }
        $localHTML .= '</li>'; 
        
        return $localHTML;
    }
}