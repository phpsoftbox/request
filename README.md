# Request

## About

`phpsoftbox/request` — тонкая оболочка над PSR‑7 запросом с удобными методами доступа к данным и встроенной валидацией через `phpsoftbox/validator`.

Ключевые возможности:
- единый доступ к query/body/cookies/files/attributes;
- валидация через `Request::validate()` и `RequestSchema`;
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

## Оглавление

- [Документация](docs/index.md)
