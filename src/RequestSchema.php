<?php

declare(strict_types=1);

namespace PhpSoftBox\Request;

use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;

abstract class RequestSchema extends AbstractInputSchema
{
    public function __construct(
        protected Request $request,
    ) {
        parent::__construct($request->all(), $request->validator());
    }

    public function beforeValidation(): void
    {
    }

    public function validationResult(?ValidationOptions $options = null): ValidationResult
    {
        $this->beforeValidation();
        $this->replacePayload($this->request->all());

        $result = $this->request->validator()->validate(
            data: $this->payload(),
            rules: $this->rules(),
            messages: $this->messages(),
            attributes: $this->attributes(),
            options: $options,
            context: $this->validationContext(),
        );

        $this->setValidationResult($result);
        $this->request->replace($this->payload());

        return $result;
    }

    public function request(): Request
    {
        return $this->request;
    }

    protected function validationContext(): mixed
    {
        return $this->request;
    }
}
