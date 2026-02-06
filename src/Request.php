<?php

declare(strict_types=1);

namespace PhpSoftBox\Request;

use PhpSoftBox\Collection\Collection;
use PhpSoftBox\Validator\Exception\ValidationException;
use PhpSoftBox\Validator\Support\DataPath;
use PhpSoftBox\Validator\Support\FilterPayloadApplier;
use PhpSoftBox\Validator\ValidationError;
use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;
use PhpSoftBox\Validator\ValidatorInterface;
use Psr\Http\Message\ServerRequestInterface;

use function is_array;
use function is_object;

final class Request
{
    private array $overrides    = [];
    private bool $replaceInput  = false;
    private array $filteredData = [];

    public function __construct(
        private readonly ServerRequestInterface $psrRequest,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function psr(): ServerRequestInterface
    {
        return $this->psrRequest;
    }

    /**
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $messages
     * @param array<string, string> $attributes
     */
    public function validate(
        array $rules,
        array $messages = [],
        array $attributes = [],
        ?ValidationOptions $options = null,
    ): array {
        $result = $this->validationResult($rules, $messages, $attributes, $options);

        if ($result->hasErrors()) {
            throw new ValidationException($result);
        }

        return $result->filteredData();
    }

    /**
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $messages
     * @param array<string, string> $attributes
     */
    public function validationResult(
        array $rules,
        array $messages = [],
        array $attributes = [],
        ?ValidationOptions $options = null,
    ): ValidationResult {
        $result = $this->validator->validate($this->all(), $rules, $messages, $attributes, $options, $this);

        $this->filteredData = $result->filteredData();

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function filteredData(): array
    {
        return $this->filteredData;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function merge(array $data): self
    {
        $this->overrides = Collection::from($this->overrides)
            ->merge($data, ['recursive' => true])
            ->all();

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function replace(array $data): self
    {
        $this->replaceInput = true;
        $this->overrides    = $data;

        return $this;
    }

    /**
     * @param array<string, callable(mixed): mixed|list<callable(mixed): mixed>> $filters
     */
    public function filter(array $filters): self
    {
        $result = new FilterPayloadApplier()->apply($this->all(), $filters);

        if ($result->errors !== []) {
            $this->throwFilterValidationErrors($result->errors, $result->payload);
        }

        return $this->replace($result->payload);
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, mixed> $filteredData
     */
    private function throwFilterValidationErrors(array $errors, array $filteredData): void
    {
        $prepared = [];

        foreach ($errors as $path => $messages) {
            foreach ($messages as $message) {
                $prepared[$path] ??= [];
                $prepared[$path][] = new ValidationError($path, 'filter', $message);
            }
        }

        $result = new ValidationResult($prepared, $filteredData);

        throw new ValidationException($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $base = $this->replaceInput ? [] : $this->collectData();

        if ($this->replaceInput) {
            return $this->overrides;
        }

        if ($this->overrides === []) {
            return $base;
        }

        return Collection::from($base)
            ->merge($this->overrides, ['recursive' => true])
            ->all();
    }

    public function input(string $path, mixed $default = null): mixed
    {
        return DataPath::get($this->all(), $path, $default);
    }

    public function has(string $path): bool
    {
        return DataPath::has($this->all(), $path);
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return $this->psrRequest->getQueryParams();
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        $body = $this->psrRequest->getParsedBody();

        if (is_array($body)) {
            return $body;
        }

        if (is_object($body)) {
            return (array) $body;
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function cookies(): array
    {
        return $this->psrRequest->getCookieParams();
    }

    /**
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->psrRequest->getUploadedFiles();
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->psrRequest->getAttributes();
    }

    /**
     * @return array<string, mixed>
     */
    private function collectData(): array
    {
        return Collection::from($this->cookies())
            ->merge($this->query(), ['recursive' => true])
            ->merge($this->body(), ['recursive' => true])
            ->merge($this->files(), ['recursive' => true])
            ->merge($this->attributes(), ['recursive' => true])
            ->all();
    }
}
