<?php

declare(strict_types=1);

namespace PhpSoftBox\Request\Tests;

use InvalidArgumentException;
use PhpSoftBox\Request\AbstractInputSchema;
use PhpSoftBox\Request\ApiSchema;
use PhpSoftBox\Request\InputSchemaDefinition;
use PhpSoftBox\Request\InputSchemaMutatorInterface;
use PhpSoftBox\Request\InputSchemaPartInterface;
use PhpSoftBox\Request\Tests\Fixtures\RecordingValidator;
use PhpSoftBox\Validator\Exception\ValidationException;
use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;
use PhpSoftBox\Validator\ValidatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function strtolower;
use function trim;

#[CoversClass(ApiSchema::class)]
#[CoversClass(AbstractInputSchema::class)]
final class ApiSchemaTest extends TestCase
{
    #[Test]
    public function processReturnsFilterErrorsWithoutThrowingValidationException(): void
    {
        $validator = new class () implements ValidatorInterface {
            public bool $called = false;

            public function validate(
                array $data,
                array $rules,
                array $messages = [],
                array $attributes = [],
                ?ValidationOptions $options = null,
                mixed $context = null,
            ): ValidationResult {
                $this->called = true;

                return new ValidationResult([], $data);
            }
        };

        $schema = new class (['code' => 'invalid'], $validator) extends ApiSchema {
            public function rules(): array
            {
                return ['code' => []];
            }

            public function filters(): array
            {
                return [
                    'code' => static function (): never {
                        throw new InvalidArgumentException('Unsupported code.');
                    },
                ];
            }
        };

        $result = $schema->process();

        self::assertTrue($result->hasErrors());
        self::assertSame(['code' => ['Unsupported code.']], $result->errors());
        self::assertSame(['code' => 'invalid'], $result->filteredData());
        self::assertFalse($validator->called);
        self::assertSame(['code' => 'invalid'], $schema->validated());
    }

    #[Test]
    public function validateStillThrowsForFilterErrors(): void
    {
        $schema = new class (['code' => 'invalid']) extends ApiSchema {
            public function rules(): array
            {
                return ['code' => []];
            }

            public function filters(): array
            {
                return [
                    'code' => static function (): never {
                        throw new InvalidArgumentException('Unsupported code.');
                    },
                ];
            }
        };

        $this->expectException(ValidationException::class);

        $schema->validate();
    }

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
     * Проверяет, что filters() нормализует payload до вызова валидатора.
     */
    #[Test]
    public function filtersNormalizePayloadBeforeValidation(): void
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

        $schema = new class (['email' => '  USER@EXAMPLE.TEST  '], $validator) extends ApiSchema {
            public function rules(): array
            {
                return ['email' => []];
            }

            public function filters(): array
            {
                return [
                    'email' => [
                        static fn (mixed $value): string => trim((string) $value),
                        static fn (mixed $value): string => strtolower((string) $value),
                    ],
                ];
            }
        };

        $schema->validate();

