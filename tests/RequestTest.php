<?php

declare(strict_types=1);

namespace PhpSoftBox\Request\Tests;

use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Http\Message\Stream;
use PhpSoftBox\Http\Message\UploadedFile;
use PhpSoftBox\Request\Request;
use PhpSoftBox\Validator\Rule\StringValidation;
use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationError;
use PhpSoftBox\Validator\ValidationResult;
use PhpSoftBox\Validator\ValidatorInterface;
use PhpSoftBox\Validator\Exception\ValidationException;
use Psr\Http\Message\UploadedFileInterface;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    /**
     * Проверяем, что Request собирает данные из query, body, cookies, files и attributes.
     */
    public function testCollectsAllSources(): void
    {
        $psr = new ServerRequest(
            'POST',
            'https://example.com/test?from=query',
            cookieParams: ['from' => 'cookie'],
            queryParams: ['from' => 'query'],
            uploadedFiles: ['file' => new UploadedFile(new Stream('file'), size: 4)],
            parsedBody: ['from' => 'body'],
            attributes: ['from' => 'attr'],
        );

        $validator = $this->stubValidator();
        $request = new Request($psr, $validator);

        $data = $request->all();

        $this->assertSame('attr', $data['from']);
        $this->assertInstanceOf(UploadedFileInterface::class, $data['file']);
    }

    /**
     * Проверяем работу merge() и replace().
     */
    public function testMergeAndReplace(): void
    {
        $psr = new ServerRequest('GET', 'https://example.com/?a=1', queryParams: ['a' => 1]);

        $validator = $this->stubValidator();
        $request = new Request($psr, $validator);

        $request->merge(['b' => 2]);
        $this->assertSame(['a' => 1, 'b' => 2], $request->all());

        $request->replace(['c' => 3]);
        $this->assertSame(['c' => 3], $request->all());
    }

    /**
     * Проверяем, что validate() возвращает отфильтрованные данные и бросает исключение при ошибках.
     */
    public function testValidateAndValidationResult(): void
    {
        $psr = new ServerRequest('POST', 'https://example.com/', parsedBody: ['name' => 'John']);
        $validator = $this->stubValidator(['name' => 'John']);
        $request = new Request($psr, $validator);

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
     * Проверяем методы input() и has().
     */
    public function testInputAndHas(): void
    {
        $psr = new ServerRequest('POST', 'https://example.com/', parsedBody: ['user' => ['email' => 'a@b.c']]);
        $validator = $this->stubValidator();
        $request = new Request($psr, $validator);

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
