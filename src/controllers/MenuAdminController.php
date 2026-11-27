<?php

namespace olivestudio\olivemenus\controllers;

use Craft;
use craft\models\Site;
use craft\web\Controller;
use olivestudio\olivemenus\models\OlivemenusModel;
use olivestudio\olivemenus\Olivemenus;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

abstract class MenuAdminController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-olivemenus');

        return true;
    }

    protected function editableMenu(int $menuId): OlivemenusModel
    {
        $menu = Olivemenus::$plugin->olivemenus->getMenuById($menuId);

        if ($menu === null) {
            throw new NotFoundHttpException(Craft::t('olivemenus', 'Menu not found.'));
        }

        $this->editableSite($menu->site_id);

        return $menu;
    }

    protected function editableSite(int $siteId): Site
    {
        $sites = Craft::$app->getSites();
        $site = $sites->getSiteById($siteId);

        if ($site === null) {
            throw new NotFoundHttpException(Craft::t('olivemenus', 'Site not found.'));
        }

        if (!in_array($siteId, $sites->getEditableSiteIds(), true)) {
            throw new ForbiddenHttpException();
        }

        return $site;
    }

    protected function positiveId(mixed $value): int
    {
        $id = $this->optionalId($value);

        if ($id === null) {
            throw new BadRequestHttpException(Craft::t('olivemenus', 'An ID is required.'));
        }

        return $id;
    }

    protected function optionalId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = is_int($value) || is_string($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        if ($id === false) {
            throw new BadRequestHttpException(Craft::t('olivemenus', 'Invalid ID.'));
        }

        return $id;
    }
}
