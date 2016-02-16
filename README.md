# Bitmask Behavior and Validators for Yii 2

## Описание

Расширение предназначено для работы с битовыми масками.
На пример, вы хотите хранить значения нескольких переключателей в поле типа int.
Возникает ряд вопросов:

- Как в соответствии со сценариями разрешить присваивать\снимать только некоторые биты, а остальные запретить
- Как привязать input[type=checkbox] теги к соответствующим битам
- Где хранить подписи для полей ввода
- Как выводить отмеченные биты
- Как удобно проверить наличие бита в поле у модели
- Ну и наконец как удобно ставить\снимать бит

**yii2-bitmask** дает решает все эти вопросы


Feel free to let me know what else you want added via:

- [Issues](https://github.com/ancor-dev/yii2-bitmask/issues)

## Installation

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```bash
$ php composer.phar require ancor/yii2-bitmask
```

or add

```
"ancor/yii2-bitmask": "dev-master"
```

to the `require` section of your `composer.json` file.

### Расширение содержит три класса

- [BitmaskBehavior](#bitmaskbehavior) - создание полей модели на основе битовой маски
- [BitmaskValidator](#bitmaskvalidator) - валидация основанная на битах
- [BitmaskFieldsValidator](#bitmaskfieldsvalidator) - валидация основанная на названиях полей


## BitmaskBehavior

### Настройка модели

```php
use ancor\bitmask\BitmaskBehavior;

/**
 * @property integer $options
 * ...
 * @property string $spamOption
 * @property string $deletedOption
 * ...
 */
class User extends \yii\db\ActiveRecord
{
    const OPT_SPAM    = 1<<0;
    const OPT_DELETED = 1<<1;

    public function behaviors()
    {
        return [
            'bitmask' => [
                'options' => [
                    'spamOption'    => static::OPT_SPAM,
                    'deletedOption' => static::OPT_DELETED,
                ],
                // 'bitmaskAttribute' => 'options', // an attribute which is the mask itself
            ],
        ];
    }

    public function rules()
    {
        return [
            [['spamOption', 'deletedOption'], 'safe'],
        ];
    }

    public function attributeLabels()
    {
        return [
            ...
            'spamOption'       => 'This user is spammer',
            'emailNotVerified' => 'User is deleted',
            ...
        ];
    }
}
```

### Пример использования

```php
$model = new User();

echo $model->options; // 0

// Назначить бит
$model->spamOption = true; // $model->options == User::OPT_SPAM == 1<<0 == 1
// Это эквивалентно следующей строке
$model->options = $model->options | User::OPT_SPAM;

// Снять бат
$model->spamOption = false; // $model->options == 0
// Это эквивалентно следующей строке
$model->options = $model->options & ~User::OPT_SPAM;

// Проверить наличие бита
if ($model->spamOption) ...
// Это эквивалентно следующей строке
if ($model->options & User::OPT_SPAM) ...
```

### Групповое присвоение

```php
// Предположим пришла форма
$post = [
    'User' => [
        ...
        'spamOption'    => false,
        'deletedOption' => true,
        ...
    ],
];

/**
 * Загрузка одной коммандой
 */
echo $model->options; // 0
$model->load($post); 

echo $model->options; // $model->options == 1<<1 == 2
var_dump($model->spamOption);    // false
var_dump($model->deletedOption); // true


/**
 * Пример БЕЗ BitmaskBehavior, при отправке той же самой формы
 * Предположим что в модели объявлены 2 свойства
 *   public $spamOption;
 *   public $deletedOption;
 */

echo $model->options; // 0
$model->load($post);

// Свойство options, конечно же осталось без изменений
echo $model->options; // 0

if ($post['User']['spamOption']) {
	$model->options |= User::OPT_SPAM;
} else {
	$model->options &= ~User::OPT_SPAM;
}
if ($post['User']['deletedOption']) {
	$model->options |= User::OPT_DELETED;
} else {
	$model->options &= ~User::OPT_DELETED;
}
```

### Пример шаблона с использованием ActiveForm

```php
$form->field($model, 'spamOption')->checkbox();
$form->field($model, 'deletedOption')->checkbox();
```

**Примечание:** Если вы хотите запретить менять некоторые биты - то просто не нужно перечислять их в валидаторе `safe`

### Новые методы и свойства модели

```php public integer[] getBitmaskFields(void) ```

Метод возвращает массив с наименованиями полей и соответствующими им битами. *Без значений по умолчанию*

Пример ответа(в нашем случае):
```php
[
    'spamOption'    => 1, // 1<<0
    'deletedOption' => 2, // 1<<1
]
```

```php public boolean[] getBitmaskValues(void) ```
Метов возвращает наименования полей с их значениями true\false.

Пример ответа(в нашем случае):
```php
[
    'spamOption'    => false,
    'deletedOption' => true,
]
```


### Собственные статические методы поведения

#### Добавить бит в маску\Убрать биз из маски
```php
public static integer modifyBitmask(int $mask, int $bit, boolean $exists)
```
```php
$mask = 0b00100001;
$bit  = 0b00000100;

// Добавить бит
$options = BitmaskBehavior::modifyBitmask($mask, $bit, true); // 0b00100101
// Убрать бит
$options = BitmaskBehavior::modifyBitmask($mask, $bit, false); // 0b00100001
```
#### Получить массив битов на основе битовой маски
```php
public static boolean[] parseBitmask(int $mask, int[] $fields)
```
```php
$mask = 0b00100000;
$fields = [
    'firstOption'  => 0b00000001,
    'secondOption' => 0b00100000,
];

$values = BitmaskBehavior::parseBitmask($mask, $fields);
print_r($values); // ['firstOption' => false, 'secondOption' => true]
```

#### Создать битовую маску на основе массива битов
```php public static int makeBitmask(boolean[] $values, int[] $fields) ```
```php
$fields = [
    'firstOption'  => 0b00000001,
    'secondOption' => 0b00100000,
];
$values = [
    'firstOption'  => false,
    'secondOption' => true
];

$mask = BitmaskBehavior::makeBitmask($values, $fields);
echo $mask; // 0b00100000
```

## BitmaskValidator

**Описание:** Позволяет указать какие биты можно менять в маске, а какие нельзя.

**Примечание:** Этот валидатор можно использовать без BitmaskBehavior.

```php
public function rules()
{
    return [
        ['options', BitmaskValidator::className(), 'mask' => 1<<3 | 1<<4 | 1<<6],
        // Или с переводом сообщения
        ['options', BitmaskValidator::className(), 'mask' => 1<<3 | 1<<4 | 1<<6, 'message' => ...],
        // Можно так же использовать константы
        ['options', BitmaskValidator::className(), 'mask' => static::OPT_SPAM],
    ];
}
```

## BitmaskFieldsValidator

**Описание:**

+ Этот валидатор поход на `BitmaskValidator`.
+ Предназначен для использования в паре с `BitmaskBehavior`.
+ Используется вместо `safe` валидатора. Но это не все.
+ Валидатор точно так же как `BitmaskValidator` блокирует изменение всех битов к кроме тех к которым валидатор применен.

**Пример использования**
```php
return [
    [
        ['spamOption', 'deletedOption'],
        BitmaskFieldsValidator::className(),
        // 'maskAttribute' => 'options', // По умолчанию
    ],
];
```
