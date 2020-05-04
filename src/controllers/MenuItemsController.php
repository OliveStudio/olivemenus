<?php
/**
 * Olivemenus plugin for Craft CMS 3.x
 *
 * OliveStudio menu
 *
 * @link      http://www.olivestudio.net/
 * @copyright Copyright (c) 2018 Olivestudio
 */

namespace olivestudio\olivemenus\controllers;

use olivestudio\olivemenus\assetbundles\olivemenus\OlivemenuItemsAsset;
use olivestudio\olivemenus\models\OlivemenusItemsModel;
use olivestudio\olivemenus\Olivemenus;

use Craft;
use craft\web\Controller;

/**
 * MenuItems Controller
 *
 * Generally speaking, controllers are the middlemen between the front end of
 * the CP/website and your plugin’s services. They contain action methods which
 * handle individual tasks.
 *
 * A common pattern used throughout Craft involves a controller action gathering
 * post data, saving it on a model, passing the model off to a service, and then
 * responding to the request appropriately depending on the service method’s response.
 *
 * Action methods begin with the prefix “action”, followed by a description of what
 * the method does (for example, actionSaveIngredient()).
 *
 * https://craftcms.com/docs/plugins/controllers
 *
 * @author    Olivestudio
 * @package   Olivemenus
 * @since     1.0.0
 */
class MenuItemsController extends Controller
{

    // Protected Properties
    // =========================================================================

    /**
     * @var    bool|array Allows anonymous access to this controller's actions.
     *         The actions must be in 'kebab-case'
     * @access protected
     */
    protected $allowAnonymous = ['edit', 'save-menu-items'];

    // Public Methods
    // =========================================================================

    /**
     * Handle a request going to our plugin's edit action URL,
     * e.g.: actions/olivemenus/menu-items
     *
     * @return mixed
     */
    public function actionEdit($menuId = null)
    {
        $this->view->registerAssetBundle(OlivemenuItemsAsset::class);
        $menu = Olivemenus::$plugin->olivemenus->getMenuById($menuId);
        $data['menu'] = $menu;
        $data['sections'] = Olivemenus::$plugin->olivemenuItems->getSectionsWithEntries($menu->site_id);
        $data['menuItemsMarkup'] = Olivemenus::$plugin->olivemenuItems->getMenuItemsAdminMarkup($menuId);
        $data['categories'] = Craft::$app
                                   ->categories
                                   ->getAllGroups();
        
        $objSite = Craft::$app->getSites()->getSiteById($menu->site_id);
        if (!$objSite) {
            $siteHandle = Craft::$app->getSites()->getPrimarySite()->handle;
            $objSite = Craft::$app->getSites()->getSiteByHandle($siteHandle);
        }
        $data['objSite'] = $objSite;
        return $this->renderTemplate('olivemenus/_menu-items', $data);
    }

    /**
     * Handle a request going to our plugin's actionSave URL,
     * e.g.: actions/olivemenus/menu-items/save-menu-items
     *
     * @return mixed
     */
    public function actionSaveMenuItems()
    {
        $this->requirePostRequest();
        $intMenuId = Craft::$app->request->getBodyParams()['menu-id'];
        $strMenuItems = Craft::$app->request->getBodyParams()['menu-items-serialized'];

        $arrMenuItems = json_decode($strMenuItems, true);

        if (!empty($arrMenuItems)) {
            foreach($arrMenuItems as $order=> $menuItem) {
                $parent_id = 0;

                if (isset($menuItem['parent-id'])){
                    $parent_id = $menuItem['parent-id'];

                    foreach ($arrMenuItems as $element) {
                        if (isset($element['item-id']['html']) && $element['item-id']['html'] == $parent_id) {
                            $parent_id = $element['menu-item-db-id'];
                            $arrMenuItems[$order]['parent-id'] = $parent_id;
                            break;
                        }
                    }
                }
                
                if ($menuItem['item-id']['db'] != null) {
                    $menuItemModel = Olivemenus::$plugin->olivemenuItems->getMenuItem($menuItem['item-id']['db']);
                } else {
                    $menuItemModel = new OlivemenusItemsModel();
                }
                $arrData['id']= $menuItem['item-id']['db'];
                $arrData['menu_id']= $intMenuId;
                $arrData['parent_id']= $parent_id;
                $arrData['item_order']= $order;
                $arrData['name']= $menuItem['name'];
                $arrData['entry_id']= (isset($menuItem['entry-id']) ? $menuItem['entry-id'] : '');
                $arrData['custom_url']= (isset($menuItem['custom-url']) ? $menuItem['custom-url'] : '');
                $arrData['class']= (isset($menuItem['class']) ? $menuItem['class'] : '');
                $arrData['class_parent']= (isset($menuItem['class-parent']) ? $menuItem['class-parent'] : '');
                $arrData['data_json']= (isset($menuItem['data-json']) ? $menuItem['data-json'] : '');
                $arrData['target']= (isset($menuItem['target']) ? $menuItem['target'] : '');

                $menuItemModel->setAttributes($arrData);

                if ($menuItemModel->validate()) {
                    $menuItemDbId = Olivemenus::$plugin->olivemenuItems->saveMenuItem($menuItemModel);
                    if (is_numeric($menuItemDbId)) $arrMenuItems[$order]['menu-item-db-id'] = $menuItemDbId;
                }
            }
        }

        $menuItemsDeleted = Craft::$app->request->getBodyParams()['menu-items-deleted'];
        if (!empty($menuItemsDeleted)) {
            $arrItems = explode(',', $menuItemsDeleted);
            if (!empty($arrItems)) {
                foreach ($arrItems as $intVal) {
                    Olivemenus::$plugin->olivemenuItems->deleteMenuItem($intVal);
                }
            }
        }
        Craft::$app->getSession()->setNotice(Craft::t('olivemenus', 'Menu items saved successfully.'));
    }
}