<?php
declare(strict_types=1);

final class SmartThingsAdapterV2
{
    public function notImplemented(): array
    {
        return [
            'ok' => false,
            'error' => 'NOT_IMPLEMENTED',
            'service' => 'SmartThingsAdapterV2',
        ];
    }
}
