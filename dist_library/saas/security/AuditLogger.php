<?php
declare(strict_types=1);

final class AuditLogger
{
    private PDO $db;
    private array $security;
    private ClientIpResolver $ipResolver;

    public function __construct(PDO $db, array $security)
    {
        $this->db = $db;
        $this->security = $security;
        $this->ipResolver = new ClientIpResolver($security);
    }

    public function log(string $action, int $userPk, string $result, string $message = '', array $meta = []): void
    {
        try {
            $table = $this->security['audit']['table'];
            $sql = sprintf(
                'INSERT INTO %s
                 (request_id, action_key, user_pk, login_id, result_code, message, client_ip, user_agent, meta_json, created_at)
                 VALUES (:request_id, :action_key, :user_pk, :login_id, :result_code, :message, :client_ip, :user_agent, :meta_json, GETDATE())',
                $table
            );

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'request_id' => $this->requestId(),
                'action_key' => $action,
                'user_pk' => $userPk,
                'login_id' => (string)($meta['login_id'] ?? ''),
                'result_code' => $result,
                'message' => mb_substr($message, 0, 500),
                'client_ip' => $this->ipResolver->resolve(),
                'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
            // Degrade mode: audit table can be created later by migration.
        }
    }

    private function requestId(): string
    {
        if (!empty($_SERVER['HTTP_X_REQUEST_ID'])) {
            return (string)$_SERVER['HTTP_X_REQUEST_ID'];
        }

        return bin2hex(random_bytes(8));
    }
}
