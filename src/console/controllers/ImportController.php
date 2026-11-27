<?php

namespace olivestudio\olivemenus\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use olivestudio\olivemenus\services\VerbbNavigationImportService;
use RuntimeException;
use yii\base\UserException;
use yii\console\ExitCode;
use yii\db\Exception as DbException;

final class ImportController extends Controller
{
    /**
     * @var string Use a different handle for the new menu.
     */
    public string $targetHandle = '';

    /**
     * @var string Use a different name for the new menu.
     */
    public string $targetName = '';

    /**
     * @var bool Validate the menu and show the import summary without saving it.
     */
    public bool $dryRun = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return [...parent::options($actionID), 'targetHandle', 'targetName', 'dryRun'];
    }

    /**
     * Copy a Verbb Navigation 3.x menu into Olive Menus for one Craft site.
     *
     * @param string $navigationHandle The existing Navigation menu handle
     * @param string $siteHandle The Craft site handle to copy
     */
    public function actionVerbb(string $navigationHandle, string $siteHandle): int
    {
        $service = new VerbbNavigationImportService();

        try {
            $plan = $service->prepare(
                $navigationHandle,
                $siteHandle,
                $this->targetHandle !== '' ? $this->targetHandle : null,
                $this->targetName !== '' ? $this->targetName : null,
            );
            foreach ($plan['warnings'] as $warning) {
                $this->stderr($warning . PHP_EOL, Console::FG_YELLOW);
            }

            if (!empty($plan['problems'])) {
                foreach ($plan['problems'] as $problem) {
                    $this->stderr($problem . PHP_EOL, Console::FG_RED);
                }

                $this->stderr('The menu was not imported. Resolve the reported nodes and try again.' . PHP_EOL, Console::FG_RED);
                return ExitCode::DATAERR;
            }

            $count = count($plan['items']);
            $handle = $plan['menu']->handle;

            if ($this->dryRun) {
                $this->stdout("Dry run: $count item(s) can be imported as \"$handle\" on \"$siteHandle\"." . PHP_EOL, Console::FG_GREEN);
                return ExitCode::OK;
            }

            $menuId = $service->save($plan['menu'], $plan['items']);
            $this->stdout("Imported $count item(s) into \"$handle\" on \"$siteHandle\" (menu ID $menuId)." . PHP_EOL, Console::FG_GREEN);

            return ExitCode::OK;
        } catch (UserException $exception) {
            $this->stderr($exception->getMessage() . PHP_EOL, Console::FG_RED);
            return ExitCode::DATAERR;
        } catch (DbException|RuntimeException $exception) {
            Craft::error($exception->getMessage(), __METHOD__);
            $this->stderr('The import could not be completed. See the Craft logs for details.' . PHP_EOL, Console::FG_RED);
            return ExitCode::SOFTWARE;
        }
    }
}
