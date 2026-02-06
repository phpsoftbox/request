<?php

declare(strict_types=1);

namespace PhpSoftBox\Request;

use PhpSoftBox\Validator\AbstractFormValidation;
use PhpSoftBox\Validator\Exception\ValidationException;
use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;

abstract class RequestSchema extends AbstractFormValidation
{
    public function __construct(
        protected Request $request,
    ) {
    }

    public function beforeValidation(): void
    {
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

        $result = $this->request->validationResult(
            $this->rules(),
            $this->messages(),
            $this->attributes(),
            $options,
        );

        $this->setValidationResult($result);

        return $result;
    }

    public function request(): Request
    {
        return $this->request;
    }
}
