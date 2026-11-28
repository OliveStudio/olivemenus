<?php

namespace olivestudio\olivemenus\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\web\Response;
use InvalidArgumentException;
use olivestudio\olivemenus\records\OlivemenusRecord;
use olivestudio\olivemenus\services\SiteVariantService;
use RuntimeException;
use yii\db\Exception as DbException;

final class SiteVariantsController extends MenuAdminController
{
    public function actionIndex(int $menuId): Response
    {
        $menu = $this->editableMenu($menuId);
        $siteService = Craft::$app->getSites();
        $source = $menu->source_menu_uid !== null
            ? OlivemenusRecord::findOne(['uid' => $menu->source_menu_uid])
            : null;
        if ($source && !in_array((int)$source->site_id, $siteService->getEditableSiteIds(), true)) {
            $source = null;
        }

        return $this->renderTemplate('olivemenus/_site-variants', [
            'menu' => $menu,
            'site' => $this->editableSite($menu->site_id),
            'source' => $source,
            'variants' => (new SiteVariantService())->variants($menu),
            'sites' => array_values(array_filter($siteService->getEditableSites(), static fn (Site $site): bool =>
                $site->id !== $menu->site_id && $site->getEnabled())),
        ]);
    }

    public function actionSync(): Response
    {
        $this->requirePostRequest();
        $menu = $this->editableMenu($this->positiveId($this->request->getRequiredBodyParam('menuId')));
        $site = $this->editableSite($this->positiveId($this->request->getRequiredBodyParam('siteId')));
        $redirect = UrlHelper::cpUrl('olivemenus/site-variants/' . $menu->id);
        try {
            $variantId = (new SiteVariantService())->sync($menu, $site);
        } catch (InvalidArgumentException $exception) {
            $this->setFailFlash($exception->getMessage());
            return $this->redirect($redirect);
        } catch (DbException|RuntimeException $exception) {
            Craft::error($exception->getMessage(), __METHOD__);
            $this->setFailFlash(Craft::t('olivemenus', 'The site variant could not be synchronized.'));
            return $this->redirect($redirect);
        }

        $this->setSuccessFlash(Craft::t('olivemenus', 'Menu structure synchronized to {site}.', ['site' => $site->getName()]));
        return $this->redirect(UrlHelper::cpUrl('olivemenus/menu-items/' . $variantId));
    }
}
