<?php

declare(strict_types=1);

namespace PhpSoftBox\Request\Tests;

use PhpSoftBox\Request\InputSchemaDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;

#[CoversClass(InputSchemaDefinition::class)]
final class InputSchemaDefinitionTest extends TestCase
{
    /**
     * Проверяет, что only() оставляет выбранные пути и их родительские правила.
     */
    #[Test]
    public function onlyKeepsSelectedPathsAndParentRules(): void
    {
        $definition = InputSchemaDefinition::make(
            rules: [
                'user'         => ['array'],
                'user.email'   => ['email'],
                'user.name'    => ['string'],
                'items'        => ['array'],
                'items.*.name' => ['string'],
            ],
            filters: [
                'user.email'   => static fn (mixed $value): mixed => $value,
                'user.name'    => static fn (mixed $value): mixed => $value,
                'items.*.name' => static fn (mixed $value): mixed => $value,
            ],
            messages: [
                'user.email.required' => 'Email required',
                'user.name.required'  => 'Name required',
            ],
            attributes: [
                'user.email' => 'Email',
                'user.name'  => 'Name',
            ],
        );

        $only = $definition->only(['user.email']);

        self::assertSame(['user', 'user.email'], array_keys($only->rules()));
        self::assertSame(['user.email'], array_keys($only->filters()));
        self::assertSame(['user.email.required'], array_keys($only->messages()));
        self::assertSame(['user.email'], array_keys($only->attributes()));
    }

    /**
     * Проверяет, что without() удаляет выбранный путь и дочерние правила.
     */
    #[Test]
    public function withoutRemovesSelectedPathsAndChildren(): void
    {
        $definition = InputSchemaDefinition::make(
            rules: [
                'user'         => ['array'],
                'user.email'   => ['email'],
                'user.name'    => ['string'],
                'items.*.name' => ['string'],
            ],
            filters: [
                'user.email'   => static fn (mixed $value): mixed => $value,
                'items.*.name' => static fn (mixed $value): mixed => $value,
            ],
        );

        $without = $definition->without(['user.email']);

        self::assertSame(['user', 'user.name', 'items.*.name'], array_keys($without->rules()));
        self::assertSame(['items.*.name'], array_keys($without->filters()));
    }

    /**
     * Проверяет, что merge() объединяет правила, фильтры и подписи.
     */
    #[Test]
    public function mergeCombinesDefinitionParts(): void
    {
        $base = InputSchemaDefinition::make(
            rules: ['name' => ['string']],
            filters: ['name' => static fn (mixed $value): mixed => $value],
            messages: ['name.required' => 'Name required'],
            attributes: ['name' => 'Name'],
        );
        $part = InputSchemaDefinition::make(
            rules: ['email' => ['email']],
            filters: ['email' => static fn (mixed $value): mixed => $value],
            messages: ['email.required' => 'Email required'],
            attributes: ['email' => 'Email'],
        );

        $merged = $base->merge($part);

        self::assertSame(['name', 'email'], array_keys($merged->rules()));
        self::assertSame(['name', 'email'], array_keys($merged->filters()));
        self::assertSame(['name.required', 'email.required'], array_keys($merged->messages()));
        self::assertSame(['name', 'email'], array_keys($merged->attributes()));
    }
}
