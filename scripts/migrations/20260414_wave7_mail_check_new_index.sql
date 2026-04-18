/*
  SHVQ V2 - Wave 7 (mail_check_new unread index)
  Target DB: CSM_C004732_V2
  Date: 2026-04-14
*/

SET NOCOUNT ON;

IF OBJECT_ID(N'dbo.Tb_Mail_MessageCache', N'U') IS NULL
BEGIN
    PRINT N'[SKIP] Tb_Mail_MessageCache not found.';
END
ELSE IF (
    COL_LENGTH('dbo.Tb_Mail_MessageCache', 'account_idx') IS NULL
    OR COL_LENGTH('dbo.Tb_Mail_MessageCache', 'folder') IS NULL
    OR COL_LENGTH('dbo.Tb_Mail_MessageCache', 'is_seen') IS NULL
)
BEGIN
    PRINT N'[SKIP] Required columns missing in Tb_Mail_MessageCache(account_idx, folder, is_seen).';
END
ELSE IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_Mail_MessageCache')
      AND name = N'IX_Tb_Mail_MessageCache_AccountFolderSeen'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_Tb_Mail_MessageCache_AccountFolderSeen
        ON dbo.Tb_Mail_MessageCache (account_idx, folder, is_seen);

    PRINT N'[OK] IX_Tb_Mail_MessageCache_AccountFolderSeen created.';
END
ELSE
BEGIN
    PRINT N'[OK] IX_Tb_Mail_MessageCache_AccountFolderSeen already exists.';
END

