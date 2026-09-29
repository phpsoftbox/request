<?php

declare(strict_types=1);

namespace PhpSoftBox\Request\Tests;

use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Request\Exception\MissingRouteParameterException;
use PhpSoftBox\Request\Exception\UnexpectedRouteParameterException;
use PhpSoftBox\Request\Request;
use PhpSoftBox\Request\RequestSchema;
use PhpSoftBox\Request\RouteParameters;
use PhpSoftBox\Request\Tests\Fixtures\RecordingValidator;
use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;
use PhpSoftBox\Validator\ValidatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

use function strtoupper;
use function trim;

#[CoversClass(RequestSchema::class)]
#[CoversClass(RouteParameters::class)]
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
     * Проверяет, что RequestSchema читает route-параметры из _route_params, а не из обычных attributes.
     */
    #[Test]
    public function routeReadsRouteParamsFromRequestAttributes(): void
    {
        $request = new Request(
            new ServerRequest('GET', 'https://example.com/products/42', attributes: [
                'id'            => 99,
                '_route_params' => ['id' => 42],
            ]),
            new readonly class () implements ValidatorInterface {
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

        $schema = new class ($request) extends RequestSchema {
            public function routes(): RouteParameters
            {
                return $this->route();
            }

            public function rules(): array
            {
                return [];
            }
        };

        self::assertTrue($schema->routes()->has('id'));
        self::assertFalse($schema->routes()->has('missing'));
        self::assertSame(42, $schema->routes()->get('id'));
        self::assertSame(42, $schema->routes()->require('id'));
        self::assertSame('fallback', $schema->routes()->get('missing', 'fallback'));
    }

    /**
     * Проверяет, что route()->require() централизованно падает при отсутствии route-параметра.
     */
    #[Test]
    public function routeRequireThrowsWhenRouteParamMissing(): void
    {
        $request = new Request(
            new ServerRequest('GET', 'https://example.com/products/42', attributes: [
                '_route_params' => [],
            ]),
            new readonly class () implements ValidatorInterface {
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

        $schema = new class ($request) extends RequestSchema {
            public function routes(): RouteParameters
            {
                return $this->route();
            }

            public function rules(): array
            {
                return [];
            }
        };

        $this->expectException(MissingRouteParameterException::class);
        $this->expectExceptionMessage('Route parameter "id" is required.');

        $schema->routes()->require('id');
    }

    /**
     * Проверяет, что route()->entity() возвращает resolved route entity для логики, зависящей от текущего ресурса.
     */
    #[Test]
    public function routeEntityReturnsResolvedRouteEntity(): void
    {
        $product = new class () {
            public bool $archived = true;
        };

        $schema = new class ($this->makeRouteRequest(['product' => $product])) extends RequestSchema {
            public function routes(): RouteParameters
            {
                return $this->route();
            }

            public function rules(): array
            {
                return [];
            }
        };

        self::assertTrue($schema->routes()->has('product'));
        self::assertFalse($schema->routes()->has('missing'));
        self::assertSame($product, $schema->routes()->entity('product', $product::class));
        self::assertTrue($schema->routes()->entity('product', $product::class)->archived);
        self::assertNull($schema->routes()->entityOrNull('missing', $product::class));
    }

    /**
     * Проверяет, что route()->entity() валидирует ожидаемый класс route-параметра.
     */
    #[Test]
    public function routeEntityThrowsWhenRouteParamIsNotExpectedEntity(): void
    {
        $schema = new class ($this->makeRouteRequest(['product' => '42'])) extends RequestSchema {
            public function routes(): RouteParameters
            {
                return $this->route();
            }

            public function rules(): array
            {
                return [];
            }
        };

        $this->expectException(UnexpectedRouteParameterException::class);
        $this->expectExceptionMessage('Route parameter "product" must be an instance of ' . Request::class);

        $schema->routes()->entity('product', Request::class);
    }

    /**
     * Проверяет, что route()->key() возвращает сырой scalar route-параметр как ключ.
     */
    #[Test]
    public function routeKeyReturnsScalarRouteParam(): void
    {
        $uuid = new class () {
            public function __toString(): string
            {
                return 'uuid-001';
            }
        };

        $schema = new class ($this->makeRouteRequest([
            'shipment'  => '42',
            'uuid'      => 'product-001',
            'object_id' => $uuid,
        ])) extends RequestSchema {
            public function routeKeyValue(string $param, ?string $entityClass = null, ?callable $extractor = null): int|string
            {
                return $this->route()->key($param, $entityClass, $extractor);
            }

            public function rules(): array
            {
                return [];
            }
        };

        self::assertSame('42', $schema->routeKeyValue('shipment'));
        self::assertSame('product-001', $schema->routeKeyValue('uuid'));
        self::assertSame('uuid-001', $schema->routeKeyValue('object_id'));
    }

    /**
     * Проверяет, что route()->key() достает ключ из resolved route entity через явный extractor.
     */
    #[Test]
    public function routeKeyExtractsEntityKey(): void
    {
        $shipment = new class () {
            public int $id = 42;
        };
        $product = new class () {
            public string $code = 'product-001';
        };

        $schema = new class ($this->makeRouteRequest([
            'shipment' => $shipment,
            'product'  => $product,
        ])) extends RequestSchema {
            public function routeKeyValue(string $param, ?string $entityClass = null, ?callable $extractor = null): int|string
            {
                return $this->route()->key($param, $entityClass, $extractor);
            }

            public function rules(): array
            {
                return [];
            }
        };

        self::assertSame(42, $schema->routeKeyValue(
            'shipment',
            $shipment::class,
            static fn (object $entity): int => $entity->id,
        ));
        self::assertSame('PRODUCT-001', $schema->routeKeyValue(
            'product',
            $product::class,
            static fn (object $entity): string => strtoupper($entity->code),
        ));
    }

    /**
     * Проверяет, что route()->key() валидирует ожидаемый класс resolved route entity.
     */
    #[Test]
    public function routeKeyThrowsWhenEntityHasUnexpectedClass(): void
    {
        $schema = new class ($this->makeRouteRequest(['shipment' => new class () {}])) extends RequestSchema {
            public function routeKeyValue(string $param, ?string $entityClass = null, ?callable $extractor = null): int|string
            {
                return $this->route()->key($param, $entityClass, $extractor);
            }

            public function rules(): array
            {
                return [];
            }
        };

        $this->expectException(UnexpectedRouteParameterException::class);
        $this->expectExceptionMessage('Route parameter "shipment" must be an instance of ' . Request::class);

        $schema->routeKeyValue('shipment', Request::class);
    }

    /**
     * Проверяет, что route()->key() требует явный extractor для объекта без поддержанного key-контракта.
     */
    #[Test]
    public function routeKeyThrowsWhenObjectHasNoExtractor(): void
    {
        $shipment = new class () {
            public int $id = 42;
        };
        $schema = new class ($this->makeRouteRequest(['shipment' => $shipment])) extends RequestSchema {
            public function routeKeyValue(string $param, ?string $entityClass = null, ?callable $extractor = null): int|string
            {
                return $this->route()->key($param, $entityClass, $extractor);
            }

            public function rules(): array
            {
                return [];
            }
        };

        $this->expectException(UnexpectedRouteParameterException::class);
        $this->expectExceptionMessage(' requires an explicit key extractor.');

        $schema->routeKeyValue('shipment', $shipment::class);
    }

    /**
     * Проверяет, что route()->key() не принимает значения, которые нельзя использовать как ключ.
     */
    #[Test]
    public function routeKeyThrowsWhenValueIsNotKey(): void
    {
        $schema = new class ($this->makeRouteRequest(['shipment' => []])) extends RequestSchema {
            public function routeKeyValue(string $param, ?string $entityClass = null, ?callable $extractor = null): int|string
            {
                return $this->route()->key($param, $entityClass, $extractor);
            }

            public function rules(): array
            {
                return [];
            }
        };

        $this->expectException(UnexpectedRouteParameterException::class);
        $this->expectExceptionMessage('Route parameter "shipment" key must be int|string or Stringable');

        $schema->routeKeyValue('shipment');
    }

    /**
     * Проверяет, что filters() нормализует RequestSchema payload и обновляет Request.
     */
    #[Test]
    public function filtersNormalizeRequestPayloadBeforeValidation(): void
    {
        $validator = new class () implements ValidatorInterface {
            /**
             * @var array<string, mixed>
             */
            public array $lastData = [];

            public function validate(
                array $data,
                array $rules,
                array $messages = [],
                array $attributes = [],
                ?ValidationOptions $options = null,
                mixed $context = null,
            ): ValidationResult {
                $this->lastData = $data;

                return new ValidationResult([], $data);
            }
        };
        $request = new Request(
            new ServerRequest('POST', 'https://example.com/', parsedBody: ['name' => '  alex  ']),
            $validator,
        );

        $schema = new class ($request) extends RequestSchema {
            public function rules(): array
            {
                return ['name' => []];
            }

            public function filters(): array
            {
                return [
                    'name' => [
                        static fn (mixed $value): string => trim((string) $value),
                        static fn (mixed $value): string => strtoupper((string) $value),
                    ],
                ];
            }
        };

        $schema->validate();

        self::assertSame('ALEX', $validator->lastData['name'] ?? null);
        self::assertSame('ALEX', $schema->getString('name'));
        self::assertSame('ALEX', $schema->request()->input('name'));
    }

    /**
     * Проверяет, что старый beforeValidation() c Request::filter() продолжает работать.
     */
    #[Test]
    public function beforeValidationRequestFilterRemainsSupported(): void
    {
        $validator = new class () implements ValidatorInterface {
            /**
             * @var array<string, mixed>
             */
            public array $lastData = [];

            public function validate(
                array $data,
                array $rules,
                array $messages = [],
                array $attributes = [],
                ?ValidationOptions $options = null,
                mixed $context = null,
            ): ValidationResult {
                $this->lastData = $data;

                return new ValidationResult([], $data);
            }
        };
        $request = new Request(
            new ServerRequest('POST', 'https://example.com/', parsedBody: ['name' => '  alex  ']),
            $validator,
        );

        $schema = new class ($request) extends RequestSchema {
            public function rules(): array
            {
                return ['name' => []];
            }

            public function beforeValidation(): void
            {
                $this->request->filter([
                    'name' => static fn (mixed $value): string => trim((string) $value),
                ]);
            }
        };

        $schema->validate();

        self::assertSame('alex', $validator->lastData['name'] ?? null);
        self::assertSame('alex', $schema->request()->input('name'));
    }

    /**
     * Проверяет, что правки payload через mergePayload() в beforeValidation() доходят до валидации и Request.
     *
     * @see RequestSchema::process()
     * @see RequestSchema::beforeValidation()
     */
    #[Test]
    public function beforeValidationPayloadEditsAreKept(): void
    {
        $validator = new RecordingValidator();

        $request = new Request(
            new ServerRequest('POST', 'https://example.com/', parsedBody: ['name' => 'alex']),
            $validator,
        );

        $schema = new class ($request) extends RequestSchema {
            public function rules(): array
            {
                return ['name' => [], 'source' => []];
            }

            public function beforeValidation(): void
            {
                $this->mergePayload(['source' => 'hook']);
            }
        };

        $schema->validate();

        self::assertSame(['name' => 'alex', 'source' => 'hook'], $validator->lastData);
        self::assertSame('hook', $schema->request()->input('source'));
    }

    /**
     * Проверяет, что прямое присваивание payload в beforeValidation() не теряется.
     *
     * @see RequestSchema::process()
     * @see RequestSchema::beforeValidation()
     */
    #[Test]
    public function beforeValidationDirectPayloadAssignmentIsKept(): void
    {
        $validator = new RecordingValidator();

        $request = new Request(
            new ServerRequest('POST', 'https://example.com/', parsedBody: ['name' => 'alex']),
            $validator,
        );

        $schema = new class ($request) extends RequestSchema {
            public function rules(): array
            {
                return ['name' => []];
            }

            public function beforeValidation(): void
            {
                $this->payload['name'] = 'ALEX';
            }
        };

        $schema->validate();

        self::assertSame('ALEX', $validator->lastData['name'] ?? null);
        self::assertSame('ALEX', $schema->request()->input('name'));
    }

    /**
     * Проверяет, что повторная обработка схемы и её копия после only() не применяют фильтры
     * к уже отфильтрованным данным Request.
     *
     * @see RequestSchema::process()
     * @see RequestSchema::only()
     */
    #[Test]
    public function repeatedProcessingDoesNotFilterTwice(): void
    {
        $validator = new RecordingValidator();

        $request = new Request(
            new ServerRequest('POST', 'https://example.com/', parsedBody: ['name' => 'alex']),
            $validator,
        );

        $schema = new class ($request) extends RequestSchema {
            public function rules(): array
            {
                return ['name' => []];
            }

            public function filters(): array
            {
                return ['name' => static fn (mixed $value): string => $value . '!'];
            }
        };

        // Первая обработка, повторная и обработка копии схемы начинают с исходных данных.
        $schema->validate();
        $schema->validate();
        $schema->only(['name'])->validate();

        self::assertSame('alex!', $validator->lastData['name'] ?? null);
        self::assertSame('alex!', $request->input('name'));
    }

    /**
     * Проверяет, что изменения Request, сделанные после создания схемы, учитываются при обработке.
     *
     * @see RequestSchema::process()
     */
    #[Test]
    public function processUsesRequestChangesMadeAfterSchemaCreation(): void
    {
        $validator = new RecordingValidator();

        $request = new Request(
            new ServerRequest('POST', 'https://example.com/', parsedBody: ['name' => 'alex']),
            $validator,
        );

        $schema = new class ($request) extends RequestSchema {
            public function rules(): array
            {
                return ['name' => [], 'role' => []];
            }
        };

        $request->merge(['role' => 'admin']);
        $schema->validate();

        self::assertSame(['name' => 'alex', 'role' => 'admin'], $validator->lastData);
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

    /**
     * @param array<string, mixed> $routeParams
     */
    private function makeRouteRequest(array $routeParams): Request
    {
        return new Request(
            new ServerRequest('GET', 'https://example.com/', attributes: [
                '_route_params' => $routeParams,
            ]),
            new readonly class () implements ValidatorInterface {
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
}
