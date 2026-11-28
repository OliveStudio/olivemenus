<?php

namespace olivestudio\olivemenus\controllers;

use Craft;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\web\Response;
use olivestudio\olivemenus\models\OlivemenusModel;
use olivestudio\olivemenus\Olivemenus;
use olivestudio\olivemenus\services\MenuRevisions;
use RuntimeException;
use yii\db\Exception as DbException;
use yii\web\BadRequestHttpException;
use yii\web\ConflictHttpException;
use yii\web\NotFoundHttpException;

final class MenuRevisionsController extends MenuAdminController
{
    public function actionHistory(int $menuId): Response
    {
        $menu = $this->editableMenu($menuId);
        $service = new MenuRevisions();
        $before = $this->optionalId($this->request->getQueryParam('before'));
        $history = $service->getHistory($menuId, $before);
        $hasMore = count($history) > MenuRevisions::PAGE_SIZE;
        if ($hasMore) {
            array_pop($history);
        }

        $actorIds = array_values(array_unique(array_filter(array_column($history, 'actor_id'))));
        $actors = $actorIds ? User::find()->id($actorIds)->status(null)->indexBy('id')->all() : [];
        $this->response->getHeaders()->set('Cache-Control', 'private, no-store');

        return $this->renderTemplate('olivemenus/_menu-revisions', [
            'menu' => $menu,
            'history' => $history,
            'actors' => $actors,
            'olderThan' => $hasMore ? (int)$history[array_key_last($history)]['id'] : null,
            'hasDraft' => $service->hasDraft($menuId),
        ]);
    }

    public function actionPreview(int $menuId): Response
    {
        $menu = $this->editableMenu($menuId);
        $service = new MenuRevisions();
        $revisionId = $this->optionalId($this->request->getQueryParam('revision'));
        $snapshot = $revisionId === null ? $service->getDraft($menuId) : $service->getRevision($menuId, $revisionId);
        if ($snapshot === null) {
            throw new NotFoundHttpException(Craft::t('olivemenus', 'Menu preview not found.'));
        }

        $items = $service->itemsForEditor($menuId, $snapshot['items']);
        $this->response->getHeaders()->set('Cache-Control', 'private, no-store');

        return $this->renderTemplate('olivemenus/_menu-preview', [
            'menu' => $menu,
            'revisionId' => $revisionId,
            'items' => Olivemenus::$plugin->olivemenus->getPreviewData($items, $menu->site_id),
        ]);
    }

    public function actionStart(): Response
    {
        $menu = $this->_postedMenu();

        return $this->_change(
            $menu,
            fn () => (new MenuRevisions())->startDraft($menu, Craft::$app->getUser()->getId()),
            'Menu draft created.',
            true,
        );
    }

    public function actionRestore(): Response
    {
        $menu = $this->_postedMenu();
        $revisionId = $this->positiveId($this->request->getRequiredBodyParam('revisionId'));

        return $this->_change(
            $menu,
            fn () => (new MenuRevisions())->restore($menu, $revisionId, Craft::$app->getUser()->getId()),
            'Revision restored as a draft.',
            true,
        );
    }

    public function actionPublish(): Response
    {
        $menu = $this->_postedMenu();
        $version = $this->_postedVersion();

        return $this->_change(
            $menu,
            fn () => (new MenuRevisions())->publish($menu, $version, Craft::$app->getUser()->getId()),
            'Menu draft published.',
            false,
        );
    }

    public function actionDiscard(): Response
    {
        $menu = $this->_postedMenu();
        $version = $this->_postedVersion();

        return $this->_change(
            $menu,
            fn () => (new MenuRevisions())->discard($menu, $version),
            'Menu draft discarded.',
            false,
        );
    }

    private function _postedMenu(): OlivemenusModel
    {
        $this->requirePostRequest();

        return $this->editableMenu($this->positiveId($this->request->getRequiredBodyParam('menuId')));
    }

    private function _postedVersion(): string
    {
        $version = $this->request->getRequiredBodyParam('draftVersion');
        if (!is_string($version)) {
            throw new BadRequestHttpException(Craft::t('olivemenus', 'Invalid draft version.'));
        }

        return $version;
    }

    private function _change(OlivemenusModel $menu, callable $change, string $success, bool $toDraft): Response
    {
        try {
            $change();
        } catch (ConflictHttpException $exception) {
            return $this->_changeFailure($menu, $exception->getMessage());
        } catch (RuntimeException|DbException $exception) {
            Craft::error($exception->getMessage(), __METHOD__);

            return $this->_changeFailure($menu, Craft::t('olivemenus', 'The menu draft could not be updated.'));
        }

        $url = UrlHelper::cpUrl('olivemenus/menu-items/' . $menu->id, $toDraft ? ['draft' => 1] : []);

        return $this->asSuccess(Craft::t('olivemenus', $success), redirect: $url);
    }

    private function _changeFailure(OlivemenusModel $menu, string $message): Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asFailure($message);
        }

        $this->setFailFlash($message);
        $hasDraft = (new MenuRevisions())->hasDraft($menu->id);

        return $this->redirect(UrlHelper::cpUrl('olivemenus/menu-items/' . $menu->id, $hasDraft ? ['draft' => 1] : []));
    }
}
