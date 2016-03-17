<?php
/**
 * Created by Anton Korniychuk <ancor.dev@gmail.com>.
 */
namespace ancor\bitmask;

use Yii;
use yii\base\Behavior;
use yii\base\InvalidConfigException;
use yii\db\ActiveRecord;


/**
 * # Bitmask Behavior and Validators for Yii 2
 *
 * ## Model configure
 *
 * ```php
 * use ancor\bitmask\BitmaskBehavior;
 *
 * /**
 *  * @property integer $options
 *  * ...
 *  * @property string $spamOption
 *  * @property string $deletedOption
 *  * ...
 *  * /
 * class User extends \yii\db\ActiveRecord
 * {
 *     const OPT_SPAM    = 1<<0;
 *     const OPT_DELETED = 1<<1;
 *
 *     public function behaviors()
 *     {
 *         return [
 *             'bitmask' => [
 *                 'options' => [
 *                     'spamOption'    => static::OPT_SPAM,
 *                     'deletedOption' => static::OPT_DELETED,
 *                 ],
 *                 // 'bitmaskAttribute' => 'options', // an attribute which is the mask itself
 *             ],
 *         ];
 *     }
 *
 *     public function rules()
 *     {
 *         return [
 *             [['spamOption', 'deletedOption'], 'safe'],
 *         ];
 *     }
 *
 *     public function attributeLabels()
 *     {
 *         return [
 *             ...
 *             'spamOption'       => 'This user is spammer',
 *             'emailNotVerified' => 'User is deleted',
 *             ...
 *         ];
 *     }
 * }
 * ```
 *
 * ## Usage
 *
 * ```php
 * $model = new User();
 *
 * echo $model->options; // 0
 *
 * // Назначить бит
 * $model->spamOption = true; // $model->options == User::OPT_SPAM == 1<<0 == 1
 * // Это эквивалентно следующей строке
 * $model->options = $model->options | User::OPT_SPAM;
 *
 * // Снять бат
 * $model->spamOption = false; // $model->options == 0
 * // Это эквивалентно следующей строке
 * $model->options = $model->options & ~User::OPT_SPAM;
 *
 * // Проверить наличие бита
 * if ($model->spamOption) ...
 * // Это эквивалентно следующей строке
 * if ($model->options & User::OPT_SPAM) ...
 * ```
 *
 * ### Групповое присвоение
 *
 * ```php
 * // Предположим пришла форма
 * $post = [
 *   'User' => [
 *     ...
 *     'spamOption'    => false,
 *     'deletedOption' => true,
 *     ...
 *   ],
 * ];
 *
 * /**
 *  * Massive assignment
 *  * /
 * echo $model->options; // 0
 * $model->load($post);
 *
 * echo $model->options; // $model->options == 1<<1 == 2
 * var_dump($model->spamOption);    // false
 * var_dump($model->deletedOption); // true
 *
 * /**
 *  * Also you can get value of old attribute.
 *  * /
 * $model->getOldBit('spamOption');
 * ```
 * @todo: Initialization value is not reflected in the $bitmaskAttribute.
 *
 * @property integer[] bitmaskFields read only. Key is name, value is bit-number
 * @property boolean[] bitmaskValues read only. Key is name, value is boolean value
 *
 * @package ancor/bitmask
 */
class BitmaskBehavior extends Behavior
{
    /**
     * @var mixed[] field names with correspond bits and default values
     * @example: [
     *     'banOption'            => [static::OPT_BAN, true],       // default true
     *     'adminOption'          => [static::OPT_ADMIN, false],    // default false
     *     'isConfidantOption'    => [static::OPT_IS_CONFIDANT],    // default false
     *     'emailNotVerifyOption' => static::OPT_EMAIL_NOT_VERIFY,  // default false
     *     ...
     * ]
     */
    public $fields;
    /**
     * @var string Bitmask attribute name in model
     */
    public $bitmaskAttribute = 'options';


