<?php

declare(strict_types=1);

namespace PhpSoftBox\Request;

use LogicException;
use PhpSoftBox\Collection\Collection;
use PhpSoftBox\Request\Mutator\ExceptInputSchemaMutator;
use PhpSoftBox\Request\Mutator\MergeInputSchemaMutator;
use PhpSoftBox\Request\Mutator\OnlyInputSchemaMutator;
use PhpSoftBox\Request\Mutator\ReplaceInputSchemaMutator;
use PhpSoftBox\Validator\AbstractFormValidation;
use PhpSoftBox\Validator\Exception\ValidationException;
use PhpSoftBox\Validator\Support\DataPath;
use PhpSoftBox\Validator\ValidationError;
use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;
use PhpSoftBox\Validator\Validator;
use PhpSoftBox\Validator\ValidatorInterface;

use function array_key_exists;
use function array_slice;
use function explode;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function str_ends_with;

abstract class AbstractInputSchema extends AbstractFormValidation implements InputSchemaPartInterface, InputSchemaDefaultsInterface
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
        $this->applyDefinitionDefaults($this->schemaDefinition());
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
     * Значения по умолчанию для непереданных полей: подставляются до `beforeValidation()` и фильтров, дальше поле
     * проходит фильтры и правила как переданное. Переданные `null` и `''` не заменяются — это задача фильтров.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
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
            defaults: $this->defaults(),
        ));

        foreach ($this->definitionMutators as $mutator) {
            $definition = $mutator->mutate($definition);
        }

        return $definition;
    }

    /**
     * Подставляет умолчания definition в непереданные поля. Точный путь создаётся вместе с родителями; wildcard-путь
     * (`items.*.quantity`) заполняется только в существующих элементах, пустой список остаётся пустым.
     */
    protected function applyDefinitionDefaults(InputSchemaDefinition $definition): void
    {
        $defaults = $definition->defaults();
        if ($defaults === []) {
            return;
        }

        $rules   = $definition->rules();
        $payload = $this->payload;

        foreach ($defaults as $path => $value) {
            if (!is_string($path) || $path === '' || str_ends_with($path, '*')) {
                throw new LogicException(sprintf('Invalid default path "%s": expected a field path.', (string) $path));
            }

            // Поле без правила validated() отбросит, и умолчание молча потеряется.
            if (!array_key_exists($path, $rules)) {
                throw new LogicException(sprintf('Default for "%s" has no validation rule.', $path));
            }

            foreach ($this->defaultTargets($payload, explode('.', $path)) as $target) {
                if (!DataPath::has($payload, $target)) {
                    DataPath::set($payload, $target, $value);
                }
            }
        }

        if ($payload !== $this->payload) {
            $this->replacePayload($payload);
        }
    }

    /**
     * Конкретные пути для умолчания: `*` раскрывается по существующим элементам.
     *
     * @param array<array-key, mixed> $data
     * @param list<string> $segments
     * @param list<string> $prefix
     * @return list<string>
     */
    private function defaultTargets(array $data, array $segments, array $prefix = []): array
    {
        $wildcard = null;
        foreach ($segments as $index => $segment) {
            if ($segment === '*') {
                $wildcard = $index;
                break;
            }
        }

        if ($wildcard === null) {
            return [implode('.', [...$prefix, ...$segments])];
        }

        $parent = [...$prefix, ...array_slice($segments, 0, $wildcard)];
        $items  = $parent === [] ? $data : DataPath::get($data, implode('.', $parent));
        if (!is_array($items)) {
            return [];
        }

        $rest    = array_slice($segments, $wildcard + 1);
        $targets = [];
        foreach ($items as $key => $item) {
            // Подставить поле можно только в элемент-массив.
            if (!is_array($item)) {
                continue;
            }

            $targets = [...$targets, ...$this->defaultTargets($data, $rest, [...$parent, (string) $key])];
        }

        return $targets;
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