        self::assertSame('user@example.test', $validator->lastData['email'] ?? null);
        self::assertSame('user@example.test', $schema->getString('email'));
    }

    /**
     * Проверяет, что mutator API умеет расширять и урезать definition до валидации.
     */
    #[Test]
    public function mutatorsCanMergeAndLimitDefinition(): void
    {
        $validator = new class () implements ValidatorInterface {
            /**
             * @var array<string, mixed>
             */
            public array $lastRules = [];

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
                $this->lastData  = $data;
                $this->lastRules = $rules;

                return new ValidationResult([], $data);
            }
        };

        $part = new class () implements InputSchemaPartInterface {
            public function rules(): array
            {
                return ['email' => ['email']];
            }

            public function filters(): array
            {
                return [
                    'email' => static fn (mixed $value): string => strtolower(trim((string) $value)),
                ];
            }

            public function messages(): array
            {
                return ['email.required' => 'Email required'];
            }

            public function attributes(): array
            {
                return ['email' => 'Email'];
            }
        };

        $schema = new class (
            ['name' => '  Alex  ', 'email' => '  USER@EXAMPLE.TEST  '],
            $validator,
        ) extends ApiSchema {
            public function rules(): array
            {
                return ['name' => ['string']];
            }

            public function filters(): array
            {
                return [
                    'name' => static fn (mixed $value): string => trim((string) $value),
                ];
            }
        };

        $schema
            ->merge($part)
            ->only(['email'])
            ->validate();

        self::assertSame(['email'], array_keys($validator->lastRules));
        self::assertSame('  Alex  ', $validator->lastData['name'] ?? null);
        self::assertSame('user@example.test', $validator->lastData['email'] ?? null);
    }

    /**
     * Проверяет, что replaceDefinition() полностью заменяет базовые правила схемы.
     */
    #[Test]
    public function replaceDefinitionOverridesBaseDefinition(): void
    {
        $validator = new class () implements ValidatorInterface {
            /**
             * @var array<string, mixed>
             */
            public array $lastRules = [];

            public function validate(
                array $data,
                array $rules,
                array $messages = [],
                array $attributes = [],
                ?ValidationOptions $options = null,
                mixed $context = null,
            ): ValidationResult {
                $this->lastRules = $rules;

                return new ValidationResult([], $data);
            }
        };

        $schema = new class (['name' => 'Alex', 'email' => 'alex@example.test'], $validator) extends ApiSchema {
            public function rules(): array
            {
                return ['name' => ['string']];
            }
        };

        $schema
            ->replaceDefinition(InputSchemaDefinition::make(rules: ['email' => ['email']]))
            ->validate();

        self::assertSame(['email'], array_keys($validator->lastRules));
    }

    /**
     * Проверяет, что except() удаляет выбранные пути из rules, filters, messages и attributes.
     */
    #[Test]
    public function exceptRemovesDefinitionPathsBeforeValidation(): void
    {
        $validator = new class () implements ValidatorInterface {
            /**
             * @var array<string, mixed>
             */
            public array $lastData = [];

            /**
             * @var array<string, mixed>
             */
            public array $lastRules = [];

            /**
             * @var array<string, mixed>
             */
            public array $lastMessages = [];

            /**
             * @var array<string, string>
             */
            public array $lastAttributes = [];

            public function validate(
                array $data,
                array $rules,
                array $messages = [],
                array $attributes = [],
                ?ValidationOptions $options = null,
                mixed $context = null,
            ): ValidationResult {
                $this->lastData       = $data;
                $this->lastRules      = $rules;
                $this->lastMessages   = $messages;
                $this->lastAttributes = $attributes;

                return new ValidationResult([], $data);
            }
        };

        $schema = new class (
            [
                'name'             => '  Alex  ',
                'internal_comment' => '  hidden  ',
                'meta'             => ['public' => '  visible  ', 'secret' => '  secret  '],
            ],
            $validator,
        ) extends ApiSchema {
            public function rules(): array
            {
                return [
                    'name'             => ['string'],
                    'internal_comment' => ['string'],
                    'meta'             => ['array'],
                    'meta.public'      => ['string'],
                    'meta.secret'      => ['string'],
                ];
            }

            public function filters(): array
            {
                return [
                    'name'             => static fn (mixed $value): string => trim((string) $value),
                    'internal_comment' => static fn (mixed $value): string => trim((string) $value),
                    'meta.public'      => static fn (mixed $value): string => trim((string) $value),
                    'meta.secret'      => static fn (mixed $value): string => trim((string) $value),
                ];
            }

            public function messages(): array
            {
                return [
                    'name.required'             => 'Name required',
                    'internal_comment.required' => 'Comment required',
                    'meta.secret.required'      => 'Secret required',
                ];
            }

            public function attributes(): array
            {
                return [
                    'name'             => 'Name',
                    'internal_comment' => 'Internal comment',
                    'meta.public'      => 'Public meta',
                    'meta.secret'      => 'Secret meta',
                ];
            }
        };

        $schema
            ->except(['internal_comment', 'meta.secret'])
            ->validate();

        self::assertSame(['name', 'meta', 'meta.public'], array_keys($validator->lastRules));
        self::assertSame(['name.required'], array_keys($validator->lastMessages));
        self::assertSame(['name', 'meta.public'], array_keys($validator->lastAttributes));
        self::assertSame('Alex', $validator->lastData['name'] ?? null);
        self::assertSame('  hidden  ', $validator->lastData['internal_comment'] ?? null);
        self::assertSame('visible', $validator->lastData['meta']['public'] ?? null);
        self::assertSame('  secret  ', $validator->lastData['meta']['secret'] ?? null);
    }

    /**
     * Проверяет, что withMutator() подключает пользовательскую мутацию definition.
     */
    #[Test]
    public function withMutatorAppliesCustomDefinitionMutator(): void
    {
        $validator = new class () implements ValidatorInterface {
            /**
             * @var array<string, mixed>
             */
            public array $lastData = [];

            /**
             * @var array<string, mixed>
             */
            public array $lastRules = [];

            public function validate(
                array $data,
                array $rules,
                array $messages = [],
                array $attributes = [],
                ?ValidationOptions $options = null,
                mixed $context = null,
            ): ValidationResult {
                $this->lastData  = $data;
                $this->lastRules = $rules;

                return new ValidationResult([], $data);
            }
        };
        $mutator = new class () implements InputSchemaMutatorInterface {
            public function mutate(InputSchemaDefinition $definition): InputSchemaDefinition
            {
                return $definition
                    ->merge(InputSchemaDefinition::make(
                        rules: ['email' => ['email']],
                        filters: ['email' => static fn (mixed $value): string => strtolower(trim((string) $value))],
                    ))
                    ->only(['email']);
            }
        };

        $schema = new class (
            ['name' => '  Alex  ', 'email' => '  USER@EXAMPLE.TEST  '],
            $validator,
        ) extends ApiSchema {
            public function rules(): array
            {
                return ['name' => ['string']];
            }

            public function filters(): array
            {
                return [
                    'name' => static fn (mixed $value): string => trim((string) $value),
                ];
            }
        };

        $schema
            ->withMutator($mutator)
            ->validate();

        self::assertSame(['email'], array_keys($validator->lastRules));
        self::assertSame('  Alex  ', $validator->lastData['name'] ?? null);
        self::assertSame('user@example.test', $validator->lastData['email'] ?? null);
    }

    /**
     * Проверяет, что only() возвращает новую схему, а исходная схема сохраняет своё definition.
     *
     * @see AbstractInputSchema::only()
     * @see AbstractInputSchema::withMutator()
     */
    #[Test]
    public function onlyReturnsCopyWithoutMutatingOriginalSchema(): void
    {
        $validator = new RecordingValidator();

        $schema = new class (['name' => 'Alex', 'email' => 'alex@example.test'], $validator) extends ApiSchema {
            public function rules(): array
            {
                return ['name' => ['string'], 'email' => ['email']];
            }
        };

        $limited = $schema->only(['email']);

        // Копия валидирует только email.
        $limited->validate();
        self::assertNotSame($schema, $limited);
        self::assertSame(['email'], array_keys($validator->lastRules));

        // Исходная схема по-прежнему валидирует все поля.
        $schema->validate();
        self::assertSame(['name', 'email'], array_keys($validator->lastRules));
    }

    /**
     * Проверяет, что повторный process() начинает с исходного payload и не применяет фильтры дважды.
     *
     * @see AbstractInputSchema::process()
     */
    #[Test]
    public function repeatedProcessDoesNotFilterTwice(): void
    {
        $validator = new RecordingValidator();

        $schema = new class (['name' => 'alex'], $validator) extends ApiSchema {
            public function rules(): array
            {
                return ['name' => []];
            }

            public function filters(): array
            {
                return ['name' => static fn (mixed $value): string => $value . '!'];
            }
        };

        $schema->process();
        $schema->process();

        self::assertSame('alex!', $validator->lastData['name'] ?? null);
        self::assertSame('alex!', $schema->getString('name'));
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
