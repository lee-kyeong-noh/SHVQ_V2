-- ═══════════════════════════════════════════════════════════════
-- SHVQ V2 Wave9: V1 IoT 데이터 → V2 Integration 테이블 마이그레이션
-- Source DB: teniq_db (V1 IoT hub DB, 67번 서버)
-- Target DB: CSM_C004732_V2 (V2 개발 DB)
--
-- 실행 전 반드시 확인:
--   1) teniq_db가 같은 서버에 존재하는지
--   2) CSM_C004732_V2에 Tb_Int* 테이블이 존재하는지
--   3) BACKUP 후 실행
-- ═══════════════════════════════════════════════════════════════

USE CSM_C004732_V2;
GO

-- ── 1) Tb_IntProviderAccount가 없으면 생성 ──
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'Tb_IntProviderAccount')
BEGIN
    CREATE TABLE dbo.Tb_IntProviderAccount (
        idx             INT IDENTITY(1,1) PRIMARY KEY,
        service_code    VARCHAR(30) NOT NULL DEFAULT 'shvq',
        tenant_id       INT NOT NULL DEFAULT 0,
        provider        VARCHAR(30) NOT NULL DEFAULT 'smartthings',
        provider_user_key NVARCHAR(150) NOT NULL DEFAULT '',
        account_email   NVARCHAR(200) NOT NULL DEFAULT '',
        account_label   NVARCHAR(200) NOT NULL DEFAULT '',
        owner_user_id   NVARCHAR(120) NOT NULL DEFAULT '',
        is_primary      BIT NOT NULL DEFAULT 1,
        status          VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        is_deleted      BIT NOT NULL DEFAULT 0,
        raw_json        NVARCHAR(MAX) NOT NULL DEFAULT '',
        created_at      DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at      DATETIME NOT NULL DEFAULT GETDATE()
    );
END;
GO

-- ── 2) Tb_IntDevice가 없으면 생성 ──
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'Tb_IntDevice')
BEGIN
    CREATE TABLE dbo.Tb_IntDevice (
        idx                 INT IDENTITY(1,1) PRIMARY KEY,
        service_code        VARCHAR(30) NOT NULL DEFAULT 'shvq',
        tenant_id           INT NOT NULL DEFAULT 0,
        provider_account_idx INT NULL,
        provider            VARCHAR(30) NOT NULL DEFAULT 'smartthings',
        device_id           NVARCHAR(120) NOT NULL DEFAULT '',
        device_name         NVARCHAR(200) NOT NULL DEFAULT '',
        device_label        NVARCHAR(200) NOT NULL DEFAULT '',
        device_type         VARCHAR(50) NOT NULL DEFAULT 'switch',
        location_id         NVARCHAR(120) NOT NULL DEFAULT '',
        location_name       NVARCHAR(200) NOT NULL DEFAULT '',
        room_id             NVARCHAR(120) NOT NULL DEFAULT '',
        room_name           NVARCHAR(200) NOT NULL DEFAULT '',
        manufacturer        NVARCHAR(100) NOT NULL DEFAULT '',
        model               NVARCHAR(100) NOT NULL DEFAULT '',
        firmware_version    NVARCHAR(100) NOT NULL DEFAULT '',
        is_active           BIT NOT NULL DEFAULT 1,
        is_deleted          BIT NOT NULL DEFAULT 0,
        last_event_at       DATETIME NULL,
        raw_json            NVARCHAR(MAX) NOT NULL DEFAULT '',
        created_at          DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at          DATETIME NOT NULL DEFAULT GETDATE()
    );
END;
GO

-- ── 3) Tb_IntSyncCheckpoint가 없으면 생성 ──
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'Tb_IntSyncCheckpoint')
BEGIN
    CREATE TABLE dbo.Tb_IntSyncCheckpoint (
        idx                 INT IDENTITY(1,1) PRIMARY KEY,
        provider_account_idx INT NOT NULL DEFAULT 0,
        sync_type           VARCHAR(30) NOT NULL DEFAULT 'device_sync',
        status              VARCHAR(20) NOT NULL DEFAULT 'OK',
        last_success_at     DATETIME NULL,
        total_count         INT NOT NULL DEFAULT 0,
        message             NVARCHAR(500) NOT NULL DEFAULT '',
        created_at          DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at          DATETIME NOT NULL DEFAULT GETDATE()
    );
END;
GO

-- ── 4) Tb_IntErrorQueue가 없으면 생성 ──
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'Tb_IntErrorQueue')
BEGIN
    CREATE TABLE dbo.Tb_IntErrorQueue (
        idx                 INT IDENTITY(1,1) PRIMARY KEY,
        service_code        VARCHAR(30) NOT NULL DEFAULT 'shvq',
        tenant_id           INT NOT NULL DEFAULT 0,
        provider            VARCHAR(30) NOT NULL DEFAULT '',
        job_type            VARCHAR(50) NOT NULL DEFAULT '',
        status              VARCHAR(20) NOT NULL DEFAULT 'PENDING',
        error_message       NVARCHAR(1000) NOT NULL DEFAULT '',
        payload_json        NVARCHAR(MAX) NOT NULL DEFAULT '',
        retry_count         INT NOT NULL DEFAULT 0,
        created_at          DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at          DATETIME NOT NULL DEFAULT GETDATE()
    );
END;
GO

-- ═══════════════════════════════════════════════════════════════
-- 데이터 마이그레이션 (V1 teniq_db → V2 CSM_C004732_V2)
-- ═══════════════════════════════════════════════════════════════

