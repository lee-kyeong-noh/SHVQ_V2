<?php
declare(strict_types=1);

final class AuthAuditService
{
    private PDO $db;
    private array $security;

    public function __construct(PDO $db, array $security)
    {
        $this->db = $db;
        $this->security = $security;
    }

    public function list(array $filters = []): array
    {
        $page = max(1, (int)($filters['page'] ?? 1));
        $limit = max(1, (int)($filters['limit'] ?? 20));
        $maxLimit = max(10, (int)($this->security['auth_audit']['max_limit'] ?? 200));
        $limit = min($limit, $maxLimit);

        $where = ['1=1'];
        $params = [];

        $loginId = trim((string)($filters['login_id'] ?? ''));
        if ($loginId !== '') {
            $where[] = 'login_id LIKE :login_id';
            $params['login_id'] = '%' . $loginId . '%';
        }

        $actionKey = trim((string)($filters['action_key'] ?? ''));
        if ($actionKey !== '') {
            $where[] = 'action_key = :action_key';
            $params['action_key'] = $actionKey;
        }

        $resultCode = trim((string)($filters['result_code'] ?? ''));
        if ($resultCode !== '') {
            $where[] = 'result_code = :result_code';
            $params['result_code'] = $resultCode;
        }

        $userPk = (int)($filters['user_pk'] ?? 0);
        if ($userPk > 0) {
            $where[] = 'user_pk = :user_pk';
            $params['user_pk'] = $userPk;
        }

        $fromAt = $this->normalizeDateTimeString($filters['from_at'] ?? null, false);
        if ($fromAt !== null) {
            $where[] = 'created_at >= :from_at';
            $params['from_at'] = $fromAt;
        }

        $toAt = $this->normalizeDateTimeString($filters['to_at'] ?? null, true);
        if ($toAt !== null) {
            $where[] = 'created_at <= :to_at';
            $params['to_at'] = $toAt;
        }

        $whereSql = implode(' AND ', $where);
        $table = (string)$this->security['audit']['table'];

        $countSql = sprintf('SELECT COUNT(1) AS cnt FROM %s WHERE %s', $table, $whereSql);
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $countRow = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int)($countRow['cnt'] ?? 0);

        $offset = ($page - 1) * $limit;
        $listSql = sprintf(
            'SELECT idx, request_id, action_key, user_pk, login_id, result_code, message, client_ip, created_at
             FROM %s
             WHERE %s
             ORDER BY idx DESC
             OFFSET %d ROWS FETCH NEXT %d ROWS ONLY',
            $table,
            $whereSql,
            $offset,
            $limit
        );

        $listStmt = $this->db->prepare($listSql);
        $listStmt->execute($params);
        $items = $listStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => is_array($items) ? $items : [],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $limit > 0 ? (int)ceil($total / $limit) : 0,
            ],
        ];
    }

    private function normalizeDateTimeString(mixed $value, bool $endOfDay): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $trimmed = trim($value);

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
                $base = new DateTimeImmutable($trimmed . ' 00:00:00');
                if ($endOfDay) {
                    $base = $base->setTime(23, 59, 59);
                }
                return $base->format('Y-m-d H:i:s');
            }

            $dt = new DateTimeImmutable($trimmed);
            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
