<?php

declare(strict_types=1);

namespace PhpSoftBox\Request\Tests;

use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Http\Message\Stream;
use PhpSoftBox\Http\Message\UploadedFile;
use PhpSoftBox\Request\Request;
use PhpSoftBox\Validator\Exception\ValidationException;
use PhpSoftBox\Validator\Rule\StringValidation;
use PhpSoftBox\Validator\ValidationError;
use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;
use PhpSoftBox\Validator\ValidatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use stdClass;

use function strtoupper;
use function trim;

#[CoversClass(Request::class)]
#[CoversMethod(Request::class, 'all')]
#[CoversMethod(Request::class, 'routeParams')]
#[CoversMethod(Request::class, 'merge')]
#[CoversMethod(Request::class, 'replace')]
#[CoversMethod(Request::class, 'validate')]
#[CoversMethod(Request::class, 'filter')]
#[CoversMethod(Request::class, 'input')]
#[CoversMethod(Request::class, 'has')]
final class RequestTest extends TestCase
{
    /**
     * Проверяем, что all() собирает query, body и files, причём body перекрывает query.
     *
     * @see Request::all()
     */
    #[Test]
    public function allCollectsQueryBodyAndFiles(): void
    {
        $psr = new ServerRequest(
            'POST',
            'https://example.com/test?from=query',
            queryParams: ['from' => 'query'],
            uploadedFiles: ['file' => new UploadedFile(new Stream('file'), size: 4)],
            parsedBody: ['from' => 'body'],
        );

        $request = new Request($psr, $this->stubValidator());

        $data = $request->all();

        $this->assertSame('body', $data['from']);
        $this->assertInstanceOf(UploadedFileInterface::class, $data['file']);
    }

    /**
     * Проверяем, что cookies и атрибуты PSR-запроса (сессия, пользователь, служебные данные маршрута)
     * не попадают во входные данные, но остаются доступны через cookies() и attributes().
     *
     * @see Request::all()
     */
    #[Test]
    public function allExcludesCookiesAndRequestAttributes(): void
    {
        $psr = new ServerRequest(
            'POST',
            'https://example.com/',
            cookieParams: ['token' => 'remember-token', 'session_id' => 'sid'],
            parsedBody: ['name' => 'Alex'],
            attributes: [
                'user'    => new stdClass(),
                '_route'  => 'profile.update',
                'session' => 'session-object',
            ],
        );

        $request = new Request($psr, $this->stubValidator());

        $this->assertSame(['name' => 'Alex'], $request->all());
        $this->assertFalse($request->has('token'));
        $this->assertSame('remember-token', $request->cookies()['token']);
        $this->assertSame('profile.update', $request->attributes()['_route']);
    }

    /**
     * Проверяем, что параметры маршрута из `_route_params` попадают в all() и не подменяются через body.
     *
     * @see Request::all()
     * @see Request::routeParams()
     */
    #[Test]
    public function allIncludesRouteParamsWithPriorityOverBody(): void
    {
        $psr = new ServerRequest(
            'POST',
            'https://example.com/products/5',
            parsedBody: ['id' => 999, 'name' => 'Alex'],
            attributes: [
                Request::ROUTE_PARAMS_ATTRIBUTE => ['id' => '5'],
                'id'                            => '5',
            ],
        );

        $request = new Request($psr, $this->stubValidator());

        $this->assertSame(['id' => '5'], $request->routeParams());
        $this->assertSame(['id' => '5', 'name' => 'Alex'], $request->all());
    }

    /**
     * Проверяем работу merge() и replace().
     *
     * @see Request::merge()
     * @see Request::replace()
     */
    #[Test]
    public function mergeAndReplace(): void
    {
        $psr = new ServerRequest('GET', 'https://example.com/?a=1', queryParams: ['a' => 1]);

        $validator = $this->stubValidator();
        $request   = new Request($psr, $validator);

        $request->merge(['b' => 2]);
        $this->assertSame(['a' => 1, 'b' => 2], $request->all());

        $request->replace(['c' => 3]);
        $this->assertSame(['c' => 3], $request->all());
    }

