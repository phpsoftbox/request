<?php

declare(strict_types=1);

namespace PhpSoftBox\Request;

interface InputSchemaPartInterface
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * @return array<string, callable(mixed): mixed|list<callable(mixed): mixed>>
     */
    public function filters(): array;

    /**
     * @return array<string, mixed>
     */
    public function messages(): array;

    /**
     * @return array<string, string>
     */
    public function attributes(): array;

    /**
     * Значения по умолчанию для непереданных полей. Ключ — dot-путь поля (`status`, `items.*.quantity`), значение
     * подставляется, только если путь отсутствует в payload. У каждого пути должно быть правило в `rules()`.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array;
}
