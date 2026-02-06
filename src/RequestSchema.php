<?php

declare(strict_types=1);

namespace PhpSoftBox\Request;

use PhpSoftBox\Validator\Exception\ValidationException;
use PhpSoftBox\Validator\Support\DataPath;
use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;

abstract class RequestSchema
{
    private ?ValidationResult $result = null;

    public function __construct(
        protected Request $request,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

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

    public function beforeValidation(): void
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function validate(?ValidationOptions $options = null): array
    {
        $this->beforeValidation();

        $result = $this->request->validationResult(
            $this->rules(),
            $this->messages(),
            $this->attributes(),
            $options,
        );

        $this->result = $result;

        if ($result->hasErrors()) {
            throw new ValidationException($result);
        }

        return $result->filteredData();
    }

    public function validationResult(?ValidationOptions $options = null): ValidationResult
    {
        $this->beforeValidation();

        $this->result = $this->request->validationResult(
            $this->rules(),
            $this->messages(),
            $this->attributes(),
            $options,
        );

        return $this->result;
    }

    /**
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->result?->filteredData() ?? [];
    }

    public function get(string $key, mixed $defaultValue = null): mixed
    {
        return DataPath::get($this->validated(), $key, $defaultValue);
    }

    public function request(): Request
    {
        return $this->request;
    }
}
