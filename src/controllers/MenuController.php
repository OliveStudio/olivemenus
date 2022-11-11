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

use olivestudio\olivemenus\assetbundles\olivemenus\OlivemenusAsset;
use olivestudio\olivemenus\models\OlivemenusModel;
use olivestudio\olivemenus\Olivemenus;

use Craft;
use craft\web\Controller;

/**
 * Menu Controller
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
class MenuController extends Controller
{

    // Protected Properties
    // =========================================================================

    /**
     * @var    bool|array Allows anonymous access to this controller's actions.
     *         The actions must be in 'kebab-case'
     * @access protected
     */
    protected $allowAnonymous = ['index', 'menu-new', 'save-menu', 'delete-menu', 'menu-edit'];

    // Public Methods
    // =========================================================================

    /**
     * Handle a request going to our plugin's index action URL,
     * e.g.: actions/olivemenus/menu
     *
     * @return mixed
     */
    public function actionIndex($siteHandle = null)
    {
        $siteHandle = $siteHandle ?? Craft::$app->getSites()->currentSite->handle;

        $objSite = Craft::$app->getSites()->getSiteByHandle($siteHandle);
        if (!$objSite) {
            $siteHandle = Craft::$app->getSites()->getPrimarySite()->handle;
            $objSite = Craft::$app->getSites()->getSiteByHandle($siteHandle);
        }
        $this->view->registerAssetBundle(OlivemenusAsset::class);
        $data['menus'] = Olivemenus::$plugin->olivemenus->getAllMenus($objSite->id);
        $data['objSite'] = $objSite;

        return $this->renderTemplate('olivemenus/_index', $data);
    }

    /**
     * Handle a request going to our plugin's actionMenuNew URL,
     * e.g.: actions/olivemenus/menu/menu-new
     *
     * @return mixed
     */
    public function actionMenuNew($siteHandle)
    {
        $objSite = Craft::$app->getSites()->getSiteByHandle($siteHandle);
        if (!$objSite) {
            $siteHandle = Craft::$app->getSites()->getPrimarySite()->handle;
            $objSite = Craft::$app->getSites()->getSiteByHandle($siteHandle);
        }
        $data['objSite'] = $objSite;

        $this->view->registerAssetBundle(OlivemenusAsset::class);

        return $this->renderTemplate('olivemenus/_menu-new', $data);
    }
    /**
     * Handle a request going to our plugin's actionSaveMenu URL,
     * e.g.: actions/olivemenus/menu/save-menu
     *
     * @return mixed
     */
    public function actionSaveMenu()
    {
        $this->requirePostRequest();
        if (isset(Craft::$app->request->getBodyParams()['data']['id'])) {
            $model = Olivemenus::$plugin->olivemenus->getMenuById(Craft::$app->request->getBodyParams()['data']['id']);
        } else {
            $model = new OlivemenusModel();
        }

        $model->setAttributes(Craft::$app->request->getBodyParams()['data']);

        if (!$model->validate()) {
            Craft::$app->getSession()->setError(Craft::t('olivemenus', 'Validation errors have occured.'));
            
            $objSite = Craft::$app->getSites()->getSiteById($model->site_id);
            if (!$objSite) {
                $siteHandle = Craft::$app->getSites()->getPrimarySite()->handle;
                $objSite = Craft::$app->getSites()->getSiteByHandle($siteHandle);
            }

            return $this->renderTemplate('olivemenus/_menu-new', [
                'menu' => $model,
                'errors' => $model->getErrors(),
                'objSite' => $objSite
            ]);
        } else {
            Olivemenus::$plugin->olivemenus->saveMenu($model);
            Craft::$app->getSession()->setNotice(Craft::t('olivemenus', 'Menu saved successfully.'));

            $objSite = Craft::$app->getSites()->getSiteById($model->site_id);
            if (!$objSite) {
                $siteHandle = Craft::$app->getSites()->getPrimarySite()->handle;
            } else {
                $siteHandle = $objSite->handle;
            }
            return $this->redirect("olivemenus/$siteHandle");
        }

    }

    public function actionDeleteMenu() {
        if (Craft::$app->request->getIsAjax()) {
            $this->requirePostRequest();
            $this->requireAcceptsJson();

            if (Olivemenus::$plugin->olivemenus->deleteMenuById(Craft::$app->request->post('menuID'))) {
                // Return data
                $returnData['success'] = true;
                return $this->asJson($returnData);
            };
        } else {
            $menuId = Craft::$app->request->getSegment(3);

            if ($menuId) {
                $menu = Olivemenus::$plugin->olivemenus->getMenuById($menuId);
                if (Olivemenus::$plugin->olivemenus->deleteMenuById($menuId)) {
                    Craft::$app->getSession()->setNotice(Craft::t('olivemenus', 'Menu deleted successfully.'));
                } else {
                    Craft::$app->getSession()->setError(Craft::t('olivemenus', 'An error occurred while deleting menu.'));
                }
                $objSite = Craft::$app->getSites()->getSiteById($menu->site_id);
                if (!$objSite) {
                    $siteHandle = Craft::$app->getSites()->getPrimarySite()->handle;
                } else {
                    $siteHandle = $objSite->handle;
                }
                return $this->redirect("olivemenus/$siteHandle");
            }

            return $this->redirect("olivemenus");
        }
    }

    public function actionMenuEdit($menuId = null) {
        if ($menuId) {
            $menu = Olivemenus::$plugin->olivemenus->getMenuById($menuId);
            $arrData['menu'] = $menu;
            
            if (isset(Craft::$app->request->getBodyParams()['data']['id'])) {
                $model = Olivemenus::$plugin->olivemenus->getMenuById(Craft::$app->request->getBodyParams()['data']['id']);
                $model->setAttributes(Craft::$app->request->getBodyParams()['data']);

                if (!$model->validate()) {
                    Craft::$app->getSession()->setError(Craft::t('olivemenus', 'Validation errors have occured.'));
                    $arrData['menu'] = $model;
                    $arrData['errors'] = $model->getErrors();
                    $arrData['originalMenu'] = $menu;
                } else {
                    Olivemenus::$plugin->olivemenus->saveMenu($model);
                    Craft::$app->getSession()->setNotice(Craft::t('olivemenus', 'Menu saved successfully.'));
                    
                    $arrData['menu'] = $model;
                }
            }

            $objSite = Craft::$app->getSites()->getSiteById($menu->site_id);
            if (!$objSite) {
                $siteHandle = Craft::$app->getSites()->getPrimarySite()->handle;
                $objSite = Craft::$app->getSites()->getSiteByHandle($siteHandle);
            }
            $arrData['objSite'] = $objSite;

            return $this->renderTemplate('olivemenus/_menu-edit', $arrData);
        }
    }
}
