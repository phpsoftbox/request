<?php

declare(strict_types=1);

namespace PhpSoftBox\Request\Mutator;

use PhpSoftBox\Request\InputSchemaDefinition;
use PhpSoftBox\Request\InputSchemaMutatorInterface;
use PhpSoftBox\Request\InputSchemaPartInterface;

final readonly class MergeInputSchemaMutator implements InputSchemaMutatorInterface
{
    public function __construct(
        private InputSchemaDefinition|InputSchemaPartInterface $part,
    ) {
    }

    public function mutate(InputSchemaDefinition $definition): InputSchemaDefinition
    {
        return $definition->merge($this->part);
    }
}
