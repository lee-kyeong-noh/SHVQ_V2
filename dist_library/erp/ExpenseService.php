<?php
declare(strict_types=1);

final class ExpenseService
{
    public function notImplemented(): array
    {
        return [
            'ok' => false,
            'error' => 'NOT_IMPLEMENTED',
            'service' => 'ExpenseService',
        ];
    }
}
