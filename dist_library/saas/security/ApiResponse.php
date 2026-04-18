<?php
declare(strict_types=1);

final class ApiResponse
{
    public static function success(array $data = [], string $code = 'OK', string $message = 'OK', int $httpStatus = 200): void
    {
        self::send([
            'success' => true,
            'ok' => true,
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ], $httpStatus);
    }

    public static function error(string $code, string $message, int $httpStatus = 400, array $detail = []): void
    {
        $payload = [
            'success' => false,
            'ok' => false,
            'code' => $code,
            'error' => $code,
            'message' => $message,
        ];

        if ($detail !== []) {
            $payload['detail'] = $detail;
        }

        self::send($payload, $httpStatus);
    }

    public static function fromLegacy(array $legacyPayload, int $successStatus = 200, int $errorStatus = 400): void
    {
        $ok = (bool)($legacyPayload['ok'] ?? false);

        if ($ok) {
            $code = is_string($legacyPayload['code'] ?? null) ? (string)$legacyPayload['code'] : 'OK';
            $message = is_string($legacyPayload['message'] ?? null) ? (string)$legacyPayload['message'] : 'OK';

            $data = $legacyPayload;
            unset($data['ok'], $data['success'], $data['code'], $data['message']);

            self::success($data, $code, $message, $successStatus);
            return;
        }

        $code = is_string($legacyPayload['error'] ?? null)
            ? (string)$legacyPayload['error']
            : (is_string($legacyPayload['code'] ?? null) ? (string)$legacyPayload['code'] : 'ERROR');
        $message = is_string($legacyPayload['message'] ?? null) ? (string)$legacyPayload['message'] : $code;

        $detail = $legacyPayload;
        unset($detail['ok'], $detail['success'], $detail['error'], $detail['code'], $detail['message']);

        self::error($code, $message, $errorStatus, $detail);
    }

    private static function send(array $payload, int $httpStatus): void
    {
        if (!headers_sent()) {
            http_response_code($httpStatus);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
