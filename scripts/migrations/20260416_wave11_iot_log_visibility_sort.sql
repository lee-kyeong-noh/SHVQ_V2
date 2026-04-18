-- SHVQ V2 Wave11: IoT log/doorlock/sort/visibility backend schema patch
-- Target DB: CSM_C004732_V2

USE CSM_C004732_V2;
GO

/* ── 1) Tb_IntDevice 확장 컬럼 ───────────────────────────── */
IF OBJECT_ID('dbo.Tb_IntDevice', 'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.Tb_IntDevice', 'sort_order') IS NULL
        ALTER TABLE dbo.Tb_IntDevice
            ADD sort_order INT NOT NULL CONSTRAINT DF_Tb_IntDevice_sort_order_wave11 DEFAULT (9999) WITH VALUES;

    IF COL_LENGTH('dbo.Tb_IntDevice', 'is_hidden') IS NULL
        ALTER TABLE dbo.Tb_IntDevice
            ADD is_hidden BIT NOT NULL CONSTRAINT DF_Tb_IntDevice_is_hidden_wave11 DEFAULT (0) WITH VALUES;
END;
GO

IF OBJECT_ID('dbo.Tb_IntDevice', 'U') IS NOT NULL
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID('dbo.Tb_IntDevice')
          AND name = 'IX_Tb_IntDevice_ScopeSort'
    )
    BEGIN
        CREATE INDEX IX_Tb_IntDevice_ScopeSort
            ON dbo.Tb_IntDevice(service_code, tenant_id, is_deleted, is_hidden, sort_order, idx);
    END
END;
GO

/* ── 2) 명령 실행 로그 테이블 ─────────────────────────────── */
IF OBJECT_ID('dbo.Tb_IntCommandLog', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_IntCommandLog (
        idx                 BIGINT IDENTITY(1,1) PRIMARY KEY,
        service_code        VARCHAR(30) NOT NULL DEFAULT 'shvq',
        tenant_id           INT NOT NULL DEFAULT 0,
        provider            VARCHAR(30) NOT NULL DEFAULT '',
        provider_account_idx INT NULL,
        device_idx          BIGINT NULL,
        device_id           NVARCHAR(120) NOT NULL DEFAULT '',
        device_label        NVARCHAR(200) NOT NULL DEFAULT '',
        capability          NVARCHAR(120) NOT NULL DEFAULT '',
        command             NVARCHAR(80) NOT NULL DEFAULT '',
        result              VARCHAR(20) NOT NULL DEFAULT 'success',
        http_status         INT NOT NULL DEFAULT 0,
        error_message       NVARCHAR(1000) NOT NULL DEFAULT '',
        raw_json            NVARCHAR(MAX) NOT NULL DEFAULT '',
        is_deleted          BIT NOT NULL DEFAULT 0,
        created_at          DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at          DATETIME NOT NULL DEFAULT GETDATE()
    );
END;
GO

IF OBJECT_ID('dbo.Tb_IntCommandLog', 'U') IS NOT NULL
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID('dbo.Tb_IntCommandLog')
          AND name = 'IX_Tb_IntCommandLog_ScopeCreated'
    )
    BEGIN
        CREATE INDEX IX_Tb_IntCommandLog_ScopeCreated
            ON dbo.Tb_IntCommandLog(service_code, tenant_id, is_deleted, created_at DESC, idx DESC);
    END

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID('dbo.Tb_IntCommandLog')
          AND name = 'IX_Tb_IntCommandLog_Device'
    )
    BEGIN
        CREATE INDEX IX_Tb_IntCommandLog_Device
            ON dbo.Tb_IntCommandLog(service_code, tenant_id, device_id, created_at DESC, idx DESC);
    END
END;
GO

