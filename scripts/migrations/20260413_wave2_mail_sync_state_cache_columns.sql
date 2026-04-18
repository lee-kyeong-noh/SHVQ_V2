/*
  SHVQ_V2 - Wave2 Mail Sync State / Cache Column Patch
  목적:
  1) Tb_Mail_FolderSyncState 테이블 보장 (account_idx, folder, last_uid, uidvalidity, last_synced_at)
  2) Tb_Mail_MessageCache 컬럼 보완
     - body_preview, thread_id, in_reply_to, [references]

  실행 DB: CSM_C004732_V2
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
USE [CSM_C004732_V2];
GO

IF OBJECT_ID(N'dbo.Tb_Mail_FolderSyncState', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_Mail_FolderSyncState (
        account_idx INT NOT NULL,
        folder NVARCHAR(255) NOT NULL,
        last_uid BIGINT NOT NULL CONSTRAINT DF_Tb_Mail_FolderSyncState_LastUid DEFAULT (0),
        uidvalidity BIGINT NULL,
        last_synced_at DATETIME NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_Mail_FolderSyncState_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_Mail_FolderSyncState_UpdatedAt DEFAULT (GETDATE()),
        CONSTRAINT PK_Tb_Mail_FolderSyncState PRIMARY KEY CLUSTERED (account_idx, folder)
    );
END;
GO

IF OBJECT_ID(N'dbo.Tb_Mail_FolderSyncState', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.Tb_Mail_FolderSyncState', 'last_uid') IS NULL
        ALTER TABLE dbo.Tb_Mail_FolderSyncState
            ADD last_uid BIGINT NOT NULL CONSTRAINT DF_Tb_Mail_FolderSyncState_LastUid_Alt DEFAULT (0);

    IF COL_LENGTH('dbo.Tb_Mail_FolderSyncState', 'uidvalidity') IS NULL
        ALTER TABLE dbo.Tb_Mail_FolderSyncState
            ADD uidvalidity BIGINT NULL;

    IF COL_LENGTH('dbo.Tb_Mail_FolderSyncState', 'last_synced_at') IS NULL
        ALTER TABLE dbo.Tb_Mail_FolderSyncState
            ADD last_synced_at DATETIME NULL;

    IF COL_LENGTH('dbo.Tb_Mail_FolderSyncState', 'created_at') IS NULL
        ALTER TABLE dbo.Tb_Mail_FolderSyncState
            ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_Mail_FolderSyncState_CreatedAt_Alt DEFAULT (GETDATE());

    IF COL_LENGTH('dbo.Tb_Mail_FolderSyncState', 'updated_at') IS NULL
        ALTER TABLE dbo.Tb_Mail_FolderSyncState
            ADD updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_Mail_FolderSyncState_UpdatedAt_Alt DEFAULT (GETDATE());

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_Mail_FolderSyncState')
          AND name = N'IX_Tb_Mail_FolderSyncState_LastSynced'
    )
    BEGIN
        CREATE INDEX IX_Tb_Mail_FolderSyncState_LastSynced
            ON dbo.Tb_Mail_FolderSyncState(account_idx, last_synced_at DESC);
    END
END;
GO

IF OBJECT_ID(N'dbo.Tb_Mail_MessageCache', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.Tb_Mail_MessageCache', 'body_preview') IS NULL
        ALTER TABLE dbo.Tb_Mail_MessageCache
            ADD body_preview NVARCHAR(1000) NULL;

    IF COL_LENGTH('dbo.Tb_Mail_MessageCache', 'thread_id') IS NULL
        ALTER TABLE dbo.Tb_Mail_MessageCache
            ADD thread_id NVARCHAR(200) NULL;

    IF COL_LENGTH('dbo.Tb_Mail_MessageCache', 'in_reply_to') IS NULL
        ALTER TABLE dbo.Tb_Mail_MessageCache
            ADD in_reply_to NVARCHAR(300) NULL;

    IF COL_LENGTH('dbo.Tb_Mail_MessageCache', 'references') IS NULL
        ALTER TABLE dbo.Tb_Mail_MessageCache
            ADD [references] NVARCHAR(MAX) NULL;

    PRINT N'[OK] Tb_Mail_MessageCache column patch applied.';
END
ELSE
BEGIN
    PRINT N'[WARN] Tb_Mail_MessageCache not found. Column patch skipped.';
END;
GO

PRINT N'[OK] Wave2 mail sync state/cache schema patch complete.';
