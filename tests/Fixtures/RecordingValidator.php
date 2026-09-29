<?php

declare(strict_types=1);

namespace PhpSoftBox\Request\Tests\Fixtures;

use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;
use PhpSoftBox\Validator\ValidatorInterface;

/**
 * Валидатор-заглушка: запоминает переданные данные и правила и возвращает данные без ошибок.
 */
final class RecordingValidator implements ValidatorInterface
{
    /**
     * @var array<string, mixed>
     */
    public array $lastData = [];

    /**
     * @var array<string, mixed>
     */
    public array $lastRules = [];

    public function validate(
        array $data,
        array $rules,
        array $messages = [],
        array $attributes = [],
        ?ValidationOptions $options = null,
        mixed $context = null,
    ): ValidationResult {
        $this->lastData  = $data;
        $this->lastRules = $rules;

        return new ValidationResult([], $data);
    }
}
