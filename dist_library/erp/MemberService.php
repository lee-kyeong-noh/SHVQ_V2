<?php
declare(strict_types=1);

final class MemberService
{
    public function notImplemented(): array
    {
        return [
            'ok' => false,
            'error' => 'NOT_IMPLEMENTED',
            'service' => 'MemberService',
        ];
    }
}
