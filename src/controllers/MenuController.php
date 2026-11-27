<?php

namespace olivestudio\olivemenus\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\web\Response;
use olivestudio\olivemenus\assetbundles\olivemenus\OlivemenusAsset;
use olivestudio\olivemenus\models\OlivemenusModel;
use olivestudio\olivemenus\Olivemenus;
use olivestudio\olivemenus\services\DuplicateMenuService;
use RuntimeException;
use yii\db\Exception as DbException;
use yii\db\IntegrityException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

class MenuController extends MenuAdminController
{
    public function actionIndex(?string $siteHandle = null): Response
    {
        $site = $this->siteByHandle($siteHandle);
        $this->view->registerAssetBundle(OlivemenusAsset::class);

        $duplicateSites = array_filter(
            Craft::$app->getSites()->getEditableSites(),
            static fn (Site $candidate): bool => $candidate->id !== $site->id,
        );

        return $this->renderTemplate('olivemenus/_index', [
            'menus' => Olivemenus::$plugin->olivemenus->getAllMenus($site->id),
            'objSite' => $site,
            'duplicateSites' => $duplicateSites,
        ]);
    }

    public function actionMenuNew(string $siteHandle): Response
    {
        return $this->renderTemplate('olivemenus/_menu-new', [
            'objSite' => $this->siteByHandle($siteHandle),
        ]);
    }

    public function actionSaveMenu(): Response
    {
        $this->requirePostRequest();
        $data = $this->menuFormData();
        $menuId = $this->optionalId($data['id'] ?? null);

        if ($menuId === null) {
            $site = $this->editableSite($this->positiveId($data['site_id'] ?? null));
            $menu = new OlivemenusModel(['site_id' => $site->id]);
        } else {
            $menu = $this->editableMenu($menuId);
            $site = $this->editableSite($menu->site_id);
        }

        if ($this->saveMenu($menu, $data)) {
            return $this->redirect(UrlHelper::cpUrl('olivemenus/' . $site->handle));
        }

        return $this->renderTemplate('olivemenus/_menu-new', [
            'menu' => $menu,
            'errors' => $menu->getErrors(),
            'objSite' => $site,
        ]);
    }

    public function actionDeleteMenu(?int $menuId = null): ?Response
    {
        $this->requirePostRequest();
        $menu = $this->editableMenu($this->positiveId($this->request->getBodyParam('menuID', $menuId)));
        $site = $this->editableSite($menu->site_id);
        $saved = Olivemenus::$plugin->olivemenus->deleteMenuById($menu->id);

        if ($this->request->getAcceptsJson()) {
            return $saved
                ? $this->asSuccess(Craft::t('olivemenus', 'Menu deleted successfully.'), ['success' => true])
                : $this->asFailure(Craft::t('olivemenus', 'An error occurred while deleting the menu.'));
        }

        if ($saved) {
            $this->setSuccessFlash(Craft::t('olivemenus', 'Menu deleted successfully.'));
        } else {
            $this->setFailFlash(Craft::t('olivemenus', 'An error occurred while deleting the menu.'));
        }

        return $this->redirect(UrlHelper::cpUrl('olivemenus/' . $site->handle));
    }

    public function actionDuplicateMenu(): Response
    {
        $this->requirePostRequest();

        $menu = $this->editableMenu($this->positiveId($this->request->getRequiredBodyParam('menuId')));
        $site = $this->editableSite($menu->site_id);
        $targetSiteId = $this->optionalId($this->request->getBodyParam('targetSiteId'));
        $targetSite = $targetSiteId === null ? $site : $this->editableSite($targetSiteId);

        try {
            $duplicateId = (new DuplicateMenuService())->duplicate($menu, $targetSite->id);
        } catch (DbException|RuntimeException $exception) {
            Craft::error($exception->getMessage(), __METHOD__);
            $this->setFailFlash(Craft::t('olivemenus', 'The menu could not be duplicated.'));

            return $this->redirect(UrlHelper::cpUrl('olivemenus/' . $site->handle));
        }

        $this->setSuccessFlash($targetSite->id === $site->id
            ? Craft::t('olivemenus', 'Menu duplicated.')
            : Craft::t('olivemenus', 'Menu duplicated to {site}.', ['site' => $targetSite->getName()]));

        return $this->redirect(UrlHelper::cpUrl('olivemenus/menu-items/' . $duplicateId));
    }

    public function actionMenuEdit(?int $menuId = null): Response
    {
        $isPost = $this->request->getIsPost();
        $data = $isPost ? $this->menuFormData() : [];
        $submittedId = $this->optionalId($data['id'] ?? null);

        if ($menuId !== null && $submittedId !== null && $menuId !== $submittedId) {
            throw new BadRequestHttpException(Craft::t('olivemenus', 'The menu ID does not match.'));
        }

        $menu = $this->editableMenu($this->positiveId($menuId ?? $submittedId));
        $site = $this->editableSite($menu->site_id);
        $originalMenu = clone $menu;

        if ($isPost && $this->saveMenu($menu, $data)) {
            return $this->redirect(UrlHelper::cpUrl('olivemenus/menu-edit/' . $menu->id));
        }

        return $this->renderTemplate('olivemenus/_menu-edit', [
            'menu' => $menu,
            'errors' => $menu->getErrors(),
            'originalMenu' => $originalMenu,
            'objSite' => $site,
        ]);
    }

    private function siteByHandle(?string $siteHandle): Site
    {
        $sites = Craft::$app->getSites();

        if ($siteHandle !== null) {
            $site = $sites->getSiteByHandle($siteHandle);

            if ($site !== null) {
                return $this->editableSite($site->id);
            }
        }

        $site = $sites->getCurrentSite();
        if (in_array($site->id, $sites->getEditableSiteIds(), true)) {
            return $site;
        }

        return $sites->getEditableSites()[0] ?? throw new ForbiddenHttpException();
    }

    private function menuFormData(): array
    {
        $data = $this->request->getRequiredBodyParam('data');

        if (!is_array($data)) {
            throw new BadRequestHttpException(Craft::t('olivemenus', 'Invalid menu data.'));
        }

        return $data;
    }

    private function saveMenu(OlivemenusModel $menu, array $data): bool
    {
        $menu->name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        $menu->handle = is_string($data['handle'] ?? null) ? trim($data['handle']) : '';

        if (!$menu->validate()) {
            $this->setFailFlash(Craft::t('olivemenus', 'Please check the highlighted fields.'));
            return false;
        }

        try {
            if (Olivemenus::$plugin->olivemenus->saveMenu($menu)) {
                $this->setSuccessFlash(Craft::t('olivemenus', 'Menu saved successfully.'));
                return true;
            }
        } catch (IntegrityException $exception) {
            Craft::error($exception->getMessage(), __METHOD__);
        }

        $this->setFailFlash(Craft::t('olivemenus', 'The menu could not be saved. Please try again.'));

        return false;
    }
}
