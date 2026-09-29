<?php

declare(strict_types=1);

namespace PhpSoftBox\Request\Tests\Fixtures;

use PhpSoftBox\Filter\TrimFilter;
use PhpSoftBox\Request\RequestSchema;
use PhpSoftBox\Validator\Rule\ArrayValidation;
use PhpSoftBox\Validator\Rule\BoolValidation;
use PhpSoftBox\Validator\Rule\IntValidation;
use PhpSoftBox\Validator\Rule\StringValidation;

/**
 * Схема со значениями по умолчанию: запоминает payload, который увидел beforeValidation().
 */
final class DefaultsRequestSchema extends RequestSchema
{
    /**
     * @var array<string, mixed>
     */
    public array $seenInHook = [];

    public function defaults(): array
    {
        return [
            'status'           => ' all ',
            'page'             => 1,
            'only_my'          => false,
            'items.*.quantity' => 1,
        ];
    }

    public function filters(): array
    {
        return ['status' => [new TrimFilter()]];
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

    public function beforeValidation(): void
    {
        $this->seenInHook = $this->payload();
    }
}
