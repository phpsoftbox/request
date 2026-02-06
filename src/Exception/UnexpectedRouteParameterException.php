<?php

declare(strict_types=1);

namespace PhpSoftBox\Request\Exception;

use RuntimeException;

use function get_debug_type;

final class UnexpectedRouteParameterException extends RuntimeException
{
    public static function forExpectedEntity(string $parameter, string $expectedClass, mixed $actual): self
    {
        return new self(
            'Route parameter "' . $parameter . '" must be an instance of ' . $expectedClass
            . ', ' . get_debug_type($actual) . ' given.',
        );
    }

    public static function forMissingKeyExtractor(string $parameter, object $entity): self
    {
        return new self(
            'Route parameter "' . $parameter . '" object ' . $entity::class
            . ' requires an explicit key extractor.',
        );
    }

    public static function forInvalidKey(string $parameter, mixed $value): self
    {
        return new self(
            'Route parameter "' . $parameter . '" key must be int|string or Stringable, '
            . get_debug_type($value) . ' given.',
        );
    }
}
