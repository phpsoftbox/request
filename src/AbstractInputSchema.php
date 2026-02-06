<?php

declare(strict_types=1);

namespace PhpSoftBox\Request;

use PhpSoftBox\Collection\Collection;
use PhpSoftBox\Validator\AbstractFormValidation;
use PhpSoftBox\Validator\Exception\ValidationException;
use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;
use PhpSoftBox\Validator\Validator;
use PhpSoftBox\Validator\ValidatorInterface;

abstract class AbstractInputSchema extends AbstractFormValidation
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        protected array $payload,
        private readonly ValidatorInterface $validator = new Validator(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function validate(?ValidationOptions $options = null): array
    {
        $result = $this->validationResult($options);
        if ($result->hasErrors()) {
            throw new ValidationException($result);
        }

        return $result->filteredData();
    }

    public function validationResult(?ValidationOptions $options = null): ValidationResult
    {
        $this->beforeValidation();

        $result = $this->validator->validate(
            data: $this->payload,
            rules: $this->rules(),
            messages: $this->messages(),
            attributes: $this->attributes(),
            options: $options,
            context: $this->validationContext(),
        );

        $this->setValidationResult($result);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function replacePayload(array $payload): void
    {
        $this->payload = $payload;
    }

    /**
     * @param array<string, mixed> $patch
     */
    protected function mergePayload(array $patch): void
    {
        $this->payload = Collection::from($this->payload)
            ->merge($patch, ['recursive' => true])
            ->all();
    }

    protected function validationContext(): mixed
    {
        return $this->payload;
    }
}
