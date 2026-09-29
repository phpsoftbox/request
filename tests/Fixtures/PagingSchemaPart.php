<?php

declare(strict_types=1);

namespace PhpSoftBox\Request\Tests\Fixtures;

use PhpSoftBox\Request\AbstractInputSchemaPart;
use PhpSoftBox\Validator\Rule\IntValidation;

/**
 * Часть схемы с умолчанием номера и размера страницы.
 */
final class PagingSchemaPart extends AbstractInputSchemaPart
{
    public function rules(): array
    {
        return ['per_page' => [new IntValidation()]];
    }

    public function defaults(): array
    {
        return ['per_page' => 50];
    }
}
