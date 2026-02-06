<?php

declare(strict_types=1);

namespace PhpSoftBox\Request;

interface InputSchemaMutatorInterface
{
    public function mutate(InputSchemaDefinition $definition): InputSchemaDefinition;
}
