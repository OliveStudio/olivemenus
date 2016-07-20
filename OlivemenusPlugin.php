<?php
namespace Craft;

class OlivemenusPlugin extends BasePlugin
{
	function init()
	{
		if ( craft()->request->isCpRequest() && craft()->userSession->isLoggedIn() )
		{
			craft()->templates->includeJsResource('olivemenus/js/jquery-ui.js');	
			craft()->templates->includeJsResource('olivemenus/js/jquery.filterList.min.js');	
			craft()->templates->includeJsResource('olivemenus/js/jquery.mjs.nestedSortable.js');	
			craft()->templates->includeJsResource('olivemenus/js/jquery-custom.js');	
			craft()->templates->includeCssResource('olivemenus/css/style.css');
		}
	}
    function getName()
    {
         return Craft::t('Menus');
    }

    function getVersion()
    {
        return '1.0.0';
    }

    function getDeveloper()
    {
        return 'Olive Studio';
    }

    function getDeveloperUrl()
    {
        return 'http://www.olivestudio.net/';
    }

    public function hasCpSection()
    {
        return true;
    }
	
    public function registerCpRoutes()
    {
        return array(
			'olivemenus' => array('action' => 'olivemenus/menus/indexMenu'),
            'olivemenus/menu-new' => 'olivemenus/_menu-container-new',
            'olivemenus/menu-edit/(?P<menuId>\d+)' => array('action' => 'olivemenus/menus/editMenu'),
			'olivemenus/menu-items/(?P<menuId>\d+)' => array('action' => 'olivemenus/menus/editMenuItems'),
			'olivemenus/save-menu' => array('action' => 'olivemenus/menus/saveMenu'),
			'olivemenus/delete-menu' => array('action' => 'olivemenus/menus/deleteMenu'),
			'olivemenus/delete-menu/(?P<menuId>\d+)' => array('action' => 'olivemenus/menus/deleteMenu')			
       );
    }	
	
}