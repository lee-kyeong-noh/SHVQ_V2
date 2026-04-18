<?php
declare(strict_types=1);

final class SalesService
{
    public function notImplemented(): array
    {
        return [
            'ok' => false,
            'error' => 'NOT_IMPLEMENTED',
            'service' => 'SalesService',
        ];
    }
}
