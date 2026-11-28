<?php

namespace olivestudio\olivemenus\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Response;
use olivestudio\olivemenus\elements\NavigationFieldContent;
use olivestudio\olivemenus\models\OlivemenusModel;
use olivestudio\olivemenus\Olivemenus;
use olivestudio\olivemenus\records\OlivemenusItemsRecord;
use olivestudio\olivemenus\services\FieldContentService;
use olivestudio\olivemenus\services\FieldLayoutsService;
use olivestudio\olivemenus\services\MenuRevisions;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

final class FieldContentController extends MenuAdminController
{
    public function actionEdit(int $menuId, ?int $itemId = null): Response
    {
        $menu = $this->editableMenu($this->positiveId($menuId));
        $item = $this->item($menu, $itemId);
        $this->requireFields($item);
        $this->requireNoDraft($menu);

        return $this->renderFields($menu, $item, (new FieldContentService())->getEditorContent($menu->id, $itemId));
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $menu = $this->editableMenu($this->positiveId($this->request->getRequiredBodyParam('menuId')));
        $itemId = $this->optionalId($this->request->getBodyParam('itemId'));
        $item = $this->item($menu, $itemId);
        $this->requireFields($item);
        if (!is_array($this->request->getBodyParam('fields', []))) {
            throw new BadRequestHttpException(Craft::t('olivemenus', 'Invalid field values.'));
        }

        $fields = new FieldContentService();
        $revisions = new MenuRevisions();
        [$content, $saved] = $revisions->withMenuLock($menu, function() use ($menu, $itemId, $fields, $revisions): array {
            $this->requireNoDraft($menu);
            $content = $fields->getEditorContent($menu->id, $itemId);
            $content->setFieldValuesFromRequest('fields');
            $before = $revisions->liveSnapshot($menu->id);
            $saved = $fields->saveContent($content, $menu->id, $itemId);
            if ($saved) {
                $revisions->recordPublication($menu->id, $before, Craft::$app->getUser()->getId());
            }

            return [$content, $saved];
        });
        if (!$saved) {
            $this->setFailFlash(Craft::t('olivemenus', 'The field content could not be saved.'));
            return $this->renderFields($menu, $item, $content);
        }

        Olivemenus::$plugin->olivemenus->invalidateMenuCache();
        return $this->asSuccess(
            Craft::t('olivemenus', 'Field content saved.'),
            redirect: UrlHelper::cpUrl($itemId === null
                ? 'olivemenus/menu-fields/' . $menu->id
                : 'olivemenus/node-fields/' . $menu->id . '/' . $itemId),
        );
    }

    private function item(OlivemenusModel $menu, ?int $itemId): ?OlivemenusItemsRecord
    {
        if ($itemId === null) {
            return null;
        }

        $item = OlivemenusItemsRecord::findOne(['id' => $itemId, 'menu_id' => $menu->id]);
        if (!$item) {
            throw new NotFoundHttpException(Craft::t('olivemenus', 'Menu item not found.'));
        }

        return $item;
    }

    private function requireNoDraft(OlivemenusModel $menu): void
    {
        if ((new MenuRevisions())->hasDraft($menu->id)) {
            throw new ForbiddenHttpException(Craft::t('olivemenus', 'Publish or discard the menu draft before editing live field content.'));
        }
    }

    private function requireFields(?OlivemenusItemsRecord $item): void
    {
        if (!FieldLayoutsService::hasLayout($item === null ? FieldLayoutsService::MENU : FieldLayoutsService::NODE)) {
            throw new NotFoundHttpException(Craft::t('olivemenus', 'No fields have been configured for this layout.'));
        }
    }

    private function renderFields(OlivemenusModel $menu, ?OlivemenusItemsRecord $item, NavigationFieldContent $content): Response
    {
        $form = $content->getFieldLayout()->createForm($content, false, ['registerDeltas' => true]);

        return $this->renderTemplate('olivemenus/_field-content', [
            'menu' => $menu,
            'item' => $item,
            'site' => $this->editableSite($menu->site_id),
            'tabs' => $form->getTabMenu(),
            'fieldsHtml' => $form->render(),
        ]);
    }
}
