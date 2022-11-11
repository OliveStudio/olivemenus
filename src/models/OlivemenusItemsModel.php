<?php
/**
 * Olivemenus plugin for Craft CMS 3.x
 *
 * OliveStudio menu
 *
 * @link      http://www.olivestudio.net/
 * @copyright Copyright (c) 2018 Olivestudio
 */

namespace olivestudio\olivemenus\models;

use olivestudio\olivemenus\Olivemenus;

use Craft;
use craft\base\Model;

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
    public $id;

    /**
     * Menu_id attribute
     *
     * @var int
     */
    public $menu_id;

    /**
     * Parent_id attribute
     *
     * @var int
     */
    public $parent_id;

    /**
     * Item_order attribute
     *
     * @var int
     */
    public $item_order;

    /**
     * Name attribute
     *
     * @var string
     */
    public $name;

    /**
     * Entry_id attribute
     *
     * @var int
     */
    public $entry_id;

     /**
     * Custom_url attribute
     *
     * @var string
     */
    public $custom_url;

    /**
     * Class attribute
     *
     * @var string
     */
    public $class;

    /**
     * Class_parent attribute
     *
     * @var string
     */
    public $class_parent;

    /**
     * Data_json attribute
     *
     * @var string
     */
    public $data_json;

    /**
     * Target attribute
     *
     * @var string
     */
    public $target;

    public $dateCreated;

    public $dateUpdated;

    public $uid;

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
    public function rules()
    {
        return [
            [['id', 'menu_id', 'parent_id', 'item_order', 'entry_id'], 'integer'],
            [['name', 'custom_url', 'class', 'class_parent', 'data_json', 'target'], 'string'],
            [['menu_id', 'parent_id', 'item_order', 'name'], 'required'],
        ];
    }
}
