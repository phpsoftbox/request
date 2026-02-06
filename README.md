# Request

## About

`phpsoftbox/request` — тонкая оболочка над PSR‑7 запросом с удобными методами доступа к данным и встроенной валидацией через `phpsoftbox/validator`.

Ключевые возможности:
- единый доступ к query/body/cookies/files/attributes;
- валидация через `Request::validate()`, `RequestSchema` и `ApiSchema`;
- доступ к оригинальному PSR‑7 запросу через `psr()`.

## Quick Start

```php
use PhpSoftBox\Request\Request;
use PhpSoftBox\Validator\Validator;

$request = new Request($psrRequest, new Validator());

$data = $request->validate([
    'email' => [
        new \PhpSoftBox\Validator\Rule\PresentValidation(),
        new \PhpSoftBox\Validator\Rule\FilledValidation(),
        (new \PhpSoftBox\Validator\Rule\StringValidation())->email(),
    ],
]);
```

## RequestSchema

Если удобнее держать правила в классе:

```php
use PhpSoftBox\Request\RequestSchema;
use PhpSoftBox\Validator\Rule\FilledValidation;
use PhpSoftBox\Validator\Rule\PresentValidation;
use PhpSoftBox\Validator\Rule\StringValidation;

final class LoginRequest extends RequestSchema
{
    public function rules(): array
    {
        return [
            'login' => [new PresentValidation(), new FilledValidation(), new StringValidation()],
            'password' => [new PresentValidation(), new FilledValidation(), new StringValidation()],
        ];
    }
}
```

Далее в контроллере:

```php
$schema = new LoginRequest($request);
$data = $schema->validate();
```

Внутри `RequestSchema` можно читать параметры текущего маршрута через helper `route()`:

- `$this->route()->has('param')` — проверит, передан ли route-параметр;
- `$this->route()->get('param', $default)` — вернет route-параметр или default;
- `$this->route()->require('param')` — вернет route-параметр или выбросит
  `MissingRouteParameterException`;
- `$this->route()->entity('param', Entity::class)` — вернет resolved entity или выбросит
  `UnexpectedRouteParameterException`;
- `$this->route()->entityOrNull('param', Entity::class)` — вернет resolved entity или null,
  если route-параметра нет;
- `$this->route()->key('param')` — вернет обязательный route key как `int|string`.

`entity()` нужен, когда схема зависит от текущего route-ресурса:

```php
if ($this->route()->has('product')) {
    $product = $this->route()->entity('product', Product::class);

    if ($product->archived) {
        // rules for archived product update
    }
}
```

`key()` принимает сырой route key или resolved entity. Для entity класс проверяется через
`instanceof`, а ключ берется через явный extractor. Если entity реализует ORM
`EntityInterface`, extractor можно не передавать: будет использован `id()`.

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

## ApiSchema

Для валидации входящих payload без HTTP-request:

```php
use PhpSoftBox\Request\ApiSchema;

final class MarketplaceCardSchema extends ApiSchema
{
    public function rules(): array
    {
        return [
            'id' => [new \PhpSoftBox\Validator\Rule\PresentValidation(), new \PhpSoftBox\Validator\Rule\IntValidation()],
        ];
    }
}

$schema = new MarketplaceCardSchema($payload);
$data = $schema->validate();
```

`validate()` предназначен для form/request-сценариев и бросает `ValidationException`.
Для компонентного кода, импортов и внешних payload используется невыбрасывающий pipeline:

```php
$result = $schema->process();

if ($result->hasErrors()) {
    // Вызывающий код сам выбирает: report, fallback или исключение.
}

$data = $result->filteredData();
```

`process()` включает `beforeValidation()`, фильтры и валидацию. Ошибки фильтров также
возвращаются в `ValidationResult` и не преобразуются в `ValidationException`.
`validationResult()` сохранён как совместимый alias для `process()`.

## Schema Definition

`RequestSchema` и `ApiSchema` поддерживают композицию правил через `InputSchemaDefinition`.
Схему можно расширять, заменять или сужать перед валидацией:

```php
use PhpSoftBox\Request\InputSchemaDefinition;

$schema = (new TaskTransitionRequest($request))
    ->merge(InputSchemaDefinition::make(
        rules: ['acceptance_data.driver_name' => [new StringValidation()->nullable()]],
        filters: ['acceptance_data.driver_name' => [new TrimFilter(), new NullIfEmptyFilter()]],
    ))
    ->only(['acceptance_data.driver_name']);

$data = $schema->validate();
```

Для переиспользуемых частей схемы можно реализовать `InputSchemaPartInterface`.

## Оглавление

- [Документация](docs/index.md)
