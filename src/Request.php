<?php

declare(strict_types=1);

namespace PhpSoftBox\Request;

use PhpSoftBox\Validator\Exception\ValidationException;
use PhpSoftBox\Validator\Support\DataPath;
use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;
use PhpSoftBox\Validator\ValidatorInterface;
use Psr\Http\Message\ServerRequestInterface;

use function array_replace_recursive;
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
        $this->overrides = array_replace_recursive($this->overrides, $data);

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
        $data = [];
        foreach ($filters as $key => $filter) {
            $value = $this->input($key);

            if (is_array($filter)) {
                foreach ($filter as $item) {
                    $value = $item($value);
                }
            } else {
                $value = $filter($value);
            }

            $data[$key] = $value;
        }

        return $this->merge($data);
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

        return array_replace_recursive($base, $this->overrides);
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
        $cookies    = $this->cookies();
        $query      = $this->query();
        $body       = $this->body();
        $files      = $this->files();
        $attributes = $this->attributes();

        return array_replace_recursive($cookies, $query, $body, $files, $attributes);
    }
}
