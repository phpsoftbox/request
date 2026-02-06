<?php

declare(strict_types=1);

namespace PhpSoftBox\Request\Tests;

use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Request\Request;
use PhpSoftBox\Request\RequestSchema;
use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;
use PhpSoftBox\Validator\ValidatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

#[CoversClass(RequestSchema::class)]
final class RequestSchemaTest extends TestCase
{
    /**
     * Проверяет, что typed-getters возвращают значения ожидаемых типов после validate().
     */
    #[Test]
    public function typedGettersReturnNormalizedValues(): void
    {
        $schema = $this->makeSchema([
            'query'        => 'search',
            'status'       => 'all',
            'page'         => 2,
            'show_deleted' => true,
            'rating'       => 4.5,
            'ids'          => [1, 2, 3],
            'email'        => null,
        ]);

        $schema->validate();

        self::assertSame('search', $schema->getString('query'));
        self::assertSame('all', $schema->getString('status'));
        self::assertSame(2, $schema->getInt('page'));
        self::assertTrue($schema->getBool('show_deleted'));
        self::assertSame(4.5, $schema->getFloat('rating'));
        self::assertSame([1, 2, 3], $schema->getArray('ids'));
        self::assertNull($schema->getNullableString('email'));
    }

    /**
     * Проверяет, что typed-getters используют переданные значения по умолчанию при отсутствии поля.
     */
    #[Test]
    public function typedGettersUseDefaultsWhenFieldMissing(): void
    {
        $schema = $this->makeSchema([]);
        $schema->validate();

        self::assertSame('all', $schema->getString('status', 'all'));
        self::assertSame(10, $schema->getInt('per_page', 10));
        self::assertFalse($schema->getBool('show_deleted'));
        self::assertSame(1.25, $schema->getFloat('amount', 1.25));
        self::assertSame(['x'], $schema->getArray('ids', ['x']));
    }

    /**
     * Проверяет, что typed-getters выбрасывают UnexpectedValueException при несовпадении типа.
     */
    #[Test]
    public function typedGettersThrowOnUnexpectedType(): void
    {
        $schema = $this->makeSchema([
            'status' => 123,
        ]);
        $schema->validate();

        $this->expectException(UnexpectedValueException::class);
        $schema->getString('status');
    }

    /**
     * @param array<string, mixed> $filtered
     */
    private function makeSchema(array $filtered): RequestSchema
    {
        $request = new Request(
            new ServerRequest('GET', 'https://example.com/'),
            new readonly class ($filtered) implements ValidatorInterface {
                /**
                 * @param array<string, mixed> $filtered
                 */
                public function __construct(
                    private array $filtered,
                ) {
                }

                public function validate(
                    array $data,
                    array $rules,
                    array $messages = [],
                    array $attributes = [],
                    ?ValidationOptions $options = null,
                    mixed $context = null,
                ): ValidationResult {
                    return new ValidationResult([], $this->filtered);
                }
            },
        );

        return new class ($request) extends RequestSchema {
            public function rules(): array
            {
                return [];
            }
        };
    }
}
