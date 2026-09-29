<?php

declare(strict_types=1);

namespace PhpSoftBox\Request\Tests;

use LogicException;
use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Request\AbstractInputSchema;
use PhpSoftBox\Request\InputSchemaDefinition;
use PhpSoftBox\Request\Request;
use PhpSoftBox\Request\RequestSchema;
use PhpSoftBox\Request\Tests\Fixtures\DefaultsRequestSchema;
use PhpSoftBox\Request\Tests\Fixtures\DefaultWithoutRuleRequestSchema;
use PhpSoftBox\Validator\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractInputSchema::class)]
#[CoversClass(RequestSchema::class)]
#[CoversClass(InputSchemaDefinition::class)]
#[CoversMethod(AbstractInputSchema::class, 'applyDefinitionDefaults')]
#[CoversMethod(RequestSchema::class, 'process')]
#[CoversMethod(InputSchemaDefinition::class, 'only')]
#[CoversMethod(InputSchemaDefinition::class, 'merge')]
final class RequestSchemaDefaultsTest extends TestCase
{
    /**
     * Проверим, что непереданные поля получают умолчания, проходят фильтры и правила и попадают в validated().
     *
     * @see AbstractInputSchema::applyDefinitionDefaults()
     */
    #[Test]
    public function missingFieldsReceiveDefaults(): void
    {
        $schema = new DefaultsRequestSchema($this->request([]));

        $schema->validate();

        self::assertSame(['status' => 'all', 'page' => 1, 'only_my' => false], $schema->validated());
        self::assertSame('all', $schema->request()->all()['status']);
    }

    /**
     * Проверим, что переданные значения, в том числе `null` и `''`, умолчанием не заменяются.
     *
     * @see AbstractInputSchema::applyDefinitionDefaults()
     */
    #[Test]
    public function passedValuesAreNotReplaced(): void
    {
        $schema = new DefaultsRequestSchema($this->request(['status' => '', 'page' => '3', 'only_my' => null]));

        $schema->process();

        $payload = $schema->request()->all();
        self::assertSame('', $payload['status']);
        self::assertSame('3', $payload['page']);
        self::assertNull($payload['only_my']);
    }

    /**
     * Проверим, что умолчание wildcard-пути подставляется только в существующие элементы без поля.
     *
     * @see AbstractInputSchema::applyDefinitionDefaults()
     */
    #[Test]
    public function wildcardDefaultFillsExistingItemsOnly(): void
    {
        $schema = new DefaultsRequestSchema($this->request([
            'items' => [['sku' => 'a'], ['sku' => 'b', 'quantity' => 5]],
        ]));

        $schema->process();

        self::assertSame(
            [['sku' => 'a', 'quantity' => 1], ['sku' => 'b', 'quantity' => 5]],
            $schema->request()->all()['items'],
        );
    }

    /**
     * Проверим, что пустой список и отсутствующий список wildcard-умолчанием не заполняются.
     *
     * @see AbstractInputSchema::applyDefinitionDefaults()
     */
    #[Test]
    public function wildcardDefaultSkipsEmptyAndMissingLists(): void
    {
        $empty = new DefaultsRequestSchema($this->request(['items' => []]));

        $empty->process();

        $missing = new DefaultsRequestSchema($this->request([]));

        $missing->process();

        self::assertSame([], $empty->request()->all()['items']);
        self::assertArrayNotHasKey('items', $missing->request()->all());
    }

    /**
     * Проверим порядок: beforeValidation() видит умолчания до фильтров (`' all '` ещё не обрезан).
     *
     * @see RequestSchema::process()
     */
    #[Test]
    public function hookSeesDefaultsBeforeFilters(): void
    {
        $schema = new DefaultsRequestSchema($this->request([]));

        $schema->process();

        self::assertSame(' all ', $schema->seenInHook['status']);
        self::assertSame(1, $schema->seenInHook['page']);
    }

    /**
     * Проверим, что умолчание для поля без правила — ошибка настройки схемы.
     *
     * @see AbstractInputSchema::applyDefinitionDefaults()
     */
    #[Test]
    public function defaultWithoutRuleThrows(): void
    {
        $schema = new DefaultWithoutRuleRequestSchema($this->request([]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Default for "page" has no validation rule.');

        $schema->process();
    }

    /**
     * Проверим, что only() оставляет умолчания только выбранных полей: умолчание исключённого поля не подставляется.
     *
     * @see InputSchemaDefinition::only()
     */
    #[Test]
    public function onlyKeepsDefaultsOfSelectedPaths(): void
    {
        $schema = new DefaultsRequestSchema($this->request([]))->only(['page']);

        $schema->process();

        self::assertSame(['page' => 1], $schema->request()->all());
    }

    /**
     * Проверим, что умолчания частей схемы объединяются: значение добавленной части перекрывает исходное.
     *
     * @see InputSchemaDefinition::merge()
     */
    #[Test]
    public function mergeCombinesDefaults(): void
    {
        $definition = InputSchemaDefinition::make(defaults: ['page' => 1, 'status' => 'all'])
            ->merge(InputSchemaDefinition::make(defaults: ['page' => 10]));

        self::assertSame(['page' => 10, 'status' => 'all'], $definition->defaults());
    }

    /**
     * @param array<string, mixed> $query
     */
    private function request(array $query): Request
    {
        return new Request(
            new ServerRequest('GET', 'https://example.com/', queryParams: $query),
            new Validator(),
        );
    }
}