/* ── 3) 이벤트 로그 테이블 ───────────────────────────────── */
IF OBJECT_ID('dbo.Tb_IntEventLog', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_IntEventLog (
        idx                 BIGINT IDENTITY(1,1) PRIMARY KEY,
        service_code        VARCHAR(30) NOT NULL DEFAULT 'shvq',
        tenant_id           INT NOT NULL DEFAULT 0,
        provider_account_idx INT NULL,
        provider            VARCHAR(30) NOT NULL DEFAULT '',
        lifecycle           VARCHAR(30) NOT NULL DEFAULT 'EVENT',
        log_type            VARCHAR(20) NOT NULL DEFAULT 'event',
        event_type          VARCHAR(60) NOT NULL DEFAULT '',
        event_id            NVARCHAR(120) NOT NULL DEFAULT '',
        device_external_id  NVARCHAR(120) NOT NULL DEFAULT '',
        capability          NVARCHAR(120) NOT NULL DEFAULT '',
        attribute           NVARCHAR(120) NOT NULL DEFAULT '',
        event_value         NVARCHAR(1000) NOT NULL DEFAULT '',
        message             NVARCHAR(1000) NOT NULL DEFAULT '',
        event_timestamp     DATETIME NULL,
        raw_json            NVARCHAR(MAX) NOT NULL DEFAULT '',
        is_deleted          BIT NOT NULL DEFAULT 0,
        created_at          DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at          DATETIME NOT NULL DEFAULT GETDATE()
    );
END;
GO

IF OBJECT_ID('dbo.Tb_IntEventLog', 'U') IS NOT NULL
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID('dbo.Tb_IntEventLog')
          AND name = 'IX_Tb_IntEventLog_ScopeTime'
    )
    BEGIN
        CREATE INDEX IX_Tb_IntEventLog_ScopeTime
            ON dbo.Tb_IntEventLog(service_code, tenant_id, provider, is_deleted, created_at DESC, idx DESC);
    END

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID('dbo.Tb_IntEventLog')
          AND name = 'IX_Tb_IntEventLog_Device'
    )
    BEGIN
        CREATE INDEX IX_Tb_IntEventLog_Device
            ON dbo.Tb_IntEventLog(service_code, tenant_id, device_external_id, created_at DESC, idx DESC);
    END
END;
GO

/* ── 4) 도어락 credential 매핑 테이블 ───────────────────── */
IF OBJECT_ID('dbo.Tb_IntLockCredentialMap', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_IntLockCredentialMap (
        idx                 BIGINT IDENTITY(1,1) PRIMARY KEY,
        service_code        VARCHAR(30) NOT NULL DEFAULT 'shvq',
        tenant_id           INT NOT NULL DEFAULT 0,
        provider_account_idx INT NULL,
        device_idx          BIGINT NULL,
        device_external_id  NVARCHAR(120) NOT NULL DEFAULT '',
        credential_type     VARCHAR(50) NOT NULL DEFAULT '',
        credential_key      NVARCHAR(200) NOT NULL DEFAULT '',
        credential_label    NVARCHAR(200) NOT NULL DEFAULT '',
        employee_id         NVARCHAR(120) NOT NULL DEFAULT '',
        employee_name       NVARCHAR(200) NOT NULL DEFAULT '',
        note                NVARCHAR(500) NOT NULL DEFAULT '',
        is_active           BIT NOT NULL DEFAULT 1,
        is_deleted          BIT NOT NULL DEFAULT 0,
        created_at          DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at          DATETIME NOT NULL DEFAULT GETDATE()
    );
END;
GO

IF OBJECT_ID('dbo.Tb_IntLockCredentialMap', 'U') IS NOT NULL
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID('dbo.Tb_IntLockCredentialMap')
          AND name = 'UX_Tb_IntLockCredentialMap_Key'
    )
    BEGIN
        CREATE UNIQUE INDEX UX_Tb_IntLockCredentialMap_Key
            ON dbo.Tb_IntLockCredentialMap(service_code, tenant_id, provider_account_idx, device_external_id, credential_type, credential_key);
    END
