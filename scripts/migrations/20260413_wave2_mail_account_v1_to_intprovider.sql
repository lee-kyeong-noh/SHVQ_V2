/*
  SHVQ_V2 - Mail Account Migration (V1 -> V2 Integration)
  목적:
  1) V1 CSM_C004732.dbo.Tb_Mail_Accounts 를 V2 dbo.Tb_IntProviderAccount로 이관
  2) 메일 연결 테스트/발송 호환을 위해 dbo.Tb_IntCredential도 동시 업서트
  3) 재실행 가능(멱등) 스크립트

  주의:
  - V1 DB(CSM_C004732)는 조회 전용 (DDL 금지)
  - V2 DB(CSM_C004732_V2)에서 실행
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
USE [CSM_C004732_V2];
GO

DECLARE @SourceDb SYSNAME = N'CSM_C004732';
DECLARE @SourceTable SYSNAME = N'Tb_Mail_Accounts';
DECLARE @ServiceCode VARCHAR(30) = 'shvq';
DECLARE @TenantId INT = 0; -- 0 이면 default tenant 자동 선택

IF DB_ID(@SourceDb) IS NULL
BEGIN
    RAISERROR(N'[STOP] Source DB not found: %s', 16, 1, @SourceDb);
    RETURN;
END;

DECLARE @SourceObj NVARCHAR(300) = QUOTENAME(@SourceDb) + N'.dbo.' + QUOTENAME(@SourceTable);

IF OBJECT_ID(@SourceObj, N'U') IS NULL
BEGIN
    RAISERROR(N'[STOP] Source table not found: %s', 16, 1, @SourceObj);
    RETURN;
END;

IF OBJECT_ID(N'dbo.Tb_IntProviderAccount', N'U') IS NULL
BEGIN
    RAISERROR(N'[STOP] Target table missing: dbo.Tb_IntProviderAccount', 16, 1);
    RETURN;
END;

IF OBJECT_ID(N'dbo.Tb_IntCredential', N'U') IS NULL
BEGIN
    RAISERROR(N'[STOP] Target table missing: dbo.Tb_IntCredential', 16, 1);
    RETURN;
END;

IF COL_LENGTH('dbo.Tb_IntProviderAccount', 'user_pk') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_IntProviderAccount
        ADD user_pk INT NULL;
END;

IF @TenantId <= 0
BEGIN
    SELECT TOP 1 @TenantId = idx
    FROM dbo.Tb_SvcTenant
    WHERE service_code = @ServiceCode
      AND status = 'ACTIVE'
    ORDER BY CASE WHEN is_default = 1 THEN 0 ELSE 1 END, idx ASC;
END;

IF ISNULL(@TenantId, 0) <= 0
BEGIN
    RAISERROR(N'[STOP] tenant_id could not be resolved. Set @TenantId explicitly.', 16, 1);
    RETURN;
END;

IF OBJECT_ID('tempdb..#SrcMail') IS NOT NULL DROP TABLE #SrcMail;
CREATE TABLE #SrcMail (
    src_id INT NOT NULL,
    user_id NVARCHAR(120) NULL,
    account_name NVARCHAR(200) NULL,
    email NVARCHAR(320) NULL,
    imap_host NVARCHAR(200) NULL,
    imap_port INT NULL,
    imap_ssl INT NULL,
    imap_username NVARCHAR(320) NULL,
    imap_password NVARCHAR(MAX) NULL,
    is_default INT NULL,
    is_active INT NULL,
    deleted_at DATETIME NULL
);

DECLARE @ExprSrcId NVARCHAR(MAX) = N'ROW_NUMBER() OVER (ORDER BY (SELECT NULL))';
DECLARE @ExprUserId NVARCHAR(MAX) = N'NULL';
DECLARE @ExprAccountName NVARCHAR(MAX) = N'NULL';
DECLARE @ExprEmail NVARCHAR(MAX) = N'NULL';
DECLARE @ExprImapHost NVARCHAR(MAX) = N'''shvision.hanbiro.net''';
DECLARE @ExprImapPort NVARCHAR(MAX) = N'993';
DECLARE @ExprImapSsl NVARCHAR(MAX) = N'1';
DECLARE @ExprImapUser NVARCHAR(MAX) = N'NULL';
DECLARE @ExprImapPass NVARCHAR(MAX) = N'NULL';
DECLARE @ExprIsDefault NVARCHAR(MAX) = N'0';
DECLARE @ExprIsActive NVARCHAR(MAX) = N'1';
DECLARE @ExprDeletedAt NVARCHAR(MAX) = N'NULL';

IF COL_LENGTH(@SourceObj, 'id') IS NOT NULL
    SET @ExprSrcId = N'TRY_CONVERT(INT, s.[id])';
ELSE IF COL_LENGTH(@SourceObj, 'idx') IS NOT NULL
    SET @ExprSrcId = N'TRY_CONVERT(INT, s.[idx])';

IF COL_LENGTH(@SourceObj, 'user_id') IS NOT NULL
    SET @ExprUserId = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(120), s.[user_id]))), '''')';
ELSE IF COL_LENGTH(@SourceObj, 'user_idx') IS NOT NULL
    SET @ExprUserId = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(120), s.[user_idx]))), '''')';

IF COL_LENGTH(@SourceObj, 'account_name') IS NOT NULL
    SET @ExprAccountName = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(200), s.[account_name]))), '''')';
ELSE IF COL_LENGTH(@SourceObj, 'display_name') IS NOT NULL
    SET @ExprAccountName = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(200), s.[display_name]))), '''')';
ELSE IF COL_LENGTH(@SourceObj, 'name') IS NOT NULL
    SET @ExprAccountName = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(200), s.[name]))), '''')';

IF COL_LENGTH(@SourceObj, 'email') IS NOT NULL
    SET @ExprEmail = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(320), s.[email]))), '''')';
ELSE IF COL_LENGTH(@SourceObj, 'from_email') IS NOT NULL
    SET @ExprEmail = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(320), s.[from_email]))), '''')';

IF COL_LENGTH(@SourceObj, 'imap_host') IS NOT NULL
    SET @ExprImapHost = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(200), s.[imap_host]))), '''')';
ELSE IF COL_LENGTH(@SourceObj, 'host') IS NOT NULL
    SET @ExprImapHost = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(200), s.[host]))), '''')';

IF COL_LENGTH(@SourceObj, 'imap_port') IS NOT NULL
    SET @ExprImapPort = N'TRY_CONVERT(INT, s.[imap_port])';
ELSE IF COL_LENGTH(@SourceObj, 'port') IS NOT NULL
    SET @ExprImapPort = N'TRY_CONVERT(INT, s.[port])';

IF COL_LENGTH(@SourceObj, 'imap_ssl') IS NOT NULL
    SET @ExprImapSsl = N'TRY_CONVERT(INT, s.[imap_ssl])';
ELSE IF COL_LENGTH(@SourceObj, 'ssl') IS NOT NULL
    SET @ExprImapSsl = N'TRY_CONVERT(INT, s.[ssl])';

IF COL_LENGTH(@SourceObj, 'imap_username') IS NOT NULL
    SET @ExprImapUser = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(320), s.[imap_username]))), '''')';
ELSE IF COL_LENGTH(@SourceObj, 'login_id') IS NOT NULL
    SET @ExprImapUser = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(320), s.[login_id]))), '''')';
ELSE IF COL_LENGTH(@SourceObj, 'id') IS NOT NULL
    SET @ExprImapUser = N'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(320), s.[id]))), '''')';

IF COL_LENGTH(@SourceObj, 'imap_password') IS NOT NULL
    SET @ExprImapPass = N'NULLIF(CONVERT(NVARCHAR(MAX), s.[imap_password]), '''')';
ELSE IF COL_LENGTH(@SourceObj, 'password') IS NOT NULL
    SET @ExprImapPass = N'NULLIF(CONVERT(NVARCHAR(MAX), s.[password]), '''')';
ELSE IF COL_LENGTH(@SourceObj, 'passwd') IS NOT NULL
    SET @ExprImapPass = N'NULLIF(CONVERT(NVARCHAR(MAX), s.[passwd]), '''')';

IF COL_LENGTH(@SourceObj, 'is_default') IS NOT NULL
    SET @ExprIsDefault = N'TRY_CONVERT(INT, s.[is_default])';
ELSE IF COL_LENGTH(@SourceObj, 'is_primary') IS NOT NULL
    SET @ExprIsDefault = N'TRY_CONVERT(INT, s.[is_primary])';

IF COL_LENGTH(@SourceObj, 'is_active') IS NOT NULL
    SET @ExprIsActive = N'TRY_CONVERT(INT, s.[is_active])';
ELSE IF COL_LENGTH(@SourceObj, 'status') IS NOT NULL
    SET @ExprIsActive = N'CASE WHEN UPPER(LTRIM(RTRIM(CONVERT(NVARCHAR(30), s.[status])))) IN (''ACTIVE'',''ON'',''1'',''Y'',''YES'',''TRUE'') THEN 1 ELSE 0 END';

IF COL_LENGTH(@SourceObj, 'deleted_at') IS NOT NULL
    SET @ExprDeletedAt = N'TRY_CONVERT(DATETIME, s.[deleted_at])';

DECLARE @LoadSql NVARCHAR(MAX) = N'
INSERT INTO #SrcMail
(
    src_id, user_id, account_name, email,
    imap_host, imap_port, imap_ssl, imap_username, imap_password,
    is_default, is_active, deleted_at
)
SELECT
    ISNULL(' + @ExprSrcId + N', ROW_NUMBER() OVER (ORDER BY (SELECT NULL))) AS src_id,
    ' + @ExprUserId + N' AS user_id,
    ' + @ExprAccountName + N' AS account_name,
    ' + @ExprEmail + N' AS email,
    ' + @ExprImapHost + N' AS imap_host,
    ' + @ExprImapPort + N' AS imap_port,
    ' + @ExprImapSsl + N' AS imap_ssl,
    ' + @ExprImapUser + N' AS imap_username,
    ' + @ExprImapPass + N' AS imap_password,
    ' + @ExprIsDefault + N' AS is_default,
    ' + @ExprIsActive + N' AS is_active,
    ' + @ExprDeletedAt + N' AS deleted_at
FROM ' + @SourceObj + N' s;';

EXEC sp_executesql @LoadSql;

IF NOT EXISTS (SELECT 1 FROM #SrcMail)
BEGIN
    PRINT N'[INFO] No rows in source table. nothing to migrate.';
    RETURN;
END;

IF OBJECT_ID('tempdb..#UserMap') IS NOT NULL DROP TABLE #UserMap;
CREATE TABLE #UserMap (
    map_key NVARCHAR(320) NOT NULL,
    user_pk INT NOT NULL
);

DECLARE @UserMapSql NVARCHAR(MAX);

IF OBJECT_ID(N'dbo.Tb_Users', N'U') IS NOT NULL
BEGIN
    INSERT INTO #UserMap (map_key, user_pk)
    SELECT
        LOWER(LTRIM(RTRIM(CONVERT(NVARCHAR(320), u.idx)))) AS map_key,
        u.idx AS user_pk
    FROM dbo.Tb_Users u
    WHERE u.idx IS NOT NULL;

    IF COL_LENGTH('dbo.Tb_Users', 'id') IS NOT NULL
    BEGIN
        SET @UserMapSql = N'
            INSERT INTO #UserMap (map_key, user_pk)
            SELECT
                LOWER(LTRIM(RTRIM(CONVERT(NVARCHAR(320), u.[id])))) AS map_key,
                u.idx AS user_pk
            FROM dbo.Tb_Users u
            WHERE NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(320), u.[id]))), '''') IS NOT NULL;';
        EXEC sp_executesql @UserMapSql;
    END;

    IF COL_LENGTH('dbo.Tb_Users', 'login_id') IS NOT NULL
    BEGIN
        SET @UserMapSql = N'
            INSERT INTO #UserMap (map_key, user_pk)
            SELECT
                LOWER(LTRIM(RTRIM(CONVERT(NVARCHAR(320), u.[login_id])))) AS map_key,
                u.idx AS user_pk
            FROM dbo.Tb_Users u
            WHERE NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(320), u.[login_id]))), '''') IS NOT NULL;';
        EXEC sp_executesql @UserMapSql;
    END;

    IF COL_LENGTH('dbo.Tb_Users', 'user_id') IS NOT NULL
    BEGIN
        SET @UserMapSql = N'
            INSERT INTO #UserMap (map_key, user_pk)
            SELECT
                LOWER(LTRIM(RTRIM(CONVERT(NVARCHAR(320), u.[user_id])))) AS map_key,
                u.idx AS user_pk
            FROM dbo.Tb_Users u
            WHERE NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(320), u.[user_id]))), '''') IS NOT NULL;';
        EXEC sp_executesql @UserMapSql;
    END;

    IF COL_LENGTH('dbo.Tb_Users', 'email') IS NOT NULL
    BEGIN
        SET @UserMapSql = N'
            INSERT INTO #UserMap (map_key, user_pk)
            SELECT
                LOWER(LTRIM(RTRIM(CONVERT(NVARCHAR(320), u.[email])))) AS map_key,
                u.idx AS user_pk
            FROM dbo.Tb_Users u
            WHERE NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(320), u.[email]))), '''') IS NOT NULL;';
        EXEC sp_executesql @UserMapSql;
    END;
END;

IF OBJECT_ID('tempdb..#SrcNorm') IS NOT NULL DROP TABLE #SrcNorm;
CREATE TABLE #SrcNorm (
    src_id INT NOT NULL,
    account_key NVARCHAR(120) NOT NULL,
    user_pk INT NULL,
    display_name NVARCHAR(200) NOT NULL,
    is_primary BIT NOT NULL,
    status VARCHAR(20) NOT NULL,
    host NVARCHAR(200) NOT NULL,
    port INT NOT NULL,
    ssl BIT NOT NULL,
    login_id NVARCHAR(320) NOT NULL,
    [password] NVARCHAR(MAX) NULL,
    smtp_host NVARCHAR(200) NOT NULL,
    smtp_port INT NOT NULL,
    smtp_ssl BIT NOT NULL,
    smtp_login_id NVARCHAR(320) NOT NULL,
    smtp_password NVARCHAR(MAX) NULL,
    from_email NVARCHAR(320) NOT NULL,
    from_name NVARCHAR(200) NOT NULL,
    raw_json NVARCHAR(MAX) NOT NULL
);

;WITH base AS (
    SELECT
        s.src_id,
        NULLIF(LOWER(LTRIM(RTRIM(ISNULL(s.user_id, '')))), '') AS source_user_key,
        LTRIM(RTRIM(ISNULL(s.account_name, ''))) AS account_name,
        LOWER(LTRIM(RTRIM(ISNULL(s.email, '')))) AS email,
        LTRIM(RTRIM(ISNULL(s.imap_host, ''))) AS imap_host,
        ISNULL(NULLIF(s.imap_port, 0), 993) AS imap_port,
        CASE WHEN ISNULL(s.imap_ssl, 1) = 1 THEN 1 ELSE 0 END AS imap_ssl,
        LOWER(LTRIM(RTRIM(ISNULL(s.imap_username, '')))) AS imap_username,
        CONVERT(NVARCHAR(MAX), ISNULL(s.imap_password, '')) AS imap_password,
        CASE WHEN ISNULL(s.is_default, 0) = 1 THEN 1 ELSE 0 END AS is_default,
        CASE WHEN s.deleted_at IS NOT NULL THEN 'DELETED'
             WHEN ISNULL(s.is_active, 1) = 1 THEN 'ACTIVE'
             ELSE 'INACTIVE'
        END AS [status]
    FROM #SrcMail s
), user_map AS (
    SELECT
        map_key,
        MIN(user_pk) AS user_pk
    FROM #UserMap
    WHERE map_key <> ''
    GROUP BY map_key
),
norm AS (
    SELECT
        b.src_id,
        LEFT(
            LOWER(
                COALESCE(
                    NULLIF(b.imap_username, ''),
                    NULLIF(b.email, ''),
                    'mail_' + CONVERT(NVARCHAR(20), b.src_id)
                )
            ),
            120
        ) AS account_key,
        um.user_pk,
        LEFT(
            COALESCE(
                NULLIF(b.account_name, ''),
                NULLIF(b.email, ''),
                'mail_' + CONVERT(NVARCHAR(20), b.src_id)
            ),
            200
        ) AS display_name,
        CAST(CASE WHEN b.is_default = 1 THEN 1 ELSE 0 END AS BIT) AS is_primary,
        b.[status],
        COALESCE(NULLIF(b.imap_host, ''), 'shvision.hanbiro.net') AS host,
        CASE WHEN b.imap_port > 0 THEN b.imap_port ELSE 993 END AS port,
        CAST(CASE WHEN b.imap_ssl = 1 THEN 1 ELSE 0 END AS BIT) AS ssl,
        COALESCE(NULLIF(b.imap_username, ''), NULLIF(b.email, ''), 'mail_' + CONVERT(NVARCHAR(20), b.src_id)) AS login_id,
        NULLIF(b.imap_password, '') AS [password],
        COALESCE(NULLIF(b.imap_host, ''), 'shvision.hanbiro.net') AS smtp_host,
        CASE WHEN b.imap_ssl = 1 THEN 465 ELSE 587 END AS smtp_port,
        CAST(CASE WHEN b.imap_ssl = 1 THEN 1 ELSE 0 END AS BIT) AS smtp_ssl,
        COALESCE(NULLIF(b.imap_username, ''), NULLIF(b.email, ''), 'mail_' + CONVERT(NVARCHAR(20), b.src_id)) AS smtp_login_id,
        NULLIF(b.imap_password, '') AS smtp_password,
        COALESCE(NULLIF(b.email, ''), NULLIF(b.imap_username, ''), '') AS from_email,
        LEFT(COALESCE(NULLIF(b.account_name, ''), NULLIF(b.email, ''), 'mail'), 200) AS from_name
    FROM base b
    LEFT JOIN user_map um
      ON um.map_key = b.source_user_key
)
INSERT INTO #SrcNorm
(
    src_id, account_key, user_pk, display_name, is_primary, status,
    host, port, ssl, login_id, [password],
    smtp_host, smtp_port, smtp_ssl, smtp_login_id, smtp_password,
    from_email, from_name, raw_json
)
SELECT
    n.src_id, n.account_key, n.user_pk, n.display_name, n.is_primary, n.status,
    n.host, n.port, n.ssl, n.login_id, n.[password],
    n.smtp_host, n.smtp_port, n.smtp_ssl, n.smtp_login_id, n.smtp_password,
    n.from_email, n.from_name,
    (
        SELECT
            n.host AS [host],
            n.port AS [port],
            n.ssl AS [ssl],
            n.login_id AS [login_id],
            n.[password] AS [password],
            n.smtp_host AS [smtp_host],
            n.smtp_port AS [smtp_port],
            n.smtp_ssl AS [smtp_ssl],
            n.smtp_login_id AS [smtp_login_id],
            n.smtp_password AS [smtp_password],
            n.from_email AS [from_email],
            n.from_name AS [from_name],
            'v1_migration' AS [provider_hint]
        FOR JSON PATH, WITHOUT_ARRAY_WRAPPER
    ) AS raw_json
FROM norm n;

BEGIN TRANSACTION;

MERGE dbo.Tb_IntProviderAccount AS target
USING (
    SELECT
        account_key,
        MAX(user_pk) AS user_pk,
        MAX(display_name) AS display_name,
        CAST(MAX(CASE WHEN is_primary = 1 THEN 1 ELSE 0 END) AS BIT) AS is_primary,
        MAX(status) AS status,
        MAX(raw_json) AS raw_json
    FROM #SrcNorm
    GROUP BY account_key
) AS src
ON target.service_code = @ServiceCode
   AND target.tenant_id = @TenantId
   AND target.provider = 'mail'
   AND target.account_key = src.account_key
WHEN MATCHED THEN
    UPDATE SET
        target.user_pk = src.user_pk,
        target.display_name = src.display_name,
        target.is_primary = src.is_primary,
        target.status = src.status,
        target.raw_json = src.raw_json,
        target.updated_at = GETDATE()
WHEN NOT MATCHED THEN
    INSERT
    (
        service_code, tenant_id, provider,
        account_key, user_pk, display_name, is_primary, status, raw_json,
        created_at, updated_at
    )
    VALUES
    (
        @ServiceCode, @TenantId, 'mail',
        src.account_key, src.user_pk, src.display_name, src.is_primary, src.status, src.raw_json,
        GETDATE(), GETDATE()
    );

;WITH ranked AS (
    SELECT
        idx,
        ROW_NUMBER() OVER (
            PARTITION BY service_code, tenant_id, provider
            ORDER BY CASE WHEN is_primary = 1 THEN 0 ELSE 1 END, idx ASC
        ) AS rn
    FROM dbo.Tb_IntProviderAccount
    WHERE service_code = @ServiceCode
      AND tenant_id = @TenantId
      AND provider = 'mail'
      AND status <> 'DELETED'
)
UPDATE a
SET
    is_primary = CASE WHEN r.rn = 1 THEN 1 ELSE 0 END,
    updated_at = GETDATE()
FROM dbo.Tb_IntProviderAccount a
INNER JOIN ranked r
    ON r.idx = a.idx;

IF OBJECT_ID('tempdb..#CredUpsert') IS NOT NULL DROP TABLE #CredUpsert;
CREATE TABLE #CredUpsert (
    provider_account_idx INT NOT NULL,
    secret_type VARCHAR(40) NOT NULL,
    secret_value_enc NVARCHAR(MAX) NOT NULL
);

INSERT INTO #CredUpsert (provider_account_idx, secret_type, secret_value_enc)
SELECT
    p.idx AS provider_account_idx,
    v.secret_type,
    v.secret_value
FROM #SrcNorm s
INNER JOIN dbo.Tb_IntProviderAccount p
    ON p.service_code = @ServiceCode
   AND p.tenant_id = @TenantId
   AND p.provider = 'mail'
   AND p.account_key = s.account_key
CROSS APPLY (VALUES
    ('host', s.host),
    ('port', CONVERT(NVARCHAR(40), s.port)),
    ('ssl', CONVERT(NVARCHAR(40), CONVERT(INT, s.ssl))),
    ('login_id', s.login_id),
    ('password', ISNULL(s.[password], '')),
    ('smtp_host', s.smtp_host),
    ('smtp_port', CONVERT(NVARCHAR(40), s.smtp_port)),
    ('smtp_ssl', CONVERT(NVARCHAR(40), CONVERT(INT, s.smtp_ssl))),
    ('smtp_login_id', s.smtp_login_id),
    ('smtp_password', ISNULL(s.smtp_password, '')),
    ('from_email', s.from_email),
    ('from_name', s.from_name)
) v(secret_type, secret_value)
WHERE LTRIM(RTRIM(ISNULL(v.secret_value, ''))) <> '';

MERGE dbo.Tb_IntCredential AS target
USING (
    SELECT provider_account_idx, secret_type, MAX(secret_value_enc) AS secret_value_enc
    FROM #CredUpsert
    GROUP BY provider_account_idx, secret_type
) AS src
ON target.provider_account_idx = src.provider_account_idx
   AND target.secret_type = src.secret_type
WHEN MATCHED THEN
    UPDATE SET
        target.secret_value_enc = src.secret_value_enc, -- 기존 decrypt 호환을 위해 평문 허용
        target.status = 'ACTIVE',
        target.updated_at = GETDATE()
WHEN NOT MATCHED THEN
    INSERT
    (
        provider_account_idx, secret_type, secret_value_enc,
        status, created_at, updated_at
    )
    VALUES
    (
        src.provider_account_idx, src.secret_type, src.secret_value_enc,
        'ACTIVE', GETDATE(), GETDATE()
    );

COMMIT TRANSACTION;

PRINT N'[OK] V1 Tb_Mail_Accounts -> V2 Tb_IntProviderAccount/Tb_IntCredential migration completed';

SELECT
    COUNT(1) AS migrated_accounts
FROM dbo.Tb_IntProviderAccount
WHERE service_code = @ServiceCode
  AND tenant_id = @TenantId
  AND provider = 'mail';

SELECT
    SUM(CASE WHEN user_pk IS NOT NULL THEN 1 ELSE 0 END) AS mapped_user_accounts,
    SUM(CASE WHEN user_pk IS NULL THEN 1 ELSE 0 END) AS unmapped_user_accounts
FROM dbo.Tb_IntProviderAccount
WHERE service_code = @ServiceCode
  AND tenant_id = @TenantId
  AND provider = 'mail';

SELECT
    secret_type,
    COUNT(1) AS secret_count
FROM dbo.Tb_IntCredential c
INNER JOIN dbo.Tb_IntProviderAccount a
    ON a.idx = c.provider_account_idx
WHERE a.service_code = @ServiceCode
  AND a.tenant_id = @TenantId
  AND a.provider = 'mail'
GROUP BY secret_type
ORDER BY secret_type;

GO