    /**
     * @var boolean[] values of fields. This array will be filled with EVENT_AFTER_FIND
     * @example: [
     *               'banOptions' => true,
     *               'emailNotVerifyOptions' => false,
     *           ]
     */
    private $_values;
    /**
     * @var integer[] bits for bit mask, without default value
     */
    private $_fields;


    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
        ];
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        if ($this->fields === null) {
            throw new InvalidConfigException('The "fields" property must be set.');
        }

        foreach ($this->fields as $name => $field) {
            if (is_array($field)) {
                if ( !isset($field[0])) {
                    throw new InvalidConfigException('The "' . $name . '" field MUST have bit mask.');
                }
                $this->_fields[$name] = $field[0];
                $this->_values[$name] = isset($field[1]) ? $field[1] : false; // bitmask_field will be updated too
            } else {
                $this->_fields[$name] = $field;
                $this->_values[$name] = false;
            }
        }
    }

    /**
     * Parse bitmask and fill bitmask-values array. Based on bitmask-field names array
     *
     * @param int       $bitmask
     * @param integer[] $fields
     *
     * @return boolean[]
     */
    public static function parseBitmask($bitmask, array $fields)
    {
        $values = [];
        foreach ($fields as $name => $bit) {
            $values[$name] = (bool)($bitmask & $bit);
        }

        return $values;
    } // end parseBitmask()

    /**
     * Make bitmask from bitmask-values array and bitmask-field names array
     *
     * @param boolean[] $values
     * @param int[]     $fields
     *
     * @return int      bitmask
     */
    public static function makeBitmask(array $values, array $fields)
    {
        $bitmask = 0;
        foreach ($values as $field => $checked) {
            if ($checked && isset($fields[$field])) $bitmask |= $fields[$field];
        }

        return $bitmask;
    } // end makeBitmask()

    /**
     * Modify bit mask. Add or delete one bit
     *
     * @param integer $bitmask
     * @param integer $bit    modifying bit
     * @param boolean $exists bit must be set? or not?
     *
     * @return int    Result bit mask
     */
    public static function modifyBitmask($bitmask, $bit, $exists)
    {
        return $exists ? $bitmask | $bit : $bitmask & ~$bit;
    } // end modifyBitmask()

    /**
     * Get bitmask fields array
     * @return integer[]
     */
    public function getBitmaskFields()
    {
        return $this->_fields;
    } // end getBitmaskFields()

    /**
     * Get bitmask values array
     * @return boolean[]
     */
    public function getBitmaskValues()
    {
        return $this->_values;
    } // end getBitmaskValues()

    /**
     * Get value of old bitmask field
     *
     * @param string $field field name
     *
     * @return bool|null is this bit checked in old bitmask?
     */
    public function getOldBit($field)
    {
        /** @var ActiveRecord $model */
        $model = $this->owner;

        $oldBitmask = $model->getOldAttribute($this->bitmaskAttribute);
        $oldValues = static::parseBitmask($oldBitmask, $this->_fields);

        return isset($oldValues[$field]) ? $this->_values[$field] : null;
    } // end getOldBit()

    /**
     * @return void
     */
    public function afterFind()
    {
        $bitmask = (int)$this->owner->{$this->bitmaskAttribute};

        $this->_values = static::parseBitmask($bitmask, $this->_fields);
    } // end afterFind()

    /**
     * @inheritdoc
     */
    public function canGetProperty($name, $checkVars = true)
    {
        return isset($this->_fields[$name]) ?: parent::canGetProperty($name, $checkVars);
    }

    /**
     * @inheritdoc
     */
    public function canSetProperty($name, $checkVars = true)
    {
        return isset($this->_fields[$name]) ?: parent::canSetProperty($name, $checkVars);
    }

    /**
     * @inheritdoc
     */
    public function __get($name)
    {
        return isset($this->_values[$name]) ? $this->_values[$name] : parent::__get($name);
    }

    /**
     * @param string  $name  field name
     * @param boolean $value bit checked(exists) or not
     */
    public function __set($name, $value)
    {
        /* @var ActiveRecord $owner */
        $owner = $this->owner;
        $value = (bool)$value;

        if (isset($this->_fields[$name])) {
            $this->_values[$name] = $value;

            $owner->{$this->bitmaskAttribute} = static::modifyBitmask($owner->{$this->bitmaskAttribute}, $this->_fields[$name], $value);
        } else {
            parent::__set($name, $value);
        }
    }
}