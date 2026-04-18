/*
  SHVQ_V2 - Migration run (V1 -> V2)
  목적:
  1) STG_Tb_Users -> Tb_Users 업서트
  2) Tb_SvcService / Tb_SvcTenant / Tb_SvcTenantUser 보장 및 기본 매핑 생성
  3) resolveTenantContext()가 읽는 최소 데이터 확보
*/

SET NOCOUNT ON;
USE [CSM_C004732_V2];
GO

IF OBJECT_ID(N'dbo.STG_Tb_Users', N'U') IS NULL
BEGIN
    RAISERROR(N'[STOP] STG_Tb_Users not found. Run 101_migration_staging.sql first.', 16, 1);
    RETURN;
END;
GO

IF OBJECT_ID(N'dbo.Tb_Users', N'U') IS NULL
BEGIN
    EXEC(N'SELECT TOP 0 * INTO dbo.Tb_Users FROM [CSM_C004732].dbo.Tb_Users;');
END;
GO

IF COL_LENGTH('dbo.Tb_Users', 'password_migrated_at') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Users
        ADD password_migrated_at DATETIME NULL;
END;
GO

DECLARE @UpdateSet NVARCHAR(MAX);
DECLARE @UpdateSql NVARCHAR(MAX);
DECLARE @InsertCols NVARCHAR(MAX);
DECLARE @InsertSelectCols NVARCHAR(MAX);
DECLARE @InsertSql NVARCHAR(MAX);
DECLARE @HasIdentityIdx BIT = 0;

SELECT @HasIdentityIdx = CASE
    WHEN COLUMNPROPERTY(OBJECT_ID(N'dbo.Tb_Users'), 'idx', 'IsIdentity') = 1 THEN 1
    ELSE 0
END;

SELECT @UpdateSet = STRING_AGG(
    't.' + QUOTENAME(t.name) + ' = s.' + QUOTENAME(t.name),
    ', '
)
FROM sys.columns t
INNER JOIN sys.columns s
    ON s.object_id = OBJECT_ID(N'dbo.STG_Tb_Users')
   AND s.name = t.name
WHERE t.object_id = OBJECT_ID(N'dbo.Tb_Users')
  AND t.name <> 'idx';

IF @UpdateSet IS NOT NULL AND LTRIM(RTRIM(@UpdateSet)) <> ''
BEGIN
    SET @UpdateSql = N'
        UPDATE t
        SET ' + @UpdateSet + N'
        FROM dbo.Tb_Users t
        INNER JOIN dbo.STG_Tb_Users s
            ON t.idx = s.idx;';
    EXEC sp_executesql @UpdateSql;
END;

SELECT @InsertCols = STRING_AGG(QUOTENAME(t.name), ',')
FROM sys.columns t
INNER JOIN sys.columns s
    ON s.object_id = OBJECT_ID(N'dbo.STG_Tb_Users')
   AND s.name = t.name
WHERE t.object_id = OBJECT_ID(N'dbo.Tb_Users');

SELECT @InsertSelectCols = STRING_AGG('s.' + QUOTENAME(t.name), ',')
FROM sys.columns t
INNER JOIN sys.columns s
    ON s.object_id = OBJECT_ID(N'dbo.STG_Tb_Users')
   AND s.name = t.name
WHERE t.object_id = OBJECT_ID(N'dbo.Tb_Users');

IF @InsertCols IS NULL OR LTRIM(RTRIM(@InsertCols)) = ''
BEGIN
    RAISERROR(N'[STOP] Tb_Users has no common columns with STG_Tb_Users.', 16, 1);
    RETURN;
END;

SET @InsertSql = N'
    INSERT INTO dbo.Tb_Users (' + @InsertCols + N')
    SELECT ' + @InsertSelectCols + N'
    FROM dbo.STG_Tb_Users s
    WHERE NOT EXISTS (
        SELECT 1
        FROM dbo.Tb_Users t
        WHERE t.idx = s.idx
    );';

