<?php

declare(strict_types=1);

namespace PhpSoftBox\Request\Tests;

use PhpSoftBox\Request\ApiSchema;
use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;
use PhpSoftBox\Validator\ValidatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiSchema::class)]
final class ApiSchemaTest extends TestCase
{
    #[Test]
    public function validatesAndExposesTypedGetters(): void
    {
        $schema = $this->makeSchema([
            'id'      => 10,
            'price'   => 120.5,
            'title'   => 'Test',
            'enabled' => true,
        ]);

        $data = $schema->validate();

        self::assertSame(10, $data['id']);
        self::assertSame(10, $schema->getInt('id'));
        self::assertSame(120.5, $schema->getFloat('price'));
        self::assertSame('Test', $schema->getString('title'));
        self::assertTrue($schema->getBool('enabled'));
    }

    /**
     * @param array<string, mixed> $filtered
     */
    private function makeSchema(array $filtered): ApiSchema
    {
        return new class ($filtered) extends ApiSchema {
            public function __construct(array $filtered)
            {
                parent::__construct(
                    payload: $filtered,
                    validator: new readonly class () implements ValidatorInterface {
                        public function validate(
                            array $data,
                            array $rules,
                            array $messages = [],
                            array $attributes = [],
                            ?ValidationOptions $options = null,
                            mixed $context = null,
                        ): ValidationResult {
                            return new ValidationResult([], $data);
                        }
                    },
                );
            }

            public function rules(): array
            {
                return [];
            }
        };
    }
}
