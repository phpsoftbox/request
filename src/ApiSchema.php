<?php

declare(strict_types=1);

namespace PhpSoftBox\Request;

use PhpSoftBox\Validator\Validator;
use PhpSoftBox\Validator\ValidatorInterface;

abstract class ApiSchema extends AbstractInputSchema
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        array $payload,
        ValidatorInterface $validator = new Validator(),
    ) {
        parent::__construct($payload, $validator);
    }
}
