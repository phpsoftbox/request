<?php

declare(strict_types=1);

namespace PhpSoftBox\Request;

use PhpSoftBox\Request\Exception\MissingRouteParameterException;
use PhpSoftBox\Request\Exception\UnexpectedRouteParameterException;
use Stringable;

use function array_key_exists;
use function interface_exists;
use function is_int;
use function is_object;
use function is_string;

final readonly class RouteParameters
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        private array $params,
    ) {
    }

    public function get(string $param, mixed $default = null): mixed
    {
        if (!$this->has($param)) {
            return $default;
        }

        return $this->params[$param];
    }

    public function has(string $param): bool
    {
        return array_key_exists($param, $this->params);
    }

    public function require(string $param): mixed
    {
        if (!$this->has($param)) {
            throw MissingRouteParameterException::forParameter($param);
        }

        return $this->params[$param];
    }

    /**
     * @template TEntity of object
     * @param class-string<TEntity> $entityClass
     * @return TEntity
     */
    public function entity(string $param, string $entityClass): object
    {
        $value = $this->require($param);

        return $this->ensureEntity($param, $entityClass, $value);
    }

    /**
     * @template TEntity of object
     * @param class-string<TEntity> $entityClass
     * @return TEntity|null
     */
    public function entityOrNull(string $param, string $entityClass): ?object
    {
        if (!$this->has($param)) {
            return null;
        }

        $value = $this->params[$param];
        if ($value === null) {
            return null;
        }

        return $this->ensureEntity($param, $entityClass, $value);
    }

    /**
     * @template TEntity of object
     * @param class-string<TEntity>|null $entityClass
     * @param (callable(TEntity):mixed)|null $extractor
     */
    public function key(string $param, ?string $entityClass = null, ?callable $extractor = null): int|string
    {
        $value = $this->require($param);

        if (!is_object($value) || ($value instanceof Stringable && $entityClass === null && $extractor === null)) {
            return $this->normalizeKey($param, $value);
        }

        if ($entityClass !== null && !($value instanceof $entityClass)) {
            throw UnexpectedRouteParameterException::forExpectedEntity($param, $entityClass, $value);
        }

        if ($extractor !== null) {
            return $this->normalizeKey($param, $extractor($value));
        }

        if ($this->isOrmEntity($value)) {
            return $this->normalizeKey($param, $value->id());
        }

        throw UnexpectedRouteParameterException::forMissingKeyExtractor($param, $value);
    }

    private function normalizeKey(string $param, mixed $value): int|string
    {
        if (is_int($value) || (is_string($value) && $value !== '')) {
            return $value;
        }

        if ($value instanceof Stringable) {
            $key = (string) $value;
            if ($key !== '') {
                return $key;
            }
        }

        throw UnexpectedRouteParameterException::forInvalidKey($param, $value);
    }

    /**
     * @template TEntity of object
     * @param class-string<TEntity> $entityClass
     * @return TEntity
     */
    private function ensureEntity(string $param, string $entityClass, mixed $value): object
    {
        if (!($value instanceof $entityClass)) {
            throw UnexpectedRouteParameterException::forExpectedEntity($param, $entityClass, $value);
        }

        return $value;
    }

    private function isOrmEntity(object $value): bool
    {
        $entityInterface = 'PhpSoftBox\\Orm\\Contracts\\EntityInterface';

        return interface_exists($entityInterface) && $value instanceof $entityInterface;
    }
}
