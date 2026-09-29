<?php

declare(strict_types=1);

namespace PhpSoftBox\Request;

use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;

abstract class RequestSchema extends AbstractInputSchema
{
    /**
     * Исходные данные запроса, с которых начинается каждый process(): повторная обработка
     * и копии схемы (only(), except(), merge()) не применяют фильтры к уже отфильтрованным данным.
     *
     * @var array<string, mixed>
     */
    private array $sourcePayload;

    /**
     * Последний payload, записанный схемой в Request через replacePayload().
     *
     * @var array<string, mixed>
     */
    private array $syncedPayload;

    public function __construct(
        protected Request $request,
    ) {
        $this->sourcePayload = $request->all();
        $this->syncedPayload = $this->sourcePayload;

        parent::__construct($this->sourcePayload, $request->validator());
    }

    public function beforeValidation(): void
    {
    }

    public function process(?ValidationOptions $options = null): ValidationResult
    {
        // Payload загружается до хука, умолчания defaults() — тоже: хук и фильтры видят подставленные значения,
        // правки через mergePayload()/replacePayload() и через Request (filter(), merge(), replace()) сохраняются.
        $this->refreshSourcePayload();
        $this->replacePayload($this->sourcePayload);
        $this->applyDefinitionDefaults($this->schemaDefinition());
        $this->beforeValidation();
        $this->syncPayloadAfterHook();
        $definition = $this->schemaDefinition();

        $filterResult = $this->applyDefinitionFilters($definition);
        if ($filterResult !== null) {
            $this->setValidationResult($filterResult);

            return $filterResult;
        }

        $result = $this->validatePayload($definition, $options);

        $this->setValidationResult($result);

        return $result;
    }

    public function request(): Request
    {
        return $this->request;
    }

    /**
     * Payload схемы и данные Request синхронизируются: правки видны и через payload(), и через Request.
     *
     * @param array<string, mixed> $payload
     */
    protected function replacePayload(array $payload): void
    {
        parent::replacePayload($payload);
        $this->request->replace($payload);
        $this->syncedPayload = $payload;
    }

    protected function route(): RouteParameters
    {
        return new RouteParameters($this->routeParams());
    }

    protected function validationContext(): mixed
    {
        return $this->request;
    }

    /**
     * Если Request изменили снаружи схемы (после её создания или последней обработки),
     * исходными данными становятся актуальные данные Request.
     */
    private function refreshSourcePayload(): void
    {
        $current = $this->request->all();

        if ($current !== $this->syncedPayload) {
            $this->sourcePayload = $current;
        }
    }

    /**
     * Прямое присваивание `$this->payload` в хуке переносится в Request; иначе берутся данные Request,
     * которые хук мог изменить через Request::filter()/merge()/replace().
     */
    private function syncPayloadAfterHook(): void
    {
        if ($this->payload !== $this->syncedPayload) {
            $this->replacePayload($this->payload);

            return;
        }

        $this->replacePayload($this->request->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function routeParams(): array
    {
        return $this->request->routeParams();
    }
}
