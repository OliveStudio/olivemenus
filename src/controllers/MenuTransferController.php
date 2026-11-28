<?php

namespace olivestudio\olivemenus\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Response;
use craft\web\UploadedFile;
use InvalidArgumentException;
use olivestudio\olivemenus\services\PortableMenuService;
use RuntimeException;
use yii\db\Exception as DbException;

final class MenuTransferController extends MenuAdminController
{
    public function actionExport(int $menuId): Response
    {
        $menu = $this->editableMenu($menuId);
        try {
            $document = (new PortableMenuService())->export($menu);
        } catch (InvalidArgumentException $exception) {
            $this->setFailFlash($exception->getMessage());
            $site = $this->editableSite($menu->site_id);
            return $this->redirect(UrlHelper::cpUrl('olivemenus/' . $site->handle));
        }

        $this->response->getHeaders()->set('Cache-Control', 'private, no-store');
        return $this->response->sendContentAsFile(
            $document,
            $menu->handle . '.olive-menu.json',
            ['mimeType' => 'application/json'],
        );
    }

    public function actionImport(int $siteId): Response
    {
        $site = $this->editableSite($siteId);
        $document = '';
        $plan = null;
        $error = null;
        if ($this->request->getIsPost()) {
            try {
                $file = UploadedFile::getInstanceByName('menuJson');
                if (!$file || $file->getHasError() || $file->size > PortableMenuService::MAX_BYTES) {
                    throw new InvalidArgumentException(Craft::t('olivemenus', 'Choose an Olive Menus JSON file of 1 MB or less.'));
                }

                $document = file_get_contents($file->tempName, length: PortableMenuService::MAX_BYTES + 1);
                if ($document === false) {
                    throw new InvalidArgumentException(Craft::t('olivemenus', 'The selected file could not be read.'));
                }
                $plan = (new PortableMenuService())->review($document, $site->id);
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->renderTemplate('olivemenus/_menu-transfer', [
            'site' => $site,
            'document' => $document,
            'plan' => $plan,
            'error' => $error,
        ]);
    }

    public function actionCreateMenu(): Response
    {
        $this->requirePostRequest();
        $site = $this->editableSite($this->positiveId($this->request->getRequiredBodyParam('siteId')));
        $document = $this->request->getRequiredBodyParam('document');
        $name = $this->request->getRequiredBodyParam('name');
        $handle = $this->request->getRequiredBodyParam('handle');
        if (!is_string($document) || !is_string($name) || !is_string($handle)) {
            throw new \yii\web\BadRequestHttpException();
        }

        try {
            $menuId = (new PortableMenuService())->import($document, $site->id, trim($name), trim($handle));
        } catch (InvalidArgumentException $exception) {
            $this->setFailFlash($exception->getMessage());
            return $this->redirect(UrlHelper::cpUrl('olivemenus/import/' . $site->id));
        } catch (DbException|RuntimeException $exception) {
            Craft::error($exception->getMessage(), __METHOD__);
            $this->setFailFlash(Craft::t('olivemenus', 'The menu could not be imported.'));
            return $this->redirect(UrlHelper::cpUrl('olivemenus/import/' . $site->id));
        }

        $this->setSuccessFlash(Craft::t('olivemenus', 'Menu imported.'));
        return $this->redirect(UrlHelper::cpUrl('olivemenus/menu-items/' . $menuId));
    }
}