END;
GO

/* ── 5) 도어락 임시 비밀번호 캐시 테이블 ─────────────────── */
IF OBJECT_ID('dbo.Tb_IntDoorlockTempPassword', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_IntDoorlockTempPassword (
        idx                 BIGINT IDENTITY(1,1) PRIMARY KEY,
        service_code        VARCHAR(30) NOT NULL DEFAULT 'shvq',
        tenant_id           INT NOT NULL DEFAULT 0,
        provider_account_idx INT NULL,
        device_external_id  NVARCHAR(120) NOT NULL DEFAULT '',
        password_id         NVARCHAR(120) NOT NULL DEFAULT '',
        [name]              NVARCHAR(200) NOT NULL DEFAULT '',
        [type]              NVARCHAR(60) NOT NULL DEFAULT 'temp',
        effective_time      DATETIME NULL,
        invalid_time        DATETIME NULL,
        status              VARCHAR(20) NOT NULL DEFAULT 'active',
        raw_json            NVARCHAR(MAX) NOT NULL DEFAULT '',
        is_deleted          BIT NOT NULL DEFAULT 0,
        created_at          DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at          DATETIME NOT NULL DEFAULT GETDATE()
    );
END;
GO

IF OBJECT_ID('dbo.Tb_IntDoorlockTempPassword', 'U') IS NOT NULL
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID('dbo.Tb_IntDoorlockTempPassword')
          AND name = 'IX_Tb_IntDoorlockTempPassword_Device'
    )
    BEGIN
        CREATE INDEX IX_Tb_IntDoorlockTempPassword_Device
            ON dbo.Tb_IntDoorlockTempPassword(service_code, tenant_id, device_external_id, is_deleted, effective_time DESC, idx DESC);
    END
END;
GO

/* ── 6) V1 이벤트 로그 → V2 이관 (가능한 경우) ───────────── */
IF OBJECT_ID('dbo.Tb_IntEventLog', 'U') IS NOT NULL
   AND DB_ID('teniq_db') IS NOT NULL
   AND OBJECT_ID('teniq_db.dbo.Tb_IotEventLog', 'U') IS NOT NULL
BEGIN
    BEGIN TRY
        INSERT INTO dbo.Tb_IntEventLog
            (service_code, tenant_id, provider_account_idx, provider, lifecycle, log_type,
             event_type, event_id, device_external_id, capability, attribute, event_value,
             message, event_timestamp, raw_json, is_deleted, created_at, updated_at)
        SELECT
            ISNULL(s.service_code, 'shvq'),
            ISNULL(s.tenant_id, 0),
            s.provider_account_idx,
            ISNULL(s.provider, 'smartthings'),
            ISNULL(s.lifecycle, 'EVENT'),
            CASE WHEN UPPER(ISNULL(s.lifecycle, 'EVENT')) = 'EVENT' THEN 'event' ELSE LOWER(ISNULL(s.lifecycle, 'event')) END,
            ISNULL(s.event_type, ''),
            ISNULL(s.event_id, ''),
            ISNULL(s.device_external_id, ''),
            ISNULL(s.capability, ''),
            ISNULL(s.attribute, ''),
            ISNULL(s.event_value, ''),
            ISNULL(s.event_value, ''),
            s.event_timestamp,
            ISNULL(s.raw_json, ''),
            CAST(0 AS BIT),
            ISNULL(s.created_at, GETDATE()),
            ISNULL(s.created_at, GETDATE())
        FROM teniq_db.dbo.Tb_IotEventLog s
        WHERE NOT EXISTS (
            SELECT 1
            FROM dbo.Tb_IntEventLog t
            WHERE t.service_code = ISNULL(s.service_code, 'shvq')
              AND t.tenant_id = ISNULL(s.tenant_id, 0)
              AND ISNULL(t.event_id, '') = ISNULL(s.event_id, '')
              AND ISNULL(t.device_external_id, '') = ISNULL(s.device_external_id, '')
              AND ISNULL(t.capability, '') = ISNULL(s.capability, '')
              AND ISNULL(t.event_value, '') = ISNULL(s.event_value, '')
              AND ISNULL(t.created_at, '1900-01-01') = ISNULL(s.created_at, '1900-01-01')
        );
        PRINT 'V1 event log migrated: ' + CAST(@@ROWCOUNT AS VARCHAR(20));
    END TRY
    BEGIN CATCH
        PRINT 'V1 event log migration skipped: ' + ERROR_MESSAGE();
    END CATCH
