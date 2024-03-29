<?php
/**
 * Olivemenus plugin for Craft CMS 4.x
 *
 * OliveStudio menu
 *
 * @link      http://www.olivestudio.net/
 * @copyright Copyright (c) 2018 Olivestudio
 */

namespace olivestudio\olivemenus\models;

use craft\validators\HandleValidator;
use craft\validators\StringValidator;
use olivestudio\olivemenus\Olivemenus;

use Craft;
use craft\base\Model;

/**
 * OlivemenusModel Model
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
class OlivemenusModel extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * Id attribute
     *
     * @var int
     */
    public $id = 0;

    /**
     * Name attribute
     *
     * @var string
     */
    public string $name = '';

     /**
     * Handle attribute
     *
     * @var string
     */
    public string $handle = '';

    public mixed $dateCreated = null;

    public mixed $dateUpdated = null;

    public string $uid = '';

     /**
     * Site Id attribute
     *
     * @var int
     */
    public int $site_id = 0;

    // Public Methods
    // =========================================================================

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
            [['id'], 'integer'],
            [['site_id'], 'integer'],
            [['name', 'handle'], 'string'],
            [['name', 'handle'], 'required'],
            ['handle', 'validateHandle'],
            ['name', 'validateName'],
        ];
    }

    public function validateHandle(): void 
    {

        $validator = new HandleValidator();
        $validator->validateAttribute($this, 'handle');
        $data = Olivemenus::$plugin->olivemenus->getMenuByHandle($this->handle);
        if ($data && $data->id != $this->id) {
            $this->addError('handle', Craft::t('olivemenus', 'Handle "{handle}" is already in use', ['handle' => $this->handle]));
        }

    }
    
    public function validateName(): void 
    {

        $validator = new StringValidator();
        $validator->validateAttribute($this, 'name');
        $data = Olivemenus::$plugin->olivemenus->getMenuByName($this->name);
        if ($data && $data->id != $this->id) {
            $this->addError('name', Craft::t('olivemenus', 'Name "{name}" is already in use', ['name' => $this->name]));
        }

    }
}
