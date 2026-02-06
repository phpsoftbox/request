<?php

declare(strict_types=1);

namespace PhpSoftBox\Request\Mutator;

use PhpSoftBox\Request\InputSchemaDefinition;
use PhpSoftBox\Request\InputSchemaMutatorInterface;

final readonly class OnlyInputSchemaMutator implements InputSchemaMutatorInterface
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        private array $paths,
    ) {
    }

    public function mutate(InputSchemaDefinition $definition): InputSchemaDefinition
    {
        return $definition->only($this->paths);
    }
}