END;
GO

/* ── 7) V1 도어락 credential map → V2 이관 ──────────────── */
IF OBJECT_ID('dbo.Tb_IntLockCredentialMap', 'U') IS NOT NULL
   AND DB_ID('teniq_db') IS NOT NULL
   AND OBJECT_ID('teniq_db.dbo.Tb_IotLockCredentialMap', 'U') IS NOT NULL
BEGIN
    BEGIN TRY
        INSERT INTO dbo.Tb_IntLockCredentialMap
            (service_code, tenant_id, provider_account_idx, device_idx, device_external_id,
             credential_type, credential_key, credential_label, employee_id, employee_name,
             note, is_active, is_deleted, created_at, updated_at)
        SELECT
            ISNULL(s.service_code, 'shvq'),
            ISNULL(s.tenant_id, 0),
            s.provider_account_idx,
            s.device_idx,
            ISNULL(s.device_external_id, ''),
            ISNULL(s.credential_type, ''),
            ISNULL(s.credential_key, ''),
            ISNULL(s.credential_label, ''),
            ISNULL(s.employee_id, ''),
            ISNULL(s.employee_name, ''),
            ISNULL(s.note, ''),
            ISNULL(s.is_active, 1),
            ISNULL(s.is_deleted, 0),
            ISNULL(s.created_at, GETDATE()),
            ISNULL(s.updated_at, GETDATE())
        FROM teniq_db.dbo.Tb_IotLockCredentialMap s
        WHERE ISNULL(s.is_deleted, 0) = 0
          AND NOT EXISTS (
              SELECT 1
              FROM dbo.Tb_IntLockCredentialMap t
              WHERE t.service_code = ISNULL(s.service_code, 'shvq')
                AND t.tenant_id = ISNULL(s.tenant_id, 0)
                AND ISNULL(t.provider_account_idx, 0) = ISNULL(s.provider_account_idx, 0)
                AND ISNULL(t.device_external_id, '') = ISNULL(s.device_external_id, '')
                AND ISNULL(t.credential_type, '') = ISNULL(s.credential_type, '')
                AND ISNULL(t.credential_key, '') = ISNULL(s.credential_key, '')
          );
        PRINT 'V1 lock credential map migrated: ' + CAST(@@ROWCOUNT AS VARCHAR(20));
    END TRY
    BEGIN CATCH
        PRINT 'V1 lock credential map migration skipped: ' + ERROR_MESSAGE();
    END CATCH
END;
GO

/* ── 8) 검증 쿼리 ───────────────────────────────────────── */
SELECT 'Tb_IntDevice' AS [table_name], COUNT(*) AS [cnt] FROM dbo.Tb_IntDevice
UNION ALL
SELECT 'Tb_IntCommandLog', COUNT(*) FROM dbo.Tb_IntCommandLog
UNION ALL
SELECT 'Tb_IntEventLog', COUNT(*) FROM dbo.Tb_IntEventLog
UNION ALL
SELECT 'Tb_IntLockCredentialMap', COUNT(*) FROM dbo.Tb_IntLockCredentialMap
UNION ALL
SELECT 'Tb_IntDoorlockTempPassword', COUNT(*) FROM dbo.Tb_IntDoorlockTempPassword;
GO
