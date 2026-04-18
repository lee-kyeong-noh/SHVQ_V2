<?php
declare(strict_types=1);

final class ClientIpResolver
{
    private array $network;

    public function __construct(array $security)
    {
        $this->network = is_array($security['network'] ?? null) ? $security['network'] : [];
    }

    public function resolve(?array $server = null): string
    {
        $source = is_array($server) ? $server : $_SERVER;

        $remoteAddr = trim((string)($source['REMOTE_ADDR'] ?? ''));
        $trustProxyHeaders = (bool)($this->network['trust_proxy_headers'] ?? false);
        $trustedProxies = is_array($this->network['trusted_proxies'] ?? null)
            ? $this->network['trusted_proxies']
            : [];

        if (!$trustProxyHeaders || !$this->isTrustedProxy($remoteAddr, $trustedProxies)) {
            return $remoteAddr !== '' ? $remoteAddr : '0.0.0.0';
        }

        $forwarded = trim((string)($source['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded === '') {
            return $remoteAddr !== '' ? $remoteAddr : '0.0.0.0';
        }

        $parts = explode(',', $forwarded);
        $candidate = trim((string)$parts[0]);

        return $candidate !== '' ? $candidate : ($remoteAddr !== '' ? $remoteAddr : '0.0.0.0');
    }

    private function isTrustedProxy(string $remoteAddr, array $trustedProxies): bool
    {
        if ($remoteAddr === '') {
            return false;
        }

        foreach ($trustedProxies as $proxy) {
            if (is_string($proxy) && trim($proxy) === $remoteAddr) {
                return true;
            }
        }

        return false;
    }
}
