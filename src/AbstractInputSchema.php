<?php

declare(strict_types=1);

namespace PhpSoftBox\Request;

use PhpSoftBox\Collection\Collection;
use PhpSoftBox\Request\Mutator\ExceptInputSchemaMutator;
use PhpSoftBox\Request\Mutator\MergeInputSchemaMutator;
use PhpSoftBox\Request\Mutator\OnlyInputSchemaMutator;
use PhpSoftBox\Request\Mutator\ReplaceInputSchemaMutator;
use PhpSoftBox\Validator\AbstractFormValidation;
use PhpSoftBox\Validator\Exception\ValidationException;
use PhpSoftBox\Validator\ValidationError;
use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;
use PhpSoftBox\Validator\Validator;
use PhpSoftBox\Validator\ValidatorInterface;

abstract class AbstractInputSchema extends AbstractFormValidation implements InputSchemaPartInterface
{
    /**
     * @var list<InputSchemaMutatorInterface>
     */
    private array $definitionMutators = [];

    /**
     * Payload до первой обработки: повторный process() и копии схемы начинают с него,
     * а не с уже отфильтрованных данных.
     *
     * @var array<string, mixed>|null
     */
    private ?array $sourcePayload = null;

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
        $result = $this->process($options);
        if ($result->hasErrors()) {
            throw new ValidationException($result);
        }

        return $result->filteredData();
    }

    public function validationResult(?ValidationOptions $options = null): ValidationResult
    {
        return $this->process($options);
    }

    public function process(?ValidationOptions $options = null): ValidationResult
    {
        $this->sourcePayload ??= $this->payload;
        $this->replacePayload($this->sourcePayload);
        $this->beforeValidation();
        $definition = $this->schemaDefinition();

        $filterResult = $this->applyDefinitionFilters($definition);
        if ($filterResult !== null) {
            $this->setValidationResult($filterResult);

            return $filterResult;
        }

        $result = $this->validatePayload($definition, $options);

        $this->setValidationResult($result);

        return $result;
    }

    /**
     * @return array<string, callable(mixed): mixed|list<callable(mixed): mixed>>
     */
    public function filters(): array
    {
        return [];
    }

    /**
     * Возвращает копию схемы с дополнительным мутатором definition; исходная схема не меняется.
     */
    public function withMutator(InputSchemaMutatorInterface $mutator): static
    {
        $schema                       = clone $this;
        $schema->definitionMutators[] = $mutator;

        return $schema;
    }

    public function replaceDefinition(InputSchemaDefinition|InputSchemaPartInterface $definition): static
    {
        return $this->withMutator(new ReplaceInputSchemaMutator($definition));
    }

    public function merge(InputSchemaDefinition|InputSchemaPartInterface $part): static
    {
        return $this->withMutator(new MergeInputSchemaMutator($part));
    }

    /**
     * @param list<string> $paths
     */
    public function only(array $paths): static
    {
        return $this->withMutator(new OnlyInputSchemaMutator($paths));
    }

    /**
     * @param list<string> $paths
     */
    public function except(array $paths): static
    {
        return $this->withMutator(new ExceptInputSchemaMutator($paths));
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
        $this->replacePayload(
            Collection::from($this->payload)
                ->merge($patch, ['recursive' => true])
                ->all(),
        );
    }

    protected function validationContext(): mixed
    {
        return $this->payload;
    }

    protected function mutateDefinition(InputSchemaDefinition $definition): InputSchemaDefinition
    {
        return $definition;
    }

    protected function schemaDefinition(): InputSchemaDefinition
    {
        $definition = $this->mutateDefinition(InputSchemaDefinition::make(
            rules: $this->rules(),
            filters: $this->filters(),
            messages: $this->messages(),
            attributes: $this->attributes(),
        ));

        foreach ($this->definitionMutators as $mutator) {
            $definition = $mutator->mutate($definition);
        }

        return $definition;
    }

    protected function applyDefinitionFilters(InputSchemaDefinition $definition): ?ValidationResult
    {
        $filters = $definition->filters();
        if ($filters === []) {
            return null;
        }

        $result = $this->applyPayloadFilters($this->payload, $filters);
        $this->replacePayload($result->payload);

        if ($result->errors !== []) {
            return $this->filterValidationResult($result->errors, $result->payload);
        }

        return null;
    }

    protected function validatePayload(
        InputSchemaDefinition $definition,
        ?ValidationOptions $options = null,
    ): ValidationResult {
        return $this->validator->validate(
            data: $this->payload,
            rules: $definition->rules(),
            messages: $definition->messages(),
            attributes: $definition->attributes(),
            options: $options,
            context: $this->validationContext(),
        );
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, mixed> $filteredData
     */
    private function filterValidationResult(array $errors, array $filteredData): ValidationResult
    {
        $prepared = [];

        foreach ($errors as $path => $messages) {
            foreach ($messages as $message) {
                $prepared[$path] ??= [];
                $prepared[$path][] = new ValidationError($path, 'filter', $message);
            }
        }

        return new ValidationResult($prepared, $filteredData);
    }
}
