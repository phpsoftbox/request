<?php

declare(strict_types=1);

namespace PhpSoftBox\Request;

/**
 * Часть схемы со значениями по умолчанию для непереданных полей.
 */
interface InputSchemaDefaultsInterface
{
    /**
     * Ключ — dot-путь поля (`status`, `items.*.quantity`), значение подставляется, только если путь отсутствует в
     * payload. У каждого пути должно быть правило в `rules()`.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array;
}
