<?php
namespace Craft;

class Olivemenus_MenusController extends BaseController
{
	protected $allowAnonymous = true;
	
	public function actionIndexMenu()
	{
        $this->renderTemplate('olivemenus/settings', array(
			'menus' => craft()->olivemenus->getMenus()
		));	   
	}
	
    public function actionSaveMenu()
    {
		$this->requirePostRequest();
		
		$menu = new Olivemenus_MenusModel();
		
		$menu->name   = craft()->request->getPost('name');
		$menu->handle = craft()->request->getPost('handle');
		
		craft()->olivemenus->saveMenu($menu);
		
		if ($menu->getAllErrors()) craft()->userSession->setFlash('error', 'Validation errors have occured.');
		else 
		{
			craft()->userSession->setFlash('notice', 'Menu saved successfully.');
			$this->redirect('olivemenus');
		}

		craft()->urlManager->setRouteVariables(array(
			'menu' => $menu
		));		
    }
	
    public function actionEditMenu()
    {
		
		$menu_id = craft()->request->getSegment(3);
		
		if ( $menu_id )
		{
			$menu = craft()->olivemenus->getMenuByID($menu_id);
			
			$name = craft()->request->getPost('name');
			$handle = craft()->request->getPost('handle');
			
			$original_name = $menu;
			
			if ( isset($name) && isset($handle) )
			{
				$menu = new Olivemenus_MenusModel();
				
				$menu->id   = $menu_id;
				$menu->name   = $name;
				$menu->handle = $handle;
							
				craft()->olivemenus->saveMenu($menu);
				
				if ($menu->getAllErrors()) craft()->userSession->setFlash('error', 'Validation errors have occured.');
				else craft()->userSession->setFlash('notice', 'Menu saved successfully.');				
			
			}
			
			$this->renderTemplate('olivemenus/_menu-container-edit', array(
				'menu' => $menu,
				'original_name' => $original_name
			));	
		}
    }	
	
	public function actionDeleteMenu()
	{
		if ( craft()->request->isAjaxRequest() )
		{
			$this->requirePostRequest();
			$menu_id = craft()->request->getPost('menuID');
			if ( $menu_id ) echo craft()->olivemenus->deleteMenu($menu_id);
		}
		else
		{
			$menu_id = craft()->request->getSegment(3);
			if ( $menu_id ) echo craft()->olivemenus->deleteMenu($menu_id);
			craft()->userSession->setFlash('notice', 'Menu deleted successfully.');	
			$this->redirect('olivemenus');
		}
	}
	
	public function actionEditMenuItems()
	{
		$menu_id = craft()->request->getSegment(3);
		
		$this->renderTemplate('olivemenus/_menu-container-items', array(
			'menu' => craft()->olivemenus->getMenuByID($menu_id),
			'sections' => craft()->olivemenus->getSectionsWithEntries(),
            'menu_items_markup' => craft()->olivemenus_menuitems->getMenuItemsAdminMarkup($menu_id)
		));		
	}
	
}