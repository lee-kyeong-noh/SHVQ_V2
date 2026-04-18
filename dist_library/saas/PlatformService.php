<?php
declare(strict_types=1);

final class PlatformService
{
    public function notImplemented(): array
    {
        return [
            'ok' => false,
            'error' => 'NOT_IMPLEMENTED',
            'service' => 'PlatformService',
        ];
    }
}
