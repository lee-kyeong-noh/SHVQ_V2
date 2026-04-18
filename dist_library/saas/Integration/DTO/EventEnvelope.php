<?php
declare(strict_types=1);

namespace SHVQ\Integration\DTO;

final class EventEnvelope
{
    private string $provider;
    private string $serviceCode;
    private int $tenantId;
    private int $accountIdx;
    private string $eventType;
    private string $resourceType;
    private string $resourceId;
    /** @var array<string,mixed> */
    private array $payload;
    private string $externalEventId;
    private string $occurredAt;
    private string $receivedAt;
    private string $idempotencyKey;

    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        string $provider,
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        string $eventType,
        string $resourceType,
        string $resourceId,
        array $payload = [],
        string $externalEventId = '',
        string $occurredAt = '',
        string $receivedAt = '',
        string $idempotencyKey = ''
    ) {
        $this->provider = trim($provider) !== '' ? trim($provider) : 'mail';
        $this->serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $this->tenantId = max(0, $tenantId);
        $this->accountIdx = max(0, $accountIdx);
        $this->eventType = trim($eventType);
        $this->resourceType = trim($resourceType);
        $this->resourceId = trim($resourceId);
        $this->payload = $payload;
        $this->externalEventId = trim($externalEventId);
        $this->occurredAt = $this->normalizeDate($occurredAt);
        $this->receivedAt = $this->normalizeDate($receivedAt);
        $this->idempotencyKey = trim($idempotencyKey) !== ''
            ? trim($idempotencyKey)
            : md5($this->provider . ':' . $this->tenantId . ':' . $this->eventType . ':' . $this->resourceId);
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function serviceCode(): string
    {
        return $this->serviceCode;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function accountIdx(): int
    {
        return $this->accountIdx;
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function resourceType(): string
    {
        return $this->resourceType;
    }

    public function resourceId(): string
    {
        return $this->resourceId;
    }

    /** @return array<string,mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function externalEventId(): string
    {
        return $this->externalEventId;
    }

    public function occurredAt(): string
    {
        return $this->occurredAt;
    }

    public function receivedAt(): string
    {
        return $this->receivedAt;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    private function normalizeDate(string $input): string
    {
        $text = trim($input);
        if ($text === '') {
            return date('c');
        }

        $ts = strtotime($text);
        if ($ts === false) {
            return date('c');
        }

        return date('c', $ts);
    }
}

