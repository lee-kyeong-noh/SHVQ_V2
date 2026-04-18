<?php
declare(strict_types=1);

final class CsrfService
{
    private SessionManager $session;
    private string $tokenKey;
    private string $headerKey;

    public function __construct(SessionManager $session, array $security)
    {
        $this->session = $session;
        $this->tokenKey = (string)$security['csrf']['token_key'];
        $this->headerKey = strtoupper(str_replace('-', '_', (string)$security['csrf']['header_key']));
    }

    public function issueToken(): string
    {
        $this->session->start();
        if (!isset($_SESSION[$this->tokenKey]) || !is_string($_SESSION[$this->tokenKey])) {
            $_SESSION[$this->tokenKey] = bin2hex(random_bytes(32));
        }

        return (string)$_SESSION[$this->tokenKey];
    }

    public function regenerateToken(): string
    {
        $this->session->start();
        $_SESSION[$this->tokenKey] = bin2hex(random_bytes(32));

        return (string)$_SESSION[$this->tokenKey];
    }

    public function validateFromRequest(?string $token = null): bool
    {
        $this->session->start();

        $inputToken = $token;
        if (!$inputToken) {
            /* GET 파라미터는 서버 로그/Referer에 노출되므로 POST만 허용 */
            $inputToken = $_POST['csrf_token'] ?? null;
        }

        if (!$inputToken) {
            $serverKey = 'HTTP_' . $this->headerKey;
            $inputToken = $_SERVER[$serverKey] ?? null;
        }

        $sessionToken = $_SESSION[$this->tokenKey] ?? null;
        if (!is_string($inputToken) || !is_string($sessionToken)) {
            return false;
        }

        return hash_equals($sessionToken, $inputToken);
    }
}
