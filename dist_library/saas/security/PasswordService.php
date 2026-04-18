<?php
declare(strict_types=1);

final class PasswordService
{
    private PDO $db;
    private array $authCfg;

    public function __construct(PDO $db, array $security)
    {
        $this->db = $db;
        $this->authCfg = $security['auth'];
    }

    public function verifyAndMigrate(array $userRow, string $plainPassword): bool
    {
        $passwordColumn = (string)$this->authCfg['user_password_column'];
        $stored = (string)($userRow[$passwordColumn] ?? '');
        if ($stored === '') {
            return false;
        }

        $hashInfo = password_get_info($stored);
        if (($hashInfo['algo'] ?? 0) !== 0) {
            $verified = password_verify($plainPassword, $stored);
            if (!$verified) {
                return false;
            }

            if (password_needs_rehash($stored, PASSWORD_BCRYPT)) {
                $this->updatePasswordHash((int)$userRow[$this->authCfg['user_pk_column']], $plainPassword);
            }

            return true;
        }

        if (!(bool)$this->authCfg['allow_legacy_password']) {
            return false;
        }

        // Guard: legacy plain/MD5 comparison should run only for short non-bcrypt values.
        if (strlen($stored) < 60 && (hash_equals($stored, $plainPassword) || hash_equals(strtolower($stored), md5($plainPassword)))) {
            $this->updatePasswordHash((int)$userRow[$this->authCfg['user_pk_column']], $plainPassword);
            return true;
        }

        return false;
    }

    /** MSSQL 식별자 안전 인용 */
    private function qi(string $name): string
    {
        return '[' . str_replace(']', ']]', $name) . ']';
    }

    private function updatePasswordHash(int $userPk, string $plainPassword): void
    {
        $table = (string)$this->authCfg['user_table'];
        $pkColumn = (string)$this->authCfg['user_pk_column'];
        $passwordColumn = (string)$this->authCfg['user_password_column'];

        $hash = password_hash($plainPassword, PASSWORD_BCRYPT);
        $sql = sprintf(
            'UPDATE %s SET %s = :password_hash WHERE %s = :user_pk',
            $this->qi($table),
            $this->qi($passwordColumn),
            $this->qi($pkColumn)
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'password_hash' => $hash,
            'user_pk' => $userPk,
        ]);

        // Keep login working even before schema migration: migrated_at update is best-effort.
        try {
            $migratedAtSql = sprintf(
                'UPDATE %s SET password_migrated_at = GETDATE() WHERE %s = :user_pk',
                $this->qi($table),
                $this->qi($pkColumn)
            );
            $migratedAtStmt = $this->db->prepare($migratedAtSql);
            $migratedAtStmt->execute(['user_pk' => $userPk]);
        } catch (Throwable) {
            // Ignore when column does not exist yet.
        }
    }
}
