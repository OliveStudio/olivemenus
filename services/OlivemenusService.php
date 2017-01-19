<?php
namespace Craft;

class OlivemenusService extends BaseApplicationComponent
{
	
	public function getMenus()
	{
		$menu_records = Olivemenus_MenusRecord::model()->findAll();
		return $menu_records;
	}
	
	public function getMenuByID($menu_ID = null)
	{
		if ( $menu_ID != null )
		{
			$menu_record = Olivemenus_MenusRecord::model()->findById($menu_ID);
			
			if ( $menu_record ) return $menu_record;
			else return null;
		}
		else return null;
	}
    
	public function getMenuByHandle($menu_handle = null)
	{
        if ( $menu_handle != null )
		{
			$menu_record = Olivemenus_MenusRecord::model()->findByAttributes( array( 'handle' => $menu_handle ) );
			
			if ( $menu_record ) return $menu_record;
			else return null;
		}
		else return null;
	}    
	
    public function saveMenu(Olivemenus_MenusModel $menu)
    {
		if ( $menu->id )  
		{
			$menu_record = craft()->olivemenus->getMenuByID($menu->id);
			$original_name = $menu->name;
		}
		else $menu_record = new Olivemenus_MenusRecord();
			
		$menu_record->name = $menu->name;
		$menu_record->handle = $menu->handle;		

		$menu_record->validate();
		$menu->addErrors($menu_record->getErrors());	
		
		if ( !$menu->hasErrors() ) $menu_record->save(false);
		else return false;		
	
    }
		
	public function deleteMenu($menu_ID = null)
	{
		if ( $menu_ID != null ) 
		{
			$delete_menu = craft()->db->createCommand()->delete('olivemenus_menus', array('id' => $menu_ID));
			$delete_menu_items = craft()->db->createCommand()->delete('olivemenus_menus_items', array('menu_id' => $menu_ID));
			
			if ( $delete_menu || $delete_menu_items) return true;
			else return false;
		}
		else return false;
	}
	
	public function getSections ()
	{
		
		$sections = [];
		
		$sections['single'] = craft()->db->createCommand()
			->select('name as name, handle as handle')
			->from('sections')
			->order('name')
			->where('type = "single"')
			->queryAll();
					
		$sections['structure'] = craft()->db->createCommand()
			->select('name as name, handle as handle')
			->from('sections')
			->order('name')
			->where('type = "structure"')
			->queryAll();
					 
		$sections['channel'] = craft()->db->createCommand()
			->select('name as name, handle as handle')
			->from('sections')
			->order('name')
			->where('type = "channel"')
			->queryAll();
		
		return $sections;
		
	}
	
	
	public function getEntriesBySection($handle)
	{
		return craft()->elements->getCriteria(ElementType::Entry)->section($handle);
	}
	
	public function getSectionsWithEntries()
	{
		$sections = $this->getSections();
		
		foreach( $sections as $handle => $values)
		{
			if ( !empty($sections[$handle]))
			{
				foreach( $values as $index => $value )
				{
					$sections[$handle][$index]['entries'] = $this->getEntriesBySection($value['handle']);
				}
			}
		}
		
		return $sections;
	}
	
	public function getMenuItems()
	{
		$menu_items = craft()->db->createCommand()
		->select('id')
		->from('olivemenus_items')
		->orderby('id')
		->queryRow();

		return $menu_items;		
	}
    
    public function getMenuHTML($handle = false, $config )
    {
        if ( $handle )
        {
            $localHTML = '';
            
            $menu = $this->getMenuByHandle($handle);
			
			if ( $menu !== NULL )
			{
				$menu_id = '';
                $menu_class = '';
                $ul_class = '';
				$menu_items = craft()->olivemenus_menuitems->getMenuItems($menu->id);
				
				if ( !empty($config) )
				{
					if ( isset($config['menu-id']) )
					{
						$menu_id = ' id="' .$config['menu-id']. '"';
					}
                    
                    if ( isset($config['menu-class']) )
                    {
                        $menu_class .= ' ' . $config['menu-class'];
                    }
					if ( isset($config['ul-class']) )
					{
						$ul_class = $config['ul-class'];
					}
				}
				
				$localHTML .= '<div' .$menu_id. ' class="menu' .$menu_class. '">';
					$localHTML .= '<ul class="' . $ul_class . '">';
						foreach ( $menu_items as $menu_item )
						{
							$localHTML .= $this->getMenuItemHTML($menu_item);
						}            
					$localHTML .= '</ul>';
				$localHTML .= '</div>';
			}
			else
			{
				$localHTML .= '<p>A menu with this handle does not exit!</p>';
			}
				echo $localHTML;
           
        }
    }
    
    public function getMenuItemHTML($menu_item)
    {
        $menu_item_url = '';
		$menu_class = '';
        $menu_item_class = 'menu-item';
        $entry_id = $menu_item['entry_id'];
        $custom_url = $menu_item['custom_url'];
        $class = $menu_item['class'];
        $class_parent = $menu_item['class_parent'];
		
		$data_attributes = '';		
        $data_json = $menu_item['data_json'];

		$menu_class = $class;
		$menu_item_class = $menu_item_class . ' ' .$class_parent;

        if ( $custom_url != '' ) {
            $menu_item_url = $this->replaceEnvironmentVariables($custom_url);
        }else{
            $criteria = craft()->elements->getCriteria(ElementType::Entry);
            $criteria->id = $menu_item['entry_id'];
            if ( !empty($criteria->first()) ) $menu_item_url = $criteria->first()->url;
        }

		if ( $data_json )
		{
			$data_attributes = ' ';
			$data_json = explode(PHP_EOL, $data_json);
			foreach ( $data_json as $data_item)
			{
				$data_item = explode(':', $data_item);
				$data_attributes .= trim($data_item[0]) . '="' .trim($data_item[1]). '"';
			}
			
		}

        $current_active_url = craft()->request->getServerName() . craft()->request->getUrl();
        if ( $current_active_url != '' && $menu_item_url != '' )
        {
            $menu_item_url_filtered = preg_replace('#^https?://#', '', $menu_item_url);
            $current_active_url = preg_replace('/\?.*/', '', $current_active_url); // Remove query string
            if ( $current_active_url == $menu_item_url_filtered )
            {
                $menu_class .= ' active';
                $menu_item_class .= ' current-menu-item';
            }
        }
        
        $localHTML = '';
        $localHTML .= '<li id="menu-item-' .$menu_item['id']. '" class="' .$menu_item_class. '">';
            
            $localHTML .= '<a class="'. $menu_class. '" href="' .$menu_item_url. '"' .$data_attributes. '>' . Craft::t( $menu_item['name'] ) . '</a>';
            if ( isset($menu_item['children']) )
            {
                $localHTML .= '<ul class="sub-menu">';
                    foreach ( $menu_item['children'] as $child )
                    {
                       $localHTML .= $this->getMenuItemHTML($child); 
                    }
                $localHTML .= '</ul>';                
            }
            
        $localHTML .= '</li>';
        
        return $localHTML;
    }
	
    private function replaceEnvironmentVariables($str){
        $environmentVariables = craft()->config->get('environmentVariables');
        if(is_array($environmentVariables)){
            $tmp = array();
            foreach($environmentVariables as $tag => $val){
                $tmp[sprintf("{%s}", $tag)] = $val;
            }
            $environmentVariables = $tmp;
            return str_replace(array_keys($environmentVariables), array_values($environmentVariables), $str);
        }
    }
    
}