    /**
     * Проверяем, что validate() возвращает отфильтрованные данные и бросает исключение при ошибках.
     *
     * @see Request::validate()
     */
    #[Test]
    public function validateAndValidationResult(): void
    {
        $psr       = new ServerRequest('POST', 'https://example.com/', parsedBody: ['name' => 'John']);
        $validator = $this->stubValidator(['name' => 'John']);
        $request   = new Request($psr, $validator);

        $data = $request->validate(['name' => [new StringValidation()->required()]]);

        $this->assertSame(['name' => 'John'], $data);
        $this->assertSame(['name' => 'John'], $request->filteredData());

        $validatorFail = $this->stubValidator(['name' => ''], [
            'name' => [new ValidationError('name', 'required', 'Поле name обязательно.')],
        ]);
        $requestFail = new Request($psr, $validatorFail);

        $this->expectException(ValidationException::class);
        $requestFail->validate(['name' => [new StringValidation()->required()]]);
    }

    /**
     * Проверяем, что filter() поддерживает цепочки фильтров.
     *
     * @see Request::filter()
     */
    #[Test]
    public function filterSupportsChains(): void
    {
        $psr       = new ServerRequest('POST', 'https://example.com/', parsedBody: ['name' => '  Alex  ']);
        $validator = $this->stubValidator();
        $request   = new Request($psr, $validator);

        $request->filter([
            'name' => [
                static fn (mixed $value): string => trim((string) $value),
                static fn (mixed $value): string => strtoupper((string) $value),
            ],
        ]);

        $this->assertSame('ALEX', $request->input('name'));
    }

    /**
     * Проверяем, что filter() поддерживает вложенные пути.
     *
     * @see Request::filter()
     */
    #[Test]
    public function filterSupportsNestedPaths(): void
    {
        $psr = new ServerRequest('POST', 'https://example.com/', parsedBody: [
            'user' => ['email' => '  a@b.c  '],
            'name' => 'John',
        ]);
        $validator = $this->stubValidator();
        $request   = new Request($psr, $validator);

        $request->filter([
            'user.email' => static fn (mixed $value): string => trim((string) $value),
        ]);

        $this->assertSame('a@b.c', $request->input('user.email'));
        $this->assertSame('John', $request->input('name'));
    }

    /**
     * Проверяем, что filter() поддерживает подстановочные пути.
     *
     * @see Request::filter()
     */
    #[Test]
    public function filterSupportsWildcardPaths(): void
    {
        $psr = new ServerRequest('POST', 'https://example.com/', parsedBody: [
            'items' => [
                ['name' => '  Alpha  '],
                ['name' => '  Beta  '],
            ],
        ]);
        $validator = $this->stubValidator();
        $request   = new Request($psr, $validator);

        $request->filter([
            'items.*.name' => static fn (mixed $value): string => trim((string) $value),
        ]);

        $this->assertSame('Alpha', $request->input('items.0.name'));
        $this->assertSame('Beta', $request->input('items.1.name'));
    }

    /**
     * Проверяем методы input() и has().
     *
     * @see Request::input()
     * @see Request::has()
     */
    #[Test]
    public function inputAndHas(): void
    {
        $psr       = new ServerRequest('POST', 'https://example.com/', parsedBody: ['user' => ['email' => 'a@b.c']]);
        $validator = $this->stubValidator();
        $request   = new Request($psr, $validator);

        $this->assertTrue($request->has('user.email'));
        $this->assertSame('a@b.c', $request->input('user.email'));
        $this->assertSame('x', $request->input('user.missing', 'x'));
    }

    /**
     * @param array<string, mixed> $filtered
     * @param array<string, list<ValidationError>> $errors
     */
    private function stubValidator(array $filtered = [], array $errors = []): ValidatorInterface
    {
        return new readonly class ($filtered, $errors) implements ValidatorInterface {
            public function __construct(
                private array $filtered,
                private array $errors,
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
                return new ValidationResult($this->errors, $this->filtered);
            }
        };
    }
}
