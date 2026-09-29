# Документация

- [Request](#request)
- [RequestSchema](#requestschema)
- [Валидация](#валидация)
- [Фильтры](#фильтры)
- [Композиция схем](#композиция-схем)

## Request

`Request` объединяет входные данные из query/body/files и параметров маршрута и предоставляет методы:

- `all()` — объединённые входные данные (query < body < files < параметры маршрута);
- `input($path, $default)` / `has($path)` — доступ по пути внутри `all()`;
- `routeParams()` — параметры маршрута из атрибута `_route_params` (константа `Request::ROUTE_PARAMS_ATTRIBUTE`);
- `query()` / `body()` / `cookies()` / `files()` / `attributes()` — отдельные источники;
- `psr()` — оригинальный PSR‑7 запрос.

Cookies и атрибуты PSR-запроса в `all()` не входят: идентификатор сессии, remember-token, объект пользователя
и служебные атрибуты маршрута не должны попадать в валидацию и `withInput()`, а правило `Present('token')`
не должно выполняться за счёт cookie. Такие данные читаются явно через `cookies()` / `attributes()`.
Параметры маршрута включены и перекрывают одноимённые поля body и query.

## RequestSchema

`RequestSchema` — базовый класс для описания правил валидации в одном месте:

```php
final class ProfileRequest extends RequestSchema
{
    public function rules(): array
    {
        return [
            'name' => [new PresentValidation(), new FilledValidation(), new StringValidation()],
        ];
    }
}
```

### Порядок обработки

`process()` (и `validate()` / `validationResult()`) выполняет шаги:

1. загружает payload из `Request` (исходные данные запроса, см. ниже);
2. подставляет `defaults()` в непереданные поля (см. «Значения по умолчанию»);
3. вызывает `beforeValidation()`;
4. применяет `filters()` из definition;
5. валидирует payload по `rules()`.

### Значения по умолчанию

`defaults()` задаёт значения для полей, которых нет в запросе:

```php
final class SupportFiltersRequest extends RequestSchema
{
    public function defaults(): array
    {
        return ['status' => 'all', 'page' => 1, 'only_my' => false, 'items.*.quantity' => 1];
    }

    public function rules(): array
    {
        return [
            'status'           => [new StringValidation()],
            'page'             => [new IntValidation()],
            'only_my'          => [new BoolValidation()],
            'items'            => [new ArrayValidation()],
            'items.*.quantity' => [new IntValidation()],
        ];
    }
}
```

- умолчание подставляется, только если пути нет в payload; переданные `null` и `''` не заменяются — это задача
  фильтров (`DefaultFilter`);
- подстановка идёт до `beforeValidation()` и фильтров: хук и фильтры видят значение, правила проверяют его как
  переданное, поле попадает в `validated()` / `filteredData()`;
- wildcard-путь (`items.*.quantity`) заполняет только существующие элементы-массивы; пустой или отсутствующий
  список не заполняется;
- у каждого пути умолчания должно быть правило в `rules()`, иначе `LogicException`: без правила `validated()`
  отбросит поле, и умолчание молча потеряется;
- умолчания входят в definition: `only()` / `except()` / `merge()` учитывают их так же, как правила
  (`InputSchemaDefinition::make(defaults: …)`, `withDefaults()`, `mergeDefaults()`); часть схемы задаёт их в
  `defaults()`.

Умолчания через фильтры тоже работают (`DefaultFilter('all')`, `IntegerFilter(1)` для непереданного поля — см.
README `phpsoftbox/validator`), но `defaults()` отделяет «подставить отсутствующее» от «нормализовать переданное».

Payload схемы и данные `Request` синхронизируются, поэтому в `beforeValidation()` можно менять данные любым
способом — правки не теряются:

```php
public function beforeValidation(): void
{
    // Через payload схемы.
    $this->mergePayload(['status' => $this->payload()['status'] ?? 'all']);

    // Или через Request, как раньше.
    $this->request->filter(['name' => [new TrimFilter()]]);
}
```

После обработки отфильтрованный payload записывается в `Request`. Повторный `process()` и копии схемы
(`only()`, `except()`, `merge()`) начинают с исходных данных запроса, а не с уже отфильтрованных, поэтому
фильтры не применяются дважды. Если `Request` изменили снаружи схемы (например, `$request->merge()` в action
после создания схемы), при следующей обработке будут использованы актуальные данные `Request`.

В `RequestSchema` доступны helpers для параметров маршрута:

- `route()->has(string $param)` — проверяет, передан ли route-параметр;
- `route()->get(string $param, mixed $default = null)` — возвращает route-параметр или default;
- `route()->require(string $param)` — возвращает route-параметр или выбрасывает
  `MissingRouteParameterException`;
- `route()->entity(string $param, string $entityClass)` — возвращает resolved entity или выбрасывает
  `UnexpectedRouteParameterException`;
- `route()->entityOrNull(string $param, string $entityClass)` — возвращает resolved entity или null,
  если route-параметра нет;
- `route()->key(string $param, ?string $entityClass = null, ?callable $extractor = null)` —
  возвращает обязательный route key как `int|string`.

`entity()` нужен, когда схема зависит от текущего route-ресурса:

```php
if ($this->route()->has('product')) {
    $product = $this->route()->entity('product', Product::class);

    if ($product->archived) {
        // rules for archived product update
    }
}
```

`key()` принимает сырой route key или resolved entity. Для entity ожидаемый класс
проверяется через `instanceof`; ключ берется через явный extractor. Если entity реализует
ORM `EntityInterface`, extractor можно не передавать: будет использован `id()`.

Методы читают `_route_params`, которые выставляет router:

```php
use PhpSoftBox\Validator\Db\Rule\ExistsValidation;

final class ShipmentProductsRequest extends RequestSchema
{
    public function rules(): array
    {
        return [
            'product_ids' => [
                ExistsValidation::make()
                    ->table('shipment_products')
                    ->column('product_id')
                    ->where('shipment_id', $this->route()->key('shipment', Shipment::class))
                    ->all(),
            ],
        ];
    }
}
```

Для кастомного объекта или нестандартного ключа передайте extractor. Если route-параметр
остался сырым scalar key, extractor не вызывается:

```php
$this->route()->key('shipment', Shipment::class, static fn (Shipment $shipment): int => $shipment->id());
$this->route()->key('product');
```

### PhpStorm meta

Если PhpStorm не выводит тип `entity()` / `entityOrNull()` из PHPDoc `@template`,
можно добавить в корень проекта `.phpstorm.meta.php`:

```php
<?php

namespace PHPSTORM_META
{
    override(\PhpSoftBox\Request\RouteParameters::entity(1), map([
        '' => '@',
    ]));

    override(\PhpSoftBox\Request\RouteParameters::entityOrNull(1), map([
        '' => '@|null',
    ]));
}
```

Индекс `1` указывает на второй аргумент метода: `Product::class` в вызове
`entity('product', Product::class)`. После этого IDE должна понимать:

```php
$product = $this->route()->entity('product', Product::class); // Product
$product = $this->route()->entityOrNull('product', Product::class); // Product|null
```

Для `key()` такой override не нужен: метод возвращает `int|string`. Если ключ
извлекается из entity, тип entity задается в extractor:

```php
$productId = $this->route()->key(
    'product',
    Product::class,
    static fn (Product $product): int => $product->id(),
);
```

## Валидация

`Request::validate()` бросает `ValidationException`, если правила не проходят.  
Альтернатива — `validationResult()` с ручной обработкой ошибок.

Для `RequestSchema` и `ApiSchema` основной невыбрасывающий API — `process()`. Он выполняет
весь pipeline схемы, включая фильтры, и всегда возвращает `ValidationResult`:

```php
$result = (new MarketplacePayloadSchema($payload))->process();

if ($result->hasErrors()) {
    return null;
}

$data = $result->filteredData();
```

`validate()` остаётся form-oriented методом и бросает `ValidationException` при любом
неуспешном результате. `validationResult()` делегирует в `process()` для обратной совместимости.

## Фильтры

`Request::filter()` позволяет преобразовать значения до валидации.

```php
use PhpSoftBox\Filter\TrimFilter;
use PhpSoftBox\Filter\PhoneFilter;

$this->request->filter([
    'login' => [new TrimFilter(), new PhoneFilter()],
]);
```

Фильтры — обычные invokable‑классы. Чтобы добавить свой фильтр:

```php
final class SlugFilter
{
    public function __invoke(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}
```

Можно смешивать фильтры‑объекты и обычные функции, главное — чтобы это был callable.

## Композиция схем

`RequestSchema` и `ApiSchema` собирают правила, фильтры, сообщения и атрибуты в `InputSchemaDefinition`.
Это позволяет расширять или урезать схему без наследования и копипасты.

```php
use PhpSoftBox\Request\InputSchemaDefinition;
use PhpSoftBox\Request\AbstractInputSchemaPart;
use PhpSoftBox\Request\RequestSchema;
use PhpSoftBox\Filter\NullIfEmptyFilter;
use PhpSoftBox\Filter\TrimFilter;
use PhpSoftBox\Validator\Rule\FilledValidation;
use PhpSoftBox\Validator\Rule\IntValidation;
use PhpSoftBox\Validator\Rule\PresentValidation;
use PhpSoftBox\Validator\Rule\StringValidation;

final class ProductRequest extends RequestSchema
{
    public function rules(): array
    {
        return [
            'product_id'       => [new PresentValidation(), new FilledValidation(), new IntValidation()],
            'name'             => [new PresentValidation(), new FilledValidation(), new StringValidation()],
            'internal_comment' => [new StringValidation()->nullable()],
        ];
    }

    public function filters(): array
    {
        return [
            'name'             => [new TrimFilter()],
            'internal_comment' => [new TrimFilter(), new NullIfEmptyFilter()],
        ];
    }

    public function attributes(): array
    {
        return [
            'product_id'       => 'ID товара',
            'name'             => 'Название',
            'internal_comment' => 'Внутренний комментарий',
        ];
    }
}

final class ProductBarcodeSchemaPart extends AbstractInputSchemaPart
{
    public function rules(): array
    {
        return [
            'barcode' => [new StringValidation()->nullable()],
        ];
    }

    public function filters(): array
    {
        return [
            'barcode' => [new TrimFilter(), new NullIfEmptyFilter()],
        ];
    }

    public function attributes(): array
    {
        return [
            'barcode' => 'Баркод',
        ];
    }
}

$schema = (new ProductRequest($request))
    ->merge(new ProductBarcodeSchemaPart())
    ->except(['internal_comment']);

$data = $schema->validate();
```

После `merge()` и `except()` в definition останется эквивалент такой схемы:

```php
InputSchemaDefinition::make(
    rules: [
        'product_id' => [new PresentValidation(), new FilledValidation(), new IntValidation()],
        'name'       => [new PresentValidation(), new FilledValidation(), new StringValidation()],
        'barcode'    => [new StringValidation()->nullable()],
    ],
    filters: [
        'name'    => [new TrimFilter()],
        'barcode' => [new TrimFilter(), new NullIfEmptyFilter()],
    ],
    attributes: [
        'product_id' => 'ID товара',
        'name'       => 'Название',
        'barcode'    => 'Баркод',
    ],
);
```

`except()` удаляет поле из definition: правил, фильтров, сообщений и подписей.
Входной payload этим методом не изменяется; если нужно физически убрать данные из запроса, это нужно делать отдельным фильтром или передавать уже очищенный payload.

Все операции ниже не меняют исходную схему, а возвращают её копию с дополнительным мутатором
(исходная схема и её definition остаются прежними). Результат нужно присвоить или сразу использовать:

```php
$schema = $schema->only(['name']); // верно
$schema->only(['name']);           // ничего не изменит
```

Доступные операции:

- `merge($part)` — добавляет rules/filters/messages/attributes/defaults из `InputSchemaDefinition` или `InputSchemaPartInterface`;
- `replaceDefinition($definition)` — полностью заменяет базовую схему;
- `only($paths)` — оставляет выбранные пути и родительские правила;
- `except($paths)` — удаляет выбранные пути и дочерние правила;
- `withMutator($mutator)` — возвращает копию схемы с собственным `InputSchemaMutatorInterface`.

Для переиспользуемых частей можно вынести описание в класс:

```php
final class DriverDataSchemaPart extends AbstractInputSchemaPart
{
    public function rules(): array
    {
        return ['driver_name' => [new StringValidation()->nullable()]];
    }

    public function filters(): array
    {
        return ['driver_name' => [new TrimFilter(), new NullIfEmptyFilter()]];
    }
}
```

`AbstractInputSchemaPart` требует только `rules()`; `filters()`, `messages()`, `attributes()`, `defaults()` по
умолчанию пустые. Если часть реализует `InputSchemaPartInterface` напрямую, нужны все пять методов.

```php
// Своя реализация интерфейса без базового класса.
public function defaults(): array
{
    return [];
}
```
