/*
  SHVQ_V2 - Wave 0 Auth/Security Tables
  DB: CSM_C004732_V2 (67번 개발DB 기준)
*/

SET NOCOUNT ON;
USE [CSM_C004732_V2];
GO

IF OBJECT_ID(N'dbo.Tb_AuthRateLimit', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_AuthRateLimit (
        idx BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY CLUSTERED,
        identifier VARCHAR(128) NOT NULL,
        fail_count INT NOT NULL DEFAULT 0,
        first_fail_at DATETIME NULL,
        last_fail_at DATETIME NULL,
        lock_until DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );

    CREATE UNIQUE INDEX UX_Tb_AuthRateLimit_Identifier
        ON dbo.Tb_AuthRateLimit(identifier);
END;
GO

IF OBJECT_ID(N'dbo.Tb_AuthRememberToken', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_AuthRememberToken (
        idx BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY CLUSTERED,
        selector VARCHAR(64) NOT NULL,
        validator_hash VARCHAR(64) NOT NULL,
        user_pk INT NOT NULL,
        service_code VARCHAR(30) NOT NULL DEFAULT 'shvq',
        tenant_id INT NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );

    CREATE UNIQUE INDEX UX_Tb_AuthRememberToken_Selector
        ON dbo.Tb_AuthRememberToken(selector);

    CREATE INDEX IX_Tb_AuthRememberToken_User
        ON dbo.Tb_AuthRememberToken(user_pk, expires_at);
END;
GO

IF OBJECT_ID(N'dbo.Tb_AuthCadToken', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_AuthCadToken (
        idx BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY CLUSTERED,
        token_hash VARCHAR(64) NOT NULL,
        user_pk INT NOT NULL,
        service_code VARCHAR(30) NOT NULL DEFAULT 'shvq',
        tenant_id INT NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        is_used BIT NOT NULL DEFAULT 0,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_at DATETIME NOT NULL DEFAULT GETDATE()
    );

    CREATE UNIQUE INDEX UX_Tb_AuthCadToken_TokenHash
        ON dbo.Tb_AuthCadToken(token_hash);

    CREATE INDEX IX_Tb_AuthCadToken_UserExpiry
        ON dbo.Tb_AuthCadToken(user_pk, expires_at, is_used);
END;
GO

IF OBJECT_ID(N'dbo.Tb_AuthAuditLog', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_AuthAuditLog (
        idx BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY CLUSTERED,
        request_id VARCHAR(80) NOT NULL DEFAULT '',
        action_key VARCHAR(60) NOT NULL,
        user_pk INT NOT NULL DEFAULT 0,
        login_id NVARCHAR(120) NOT NULL DEFAULT '',
        result_code VARCHAR(30) NOT NULL DEFAULT '',
        message NVARCHAR(500) NOT NULL DEFAULT '',
        client_ip VARCHAR(50) NOT NULL DEFAULT '',
        user_agent NVARCHAR(500) NOT NULL DEFAULT '',
        meta_json NVARCHAR(MAX) NULL,
        created_at DATETIME NOT NULL DEFAULT GETDATE()
    );

    CREATE INDEX IX_Tb_AuthAuditLog_Created
        ON dbo.Tb_AuthAuditLog(created_at DESC);

    CREATE INDEX IX_Tb_AuthAuditLog_ActionResult
        ON dbo.Tb_AuthAuditLog(action_key, result_code, created_at DESC);
END;
GO

IF OBJECT_ID(N'dbo.Tb_AuthCadToken', N'U') IS NOT NULL
   AND OBJECT_ID(N'dbo.Tb_Users', N'U') IS NOT NULL
   AND NOT EXISTS (
        SELECT 1
        FROM sys.foreign_keys
        WHERE parent_object_id = OBJECT_ID(N'dbo.Tb_AuthCadToken')
          AND name = N'FK_Tb_AuthCadToken_UserPk'
   )
BEGIN
    ALTER TABLE dbo.Tb_AuthCadToken WITH CHECK
    ADD CONSTRAINT FK_Tb_AuthCadToken_UserPk
    FOREIGN KEY (user_pk) REFERENCES dbo.Tb_Users(idx);
END;
GO

IF OBJECT_ID(N'dbo.Tb_AuthRememberToken', N'U') IS NOT NULL
   AND OBJECT_ID(N'dbo.Tb_Users', N'U') IS NOT NULL
   AND NOT EXISTS (
        SELECT 1
        FROM sys.foreign_keys
        WHERE parent_object_id = OBJECT_ID(N'dbo.Tb_AuthRememberToken')
          AND name = N'FK_Tb_AuthRememberToken_UserPk'
   )
BEGIN
    ALTER TABLE dbo.Tb_AuthRememberToken WITH CHECK
    ADD CONSTRAINT FK_Tb_AuthRememberToken_UserPk
    FOREIGN KEY (user_pk) REFERENCES dbo.Tb_Users(idx);
END;
GO

/* Optional: password migration tracking columns on Tb_Users (if Tb_Users exists in V2) */
/* 권장 실행 순서: Tb_Users 이관/생성 이후 본 스크립트를 재실행하여 FK/컬럼을 최종 반영 */
IF OBJECT_ID(N'dbo.Tb_Users', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.Tb_Users', 'password_migrated_at') IS NULL
    BEGIN
        ALTER TABLE dbo.Tb_Users
            ADD password_migrated_at DATETIME NULL;
    END;
END;
GO
