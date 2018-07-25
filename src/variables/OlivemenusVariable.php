<?php
/**
 * Olivemenus plugin for Craft CMS 3.x
 *
 * OliveStudio menu
 *
 * @link      http://www.olivestudio.net/
 * @copyright Copyright (c) 2018 Olivestudio
 */

namespace olivestudio\olivemenus\variables;

use olivestudio\olivemenus\Olivemenus;

use Craft;

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
     * @param null $handle
     * @param array $config
     *
     * @return string
     */

    public function getMenuHTML($handle, $config = array())
    {
        if ($handle != '') {
            return Olivemenus::$plugin->olivemenus->getMenuHTML($handle, $config);
        }
    }
}
