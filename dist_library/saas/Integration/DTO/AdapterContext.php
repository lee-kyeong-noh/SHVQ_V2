<?php
declare(strict_types=1);

namespace SHVQ\Integration\DTO;

final class AdapterContext
{
    private string $provider;
    private string $serviceCode;
    private int $tenantId;
    private int $accountIdx;
    /** @var array<string,string> */
    private array $credentials;
    /** @var array<string,mixed> */
    private array $options;
    private string $traceId;

    /**
     * @param array<string,string> $credentials
     * @param array<string,mixed> $options
     */
    public function __construct(
        string $provider,
        string $serviceCode,
        int $tenantId,
        int $accountIdx,
        array $credentials = [],
        array $options = [],
        string $traceId = ''
    ) {
        $this->provider = trim($provider) !== '' ? trim($provider) : 'mail';
        $this->serviceCode = trim($serviceCode) !== '' ? trim($serviceCode) : 'shvq';
        $this->tenantId = max(0, $tenantId);
        $this->accountIdx = max(0, $accountIdx);
        $this->credentials = $this->normalizeMap($credentials);
        $this->options = $options;
        $this->traceId = trim($traceId) !== '' ? trim($traceId) : uniqid('adapter_ctx_', true);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $provider = trim((string)($data['provider'] ?? 'mail'));
        $serviceCode = trim((string)($data['service_code'] ?? $data['serviceCode'] ?? 'shvq'));
        $tenantId = (int)($data['tenant_id'] ?? $data['tenantId'] ?? 0);
        $accountIdx = (int)($data['account_idx'] ?? $data['accountIdx'] ?? 0);

        $credentialsRaw = $data['credentials'] ?? [];
        $credentials = is_array($credentialsRaw) ? $credentialsRaw : [];

        $optionsRaw = $data['options'] ?? [];
        $options = is_array($optionsRaw) ? $optionsRaw : [];

        $traceId = trim((string)($data['trace_id'] ?? $data['traceId'] ?? ''));

        return new self($provider, $serviceCode, $tenantId, $accountIdx, $credentials, $options, $traceId);
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

    /** @return array<string,string> */
    public function credentials(): array
    {
        return $this->credentials;
    }

    public function credential(string $key, string $default = ''): string
    {
        $name = trim($key);
        if ($name === '') {
            return $default;
        }

        if (array_key_exists($name, $this->credentials)) {
            return (string)$this->credentials[$name];
        }

        $lowerName = strtolower($name);
        if (array_key_exists($lowerName, $this->credentials)) {
            return (string)$this->credentials[$lowerName];
        }

        return $default;
    }

    /** @return array<string,mixed> */
    public function options(): array
    {
        return $this->options;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        $name = trim($key);
        if ($name === '') {
            return $default;
        }

        return array_key_exists($name, $this->options) ? $this->options[$name] : $default;
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'service_code' => $this->serviceCode,
            'tenant_id' => $this->tenantId,
            'account_idx' => $this->accountIdx,
            'credentials' => $this->credentials,
            'options' => $this->options,
            'trace_id' => $this->traceId,
        ];
    }

    /** @param array<string,mixed> $input */
    private function normalizeMap(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            $name = trim((string)$key);
            if ($name === '') {
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $out[$name] = trim((string)$value);
        }

        return $out;
    }
}

