<?php

/**
 * Olivemenus plugin for Craft CMS 5.x
 *
 * OliveStudio menu
 *
 * @link      http://www.olivestudio.net/
 * @copyright Copyright (c) 2018 Olivestudio
 */

namespace olivestudio\olivemenus\models;

use Craft;

use craft\base\Model;
use olivestudio\olivemenus\Olivemenus;

/**
 * OlivemenusItemsModel Model
 *
 * Models are containers for data. Just about every time information is passed
 * between services, controllers, and templates in Craft, it’s passed via a model.
 *
 * https://craftcms.com/docs/plugins/models
 *
 * @author    Olivestudio
 * @package   Olivemenus
 * @since     1.0.0
 */
class OlivemenusItemsModel extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * Id attribute
     *
     * @var int
     */
    public int $id = 0;

    /**
     * Menu_id attribute
     *
     * @var int
     */
    public int $menu_id = 0;

    /**
     * Parent_id attribute
     *
     * @var int
     */
    public int $parent_id = 0;

    /**
     * Item_order attribute
     *
     * @var int
     */
    public int $item_order = 0;

    /**
     * Name attribute
     *
     * @var string
     */
    public string $name = '';

    /**
     * Entry_id attribute
     *
     * @var int
     */
    public int $entry_id = 0;

    /**
    * Custom_url attribute
    *
    * @var string
    */
    public string $custom_url = '';

    public string $url_suffix = '';

    /**
     * Class attribute
     *
     * @var string
     */
    public string $class = '';

    /**
     * Class_parent attribute
     *
     * @var string
     */
    public string $class_parent = '';

    /**
     * Data_json attribute
     *
     * @var string
     */
    public string $data_json = '';

    /**
     * Target attribute
     *
     * @var string
     */
    public string $target = '';

    public bool $enabled = true;

    public bool $use_element_title = false;

    public bool $hide_when_unavailable = false;

    public mixed $dateCreated = null;

    public mixed $dateUpdated = null;

    public string $uid = '';

    /**
     * Returns the validation rules for attributes.
     *
     * Validation rules are used by [[validate()]] to check if attribute values are valid.
     * Child classes may override this method to declare different validation rules.
     *
     * More info: http://www.yiiframework.com/doc-2.0/guide-input-validation.html
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            [['id', 'menu_id', 'parent_id', 'item_order', 'entry_id'], 'integer'],
            [['name', 'custom_url', 'class', 'class_parent', 'data_json', 'target'], 'string', 'max' => 255],
            ['url_suffix', 'string', 'max' => 255],
            [
                'url_suffix',
                'match',
                'pattern' => '~\A(?:\?[^#\s\p{C}"<>\\\\]+(?:#[^#\s\p{C}"<>\\\\]+)?|#[^#\s\p{C}"<>\\\\]+)\z~u',
                'message' => Craft::t('olivemenus', 'Enter a query or anchor starting with ? or #, without spaces.'),
            ],
            [['enabled', 'use_element_title', 'hide_when_unavailable'], 'boolean'],
            [['menu_id', 'parent_id', 'item_order', 'name'], 'required'],
        ];
    }
}