IF @HasIdentityIdx = 1
BEGIN
    SET IDENTITY_INSERT dbo.Tb_Users ON;
    EXEC sp_executesql @InsertSql;
    SET IDENTITY_INSERT dbo.Tb_Users OFF;
END
ELSE
BEGIN
    EXEC sp_executesql @InsertSql;
END;
GO

IF OBJECT_ID(N'dbo.Tb_SvcService', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_SvcService (
        idx INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL,
        service_name NVARCHAR(100) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE UNIQUE INDEX UX_Tb_SvcService_ServiceCode ON dbo.Tb_SvcService(service_code);
END;
GO

IF OBJECT_ID(N'dbo.Tb_SvcTenant', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_SvcTenant (
        idx INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL,
        tenant_code VARCHAR(60) NOT NULL,
        tenant_name NVARCHAR(120) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        plan_code VARCHAR(30) NOT NULL DEFAULT 'basic',
        timezone_name VARCHAR(60) NOT NULL DEFAULT 'Asia/Seoul',
        is_default BIT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE UNIQUE INDEX UX_Tb_SvcTenant_ServiceTenantCode
        ON dbo.Tb_SvcTenant(service_code, tenant_code);
    CREATE INDEX IX_Tb_SvcTenant_ServiceStatus
        ON dbo.Tb_SvcTenant(service_code, status, idx);
END;
GO

IF OBJECT_ID(N'dbo.Tb_SvcTenantUser', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_SvcTenantUser (
        idx INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        tenant_id INT NOT NULL,
        user_idx INT NOT NULL,
        role_id INT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE UNIQUE INDEX UX_Tb_SvcTenantUser_TenantUser
        ON dbo.Tb_SvcTenantUser(tenant_id, user_idx);
    CREATE INDEX IX_Tb_SvcTenantUser_UserStatus
        ON dbo.Tb_SvcTenantUser(user_idx, status, tenant_id);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM dbo.Tb_SvcService
    WHERE service_code = 'shvq'
)
BEGIN
    INSERT INTO dbo.Tb_SvcService
        (service_code, service_name, status, sort_order, created_at, updated_at)
    VALUES
        ('shvq', N'SHVQ', 'ACTIVE', 0, GETDATE(), GETDATE());
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM dbo.Tb_SvcTenant
    WHERE service_code = 'shvq'
      AND tenant_code = 'SHVQ_DEFAULT'
)
BEGIN
    INSERT INTO dbo.Tb_SvcTenant
        (service_code, tenant_code, tenant_name, status, plan_code, timezone_name, is_default, created_at, updated_at)
    VALUES
        ('shvq', 'SHVQ_DEFAULT', N'SHVQ 기본 테넌트', 'ACTIVE', 'basic', 'Asia/Seoul', 1, GETDATE(), GETDATE());
END;
GO

DECLARE @DefaultTenantId INT;
SELECT TOP 1 @DefaultTenantId = idx
FROM dbo.Tb_SvcTenant
WHERE service_code = 'shvq'
  AND status = 'ACTIVE'
ORDER BY CASE WHEN is_default = 1 THEN 0 ELSE 1 END, idx ASC;

IF @DefaultTenantId IS NULL
BEGIN
    RAISERROR(N'[STOP] Could not resolve default tenant in Tb_SvcTenant.', 16, 1);
    RETURN;
END;

INSERT INTO dbo.Tb_SvcTenantUser
    (tenant_id, user_idx, role_id, status, created_at, updated_at)
SELECT
    @DefaultTenantId AS tenant_id,
    u.idx AS user_idx,
    5 AS role_id,
    'ACTIVE' AS status,
    GETDATE() AS created_at,
    GETDATE() AS updated_at
FROM dbo.Tb_Users u
WHERE NOT EXISTS (
    SELECT 1
    FROM dbo.Tb_SvcTenantUser tu
    WHERE tu.tenant_id = @DefaultTenantId
      AND tu.user_idx = u.idx
);
GO

PRINT N'[OK] Tb_Users upsert + Tb_SvcTenant/Tb_SvcTenantUser bootstrap completed';
