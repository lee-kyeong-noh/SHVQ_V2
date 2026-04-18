/*
  SHVQ V2 - Wave 5 (Mail FCM + Realtime Cache Columns)
  Target DB: CSM_C004732_V2
  Date: 2026-04-14
*/

SET NOCOUNT ON;

/* 1) Tb_Mail_FcmToken 신규 */
IF OBJECT_ID(N'dbo.Tb_Mail_FcmToken', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_Mail_FcmToken (
        id          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Tb_Mail_FcmToken PRIMARY KEY,
        user_pk     INT NOT NULL,
        tenant_id   INT NOT NULL CONSTRAINT DF_Tb_Mail_FcmToken_tid DEFAULT (0),
        token       NVARCHAR(500) NOT NULL,
        device_type VARCHAR(20) NOT NULL CONSTRAINT DF_Tb_Mail_FcmToken_device DEFAULT ('web'),
        user_agent  NVARCHAR(300) NULL,
        created_at  DATETIME NOT NULL CONSTRAINT DF_Tb_Mail_FcmToken_created DEFAULT (GETDATE()),
        updated_at  DATETIME NOT NULL CONSTRAINT DF_Tb_Mail_FcmToken_updated DEFAULT (GETDATE())
    );
END
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_Mail_FcmToken')
      AND name = N'UX_FcmToken_Token'
)
BEGIN
    CREATE UNIQUE INDEX UX_FcmToken_Token
    ON dbo.Tb_Mail_FcmToken(token);
END
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_Mail_FcmToken')
      AND name = N'IX_FcmToken_UserPk'
)
BEGIN
    CREATE INDEX IX_FcmToken_UserPk
    ON dbo.Tb_Mail_FcmToken(user_pk, tenant_id);
END
GO

/* 2) Tb_Mail_MessageCache 컬럼 확장 */
IF COL_LENGTH('dbo.Tb_Mail_MessageCache', 'body_hash') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Mail_MessageCache
    ADD body_hash VARCHAR(64) NULL;
END
GO

IF COL_LENGTH('dbo.Tb_Mail_MessageCache', 'fcm_notified') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Mail_MessageCache
    ADD fcm_notified BIT NOT NULL
        CONSTRAINT DF_Tb_Mail_MessageCache_fcm_notified DEFAULT (0) WITH VALUES;
END
GO

/* 3) body_preview 200자 제한 */
IF COL_LENGTH('dbo.Tb_Mail_MessageCache', 'body_preview') IS NOT NULL
BEGIN
    ALTER TABLE dbo.Tb_Mail_MessageCache
    ALTER COLUMN body_preview NVARCHAR(200) NULL;
END
GO

PRINT 'Wave 5 migration complete: Tb_Mail_FcmToken + MessageCache(body_hash,fcm_notified,body_preview=200)';

