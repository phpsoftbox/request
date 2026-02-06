<?php

declare(strict_types=1);

namespace PhpSoftBox\Request;

use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;

use function is_array;

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

    public function process(?ValidationOptions $options = null): ValidationResult
    {
        $this->beforeValidation();
        $this->replacePayload($this->request->all());
        $definition = $this->schemaDefinition();

        $filterResult = $this->applyDefinitionFilters($definition);
        $this->request->replace($this->payload());
        if ($filterResult !== null) {
            $this->setValidationResult($filterResult);

            return $filterResult;
        }

        $result = $this->validatePayload($definition, $options);

        $this->setValidationResult($result);
        $this->request->replace($this->payload());

        return $result;
    }

    public function request(): Request
    {
        return $this->request;
    }

    protected function route(): RouteParameters
    {
        return new RouteParameters($this->routeParams());
    }

    protected function validationContext(): mixed
    {
        return $this->request;
    }

    /**
     * @return array<string, mixed>
     */
    private function routeParams(): array
    {
        $routeParams = $this->request->psr()->getAttribute('_route_params');
        if (!is_array($routeParams)) {
            return [];
        }

        return $routeParams;
    }
}
