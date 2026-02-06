# Документация

- [Request](#request)
- [RequestSchema](#requestschema)
- [Валидация](#валидация)
- [Фильтры](#фильтры)

## Request

`Request` объединяет данные из query/body/cookies/files/attributes и предоставляет методы:

- `all()` — объединённые данные;
- `input($path, $default)` — доступ по пути;
- `query()` / `body()` / `cookies()` / `files()` / `attributes()` — отдельные источники;
- `psr()` — оригинальный PSR‑7 запрос.

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

## Валидация

`Request::validate()` бросает `ValidationException`, если правила не проходят.  
Альтернатива — `validationResult()` с ручной обработкой ошибок.

## Фильтры

`Request::filter()` позволяет преобразовать значения до валидации.

```php
use PhpSoftBox\Validator\Filter\TrimFilter;
use PhpSoftBox\Validator\Filter\PhoneFilter;

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
