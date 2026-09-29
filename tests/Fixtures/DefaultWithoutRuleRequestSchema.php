<?php

declare(strict_types=1);

namespace PhpSoftBox\Request\Tests\Fixtures;

use PhpSoftBox\Request\RequestSchema;
use PhpSoftBox\Validator\Rule\StringValidation;

/**
 * Схема с умолчанием для поля без правила — ошибка настройки.
 */
final class DefaultWithoutRuleRequestSchema extends RequestSchema
{
    public function defaults(): array
    {
        return ['page' => 1];
    }

    public function rules(): array
    {
        return ['status' => [new StringValidation()]];
    }
}
