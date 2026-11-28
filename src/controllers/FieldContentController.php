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
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

final class FieldContentController extends MenuAdminController
{
    public function actionEdit(int $menuId, ?int $itemId = null): Response
    {
        $menu = $this->editableMenu($this->positiveId($menuId));
        $item = $this->item($menu, $itemId);
        $this->requireFields($item);

        $revisions = new MenuRevisions();
        [$content, $fieldVersion] = $revisions->withMenuLock($menu, function() use ($menu, $itemId, $revisions): array {
            $this->requireNoDraft($menu);

            return [(new FieldContentService())->getEditorContent($menu->id, $itemId), $revisions->liveVersion($menu->id)];
        });

        return $this->renderFields($menu, $item, $content, $fieldVersion);
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

        $fieldVersion = $this->request->getRequiredBodyParam('fieldVersion');
        if (!is_string($fieldVersion) || !preg_match('/^[a-f0-9]{64}$/D', $fieldVersion)) {
            throw new BadRequestHttpException(Craft::t('olivemenus', 'Invalid field version.'));
        }

        $fields = new FieldContentService();
        $revisions = new MenuRevisions();
        [$content, $saved] = $revisions->withMenuLock($menu, function() use ($menu, $itemId, $fieldVersion, $fields, $revisions): array {
            $this->requireNoDraft($menu);
            $before = $revisions->liveSnapshot($menu->id);
            if (!hash_equals($revisions->snapshotVersion($before), $fieldVersion)) {
                throw new ConflictHttpException(Craft::t('olivemenus', 'The live menu has changed. Reload and try again.'));
            }

            $content = $fields->getEditorContent($menu->id, $itemId);
            $content->setFieldValuesFromRequest('fields');
            $saved = $fields->saveContent($content, $menu->id, $itemId);
            if ($saved) {
                $revisions->recordPublication($menu->id, $before, Craft::$app->getUser()->getId());
            }

            return [$content, $saved];
        });
        if (!$saved) {
            $this->setFailFlash(Craft::t('olivemenus', 'The field content could not be saved.'));
            return $this->renderFields($menu, $item, $content, $fieldVersion);
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

    private function renderFields(
        OlivemenusModel $menu,
        ?OlivemenusItemsRecord $item,
        NavigationFieldContent $content,
        string $fieldVersion,
    ): Response {
        $form = $content->getFieldLayout()->createForm($content, false, ['registerDeltas' => true]);

        return $this->renderTemplate('olivemenus/_field-content', [
            'menu' => $menu,
            'item' => $item,
            'site' => $this->editableSite($menu->site_id),
            'tabs' => $form->getTabMenu(),
            'fieldsHtml' => $form->render(),
            'fieldVersion' => $fieldVersion,
        ]);
    }
}
