<?php

namespace olivestudio\olivemenus\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\web\Response;
use olivestudio\olivemenus\services\FieldLayoutsService;
use yii\web\BadRequestHttpException;

final class FieldLayoutController extends MenuAdminController
{
    public function actionEdit(string $scope): Response
    {
        $this->requireAdmin();
        $scope = $this->validatedScope($scope);
        return $this->renderLayout($scope, FieldLayoutsService::layout($scope));
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();
        $scope = $this->validatedScope($this->request->getRequiredBodyParam('scope'));
        $layout = Craft::$app->getFields()->assembleLayoutFromPost();
        if (!FieldLayoutsService::save($scope, $layout)) {
            $this->setFailFlash(Craft::t('olivemenus', 'The field layout could not be saved.'));
            return $this->renderLayout($scope, $layout);
        }

        return $this->asSuccess(
            Craft::t('olivemenus', 'Field layout saved.'),
            redirect: UrlHelper::cpUrl('olivemenus/field-layouts/' . $scope),
        );
    }

    private function validatedScope(mixed $scope): string
    {
        if (!in_array($scope, [FieldLayoutsService::MENU, FieldLayoutsService::NODE], true)) {
            throw new BadRequestHttpException(Craft::t('olivemenus', 'Unknown field layout.'));
        }

        return $scope;
    }

    private function renderLayout(string $scope, FieldLayout $layout): Response
    {
        return $this->renderTemplate('olivemenus/_field-layout', [
            'scope' => $scope,
            'fieldLayout' => $layout,
        ]);
    }
}
