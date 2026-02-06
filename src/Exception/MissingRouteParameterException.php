<?php

declare(strict_types=1);

namespace PhpSoftBox\Request\Exception;

use RuntimeException;

final class MissingRouteParameterException extends RuntimeException
{
    public static function forParameter(string $parameter): self
    {
        return new self('Route parameter "' . $parameter . '" is required.');
    }
}
