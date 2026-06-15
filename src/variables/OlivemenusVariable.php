<?php
/**
 * Olivemenus plugin for Craft CMS 5.x
 *
 * OliveStudio menu
 *
 * @link      http://www.olivestudio.net/
 * @copyright Copyright (c) 2018 Olivestudio
 */

namespace olivestudio\olivemenus\variables;

use olivestudio\olivemenus\Olivemenus;

use Craft;
use Twig\Markup;

/**
 * Olivemenus Variable
 *
 * Craft allows plugins to provide their own template variables, accessible from
 * the {{ craft }} global variable (e.g. {{ craft.olivemenus }}).
 *
 * https://craftcms.com/docs/plugins/variables
 *
 * @author    Olivestudio
 * @package   Olivemenus
 * @since     1.0.0
 */
class OlivemenusVariable
{
    // Public Methods
    // =========================================================================

    /**
     * Whatever you want to output to a Twig template can go into a Variable method.
     * You can have as many variable functions as you want.  From any Twig template,
     * call it like this:
     *
     *     {{ craft.olivemenus.getMenuHTML }}
     *
     * Or, if your variable requires parameters from Twig:
     *
     *     {{ craft.olivemenus.getMenuHTML(twigValue) }}
     *
     * @param string $handle
     * @param array $config
     *
     * @return string
     */

    public function getMenuHTML(string $handle, array $config = [], ?int $siteId = null): Markup|string
    {
        if ($handle != '') {
            return new Markup(
                Olivemenus::$plugin->olivemenus->getMenuHTML($handle, $config, $siteId),
                Craft::$app->charset
            );
        }

        return '';
    }

    /**
     * From any Twig template, call it like this:
     *
     *     {{ craft.olivemenus.getMenuData(menuHandle) }}
     *
     * @param String $handle The handle of the menu you want the data for.
     *
     * @return Mixed The data of the menu you wrote or a string with a notice.
     */

    public function getMenuData(string $handle, ?int $siteId = null): array
    {
        if ($handle != '') {
            return Olivemenus::$plugin->olivemenus->getMenuData($handle, $siteId);
        }

        return [];
    }
}