-- ── 5) Provider Account 복사 ──
-- V1 Tb_IotProviderAccount → V2 Tb_IntProviderAccount
-- 중복 방지: service_code + tenant_id + provider + provider_user_key 기준
BEGIN TRY
    INSERT INTO dbo.Tb_IntProviderAccount
        (service_code, tenant_id, provider, provider_user_key, account_email, account_label,
         owner_user_id, is_primary, status, raw_json, created_at, updated_at)
    SELECT
        s.service_code,
        s.tenant_id,
        s.provider,
        s.provider_user_key,
        s.account_email,
        s.account_label,
        s.owner_user_id,
        s.is_primary,
        s.status,
        s.raw_json,
        s.created_at,
        s.updated_at
    FROM teniq_db.dbo.Tb_IotProviderAccount s
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.Tb_IntProviderAccount t
        WHERE t.service_code = s.service_code
          AND t.tenant_id = s.tenant_id
          AND t.provider = s.provider
          AND t.provider_user_key = s.provider_user_key
    );
    PRINT 'Provider accounts migrated: ' + CAST(@@ROWCOUNT AS VARCHAR);
END TRY
BEGIN CATCH
    PRINT 'Provider account migration skipped: ' + ERROR_MESSAGE();
END CATCH;
GO

-- ── 6) Device 복사 ──
-- V1 Tb_IotDevice → V2 Tb_IntDevice
-- 중복 방지: service_code + tenant_id + device_id(external_id) 기준
BEGIN TRY
    INSERT INTO dbo.Tb_IntDevice
        (service_code, tenant_id, provider, device_id, device_name, device_label,
         device_type, location_id, location_name, room_id, room_name,
         is_active, is_deleted, raw_json, created_at, updated_at)
    SELECT
        s.service_code,
        s.tenant_id,
        s.provider,
        s.external_id,         -- V1 external_id → V2 device_id
        s.device_name,
        s.device_label,
        s.device_type,
        s.location_id,
        s.location_name,
        s.room_id,
        s.room_name,
        s.is_active,
        s.is_deleted,
        s.raw_json,
        s.created_at,
        s.updated_at
    FROM teniq_db.dbo.Tb_IotDevice s
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.Tb_IntDevice t
        WHERE t.service_code = s.service_code
          AND t.tenant_id = s.tenant_id
          AND t.device_id = s.external_id
    );
    PRINT 'Devices migrated: ' + CAST(@@ROWCOUNT AS VARCHAR);
END TRY
BEGIN CATCH
    PRINT 'Device migration skipped: ' + ERROR_MESSAGE();
END CATCH;
GO

-- ── 7) Sync Log → SyncCheckpoint 변환 복사 ──
-- V1 Tb_IotSyncLog → V2 Tb_IntSyncCheckpoint
BEGIN TRY
    INSERT INTO dbo.Tb_IntSyncCheckpoint
        (provider_account_idx, sync_type, status, last_success_at, total_count, message, created_at, updated_at)
    SELECT
        ISNULL(s.provider_account_idx, 0),
        s.sync_type,
        CASE WHEN s.fail_count = 0 THEN 'OK' ELSE 'ERROR' END,
        s.created_at,
        s.total_count,
        LEFT(s.message, 500),
        s.created_at,
        s.created_at
    FROM teniq_db.dbo.Tb_IotSyncLog s
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.Tb_IntSyncCheckpoint t
        WHERE t.provider_account_idx = ISNULL(s.provider_account_idx, 0)
          AND t.sync_type = s.sync_type
          AND t.created_at = s.created_at
    );
    PRINT 'Sync logs migrated: ' + CAST(@@ROWCOUNT AS VARCHAR);
END TRY
BEGIN CATCH
    PRINT 'Sync log migration skipped: ' + ERROR_MESSAGE();
END CATCH;
GO

-- ═══════════════════════════════════════════════════════════════
-- 인덱스 생성
-- ═══════════════════════════════════════════════════════════════
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_IntProviderAccount_Scope' AND object_id = OBJECT_ID('Tb_IntProviderAccount'))
    CREATE INDEX IX_IntProviderAccount_Scope ON dbo.Tb_IntProviderAccount(service_code, tenant_id, provider, status);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_IntDevice_Scope' AND object_id = OBJECT_ID('Tb_IntDevice'))
    CREATE INDEX IX_IntDevice_Scope ON dbo.Tb_IntDevice(service_code, tenant_id, provider, is_deleted);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_IntDevice_DeviceId' AND object_id = OBJECT_ID('Tb_IntDevice'))
    CREATE INDEX IX_IntDevice_DeviceId ON dbo.Tb_IntDevice(device_id);
GO

-- ═══════════════════════════════════════════════════════════════
-- 검증 쿼리
-- ═══════════════════════════════════════════════════════════════
SELECT 'Tb_IntProviderAccount' AS [Table], COUNT(*) AS [Count] FROM dbo.Tb_IntProviderAccount
UNION ALL
SELECT 'Tb_IntDevice', COUNT(*) FROM dbo.Tb_IntDevice
UNION ALL
SELECT 'Tb_IntSyncCheckpoint', COUNT(*) FROM dbo.Tb_IntSyncCheckpoint
UNION ALL
SELECT 'Tb_IntErrorQueue', COUNT(*) FROM dbo.Tb_IntErrorQueue;
GO
