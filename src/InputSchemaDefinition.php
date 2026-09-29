<?php

declare(strict_types=1);

namespace PhpSoftBox\Request;

use PhpSoftBox\Collection\Collection;

use function array_filter;
use function array_values;
use function is_string;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function trim;

use const ARRAY_FILTER_USE_BOTH;

final readonly class InputSchemaDefinition implements InputSchemaPartInterface, InputSchemaDefaultsInterface
{
    /**
     * @param array<string, mixed> $rules
     * @param array<string, callable(mixed): mixed|list<callable(mixed): mixed>> $filters
     * @param array<string, mixed> $messages
     * @param array<string, string> $attributes
     * @param array<string, mixed> $defaults
     */
    public function __construct(
        private array $rules = [],
        private array $filters = [],
        private array $messages = [],
        private array $attributes = [],
        private array $defaults = [],
    ) {
    }

    /**
     * @param array<string, mixed> $rules
     * @param array<string, callable(mixed): mixed|list<callable(mixed): mixed>> $filters
     * @param array<string, mixed> $messages
     * @param array<string, string> $attributes
     * @param array<string, mixed> $defaults
     */
    public static function make(
        array $rules = [],
        array $filters = [],
        array $messages = [],
        array $attributes = [],
        array $defaults = [],
    ): self {
        return new self($rules, $filters, $messages, $attributes, $defaults);
    }

    public static function fromPart(InputSchemaPartInterface $part): self
    {
        return new self(
            rules: $part->rules(),
            filters: $part->filters(),
            messages: $part->messages(),
            attributes: $part->attributes(),
            defaults: $part instanceof InputSchemaDefaultsInterface ? $part->defaults() : [],
        );
    }

    public function merge(self|InputSchemaPartInterface $part): self
    {
        $definition = $part instanceof self ? $part : self::fromPart($part);

        return new self(
            rules: $this->mergeArray($this->rules, $definition->rules()),
            filters: $this->mergeArray($this->filters, $definition->filters()),
            messages: $this->mergeArray($this->messages, $definition->messages()),
            attributes: $this->mergeArray($this->attributes, $definition->attributes()),
            defaults: [...$this->defaults, ...$definition->defaults()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * @return array<string, callable(mixed): mixed|list<callable(mixed): mixed>>
     */
    public function filters(): array
    {
        return $this->filters;
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return $this->defaults;
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function withRules(array $rules): self
    {
        return new self($rules, $this->filters, $this->messages, $this->attributes, $this->defaults);
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function mergeRules(array $rules): self
    {
        return $this->withRules($this->mergeArray($this->rules, $rules));
    }

    /**
     * @param array<string, callable(mixed): mixed|list<callable(mixed): mixed>> $filters
     */
    public function withFilters(array $filters): self
    {
        return new self($this->rules, $filters, $this->messages, $this->attributes, $this->defaults);
    }

    /**
     * @param array<string, callable(mixed): mixed|list<callable(mixed): mixed>> $filters
     */
    public function mergeFilters(array $filters): self
    {
        return $this->withFilters($this->mergeArray($this->filters, $filters));
    }

    /**
     * @param array<string, mixed> $messages
     */
    public function withMessages(array $messages): self
    {
        return new self($this->rules, $this->filters, $messages, $this->attributes, $this->defaults);
    }

    /**
     * @param array<string, mixed> $messages
     */
    public function mergeMessages(array $messages): self
    {
        return $this->withMessages($this->mergeArray($this->messages, $messages));
    }

    /**
     * @param array<string, string> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        return new self($this->rules, $this->filters, $this->messages, $attributes, $this->defaults);
    }

    /**
     * @param array<string, string> $attributes
     */
    public function mergeAttributes(array $attributes): self
    {
        return $this->withAttributes($this->mergeArray($this->attributes, $attributes));
    }

    /**
     * @param array<string, mixed> $defaults
     */
    public function withDefaults(array $defaults): self
    {
        return new self($this->rules, $this->filters, $this->messages, $this->attributes, $defaults);
    }

    /**
     * @param array<string, mixed> $defaults
     */
    public function mergeDefaults(array $defaults): self
    {
        return $this->withDefaults([...$this->defaults, ...$defaults]);
    }

    /**
     * @param list<string> $paths
     */
    public function only(array $paths): self
    {
        $paths = $this->normalizePaths($paths);

        return new self(
            rules: $this->onlyMap($this->rules, $paths),
            filters: $this->onlyMap($this->filters, $paths),
            messages: $this->onlyMap($this->messages, $paths),
            attributes: $this->onlyMap($this->attributes, $paths),
            defaults: $this->onlyMap($this->defaults, $paths),
        );
    }

    /**
     * @param list<string> $paths
     */
    public function without(array $paths): self
    {
        $paths = $this->normalizePaths($paths);

        return new self(
            rules: $this->withoutMap($this->rules, $paths),
            filters: $this->withoutMap($this->filters, $paths),
            messages: $this->withoutMap($this->messages, $paths),
            attributes: $this->withoutMap($this->attributes, $paths),
            defaults: $this->withoutMap($this->defaults, $paths),
        );
    }

    /**
     * @template TValue
     *
     * @param array<string, TValue> $map
     * @param list<string> $paths
     * @return array<string, TValue>
     */
    private function onlyMap(array $map, array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        return array_filter(
            $map,
            fn (mixed $value, string $key): bool => $this->matchesAnyOnlyPath($key, $paths),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @template TValue
     *
     * @param array<string, TValue> $map
     * @param list<string> $paths
     * @return array<string, TValue>
     */
    private function withoutMap(array $map, array $paths): array
    {
        if ($paths === []) {
            return $map;
        }

        return array_filter(
            $map,
            fn (mixed $value, string $key): bool => !$this->matchesAnyWithoutPath($key, $paths),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param list<string> $paths
     */
    private function matchesAnyOnlyPath(string $key, array $paths): bool
    {
        foreach ($paths as $path) {
            if (
                $this->pathMatches($key, $path)
                || $this->pathIsChildOf($key, $path)
                || $this->pathIsChildOf($path, $key)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $paths
     */
    private function matchesAnyWithoutPath(string $key, array $paths): bool
    {
        foreach ($paths as $path) {
            if ($this->pathMatches($key, $path) || $this->pathIsChildOf($key, $path)) {
                return true;
            }
        }

        return false;
    }

    private function pathMatches(string $path, string $pattern): bool
    {
        if ($path === $pattern) {
            return true;
        }

        if (!str_contains($pattern, '*')) {
            return false;
        }

        $regex = '/^' . str_replace('\*', '[^.]+', preg_quote($pattern, '/')) . '$/';

        return preg_match($regex, $path) === 1;
    }

    private function pathIsChildOf(string $path, string $parent): bool
    {
        if ($path === $parent) {
            return true;
        }

        if (str_ends_with($parent, '.*')) {
            $parent = (string) preg_replace('/\.\*$/', '', $parent);
        }

        return $parent !== '' && str_starts_with($path, $parent . '.');
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function normalizePaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            if (!is_string($path)) {
                continue;
            }

            $path = trim($path);
            if ($path === '') {
                continue;
            }

            $normalized[] = $path;
        }

        return array_values($normalized);
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    private function mergeArray(array $left, array $right): array
    {
        return Collection::from($left)
            ->merge($right, ['recursive' => true])
            ->all();
    }
}
