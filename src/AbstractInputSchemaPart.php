<?php

declare(strict_types=1);

namespace PhpSoftBox\Request;

/**
 * Базовый класс переиспользуемой части схемы: обязательны только правила, остальное по умолчанию пусто.
 *
 * Часть схемы — неизменяемое описание, поэтому класс readonly: наследники тоже объявляются readonly.
 */
abstract readonly class AbstractInputSchemaPart implements InputSchemaPartInterface
{
    /**
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

    /**
     * @return array<string, callable(mixed): mixed|list<callable(mixed): mixed>>
     */
    public function filters(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [];
    }
}
