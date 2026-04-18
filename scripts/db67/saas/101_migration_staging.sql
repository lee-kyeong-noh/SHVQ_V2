/*
  SHVQ_V2 - Migration staging (V1 -> V2)
  목적:
  1) V1(CSM_C004732) Tb_Users를 V2 스테이징(STG_Tb_Users)으로 적재
  2) 재실행 가능(멱등) + 전체 재적재 방식
*/

SET NOCOUNT ON;
USE [CSM_C004732_V2];
GO

DECLARE @SourceDb SYSNAME = N'CSM_C004732';

IF DB_ID(@SourceDb) IS NULL
BEGIN
    RAISERROR(N'[STOP] Source DB not found: %s', 16, 1, @SourceDb);
    RETURN;
END;

IF OBJECT_ID(QUOTENAME(@SourceDb) + N'.dbo.Tb_Users', N'U') IS NULL
BEGIN
    RAISERROR(N'[STOP] Source table not found: %s.dbo.Tb_Users', 16, 1, @SourceDb);
    RETURN;
END;
GO

IF OBJECT_ID(N'dbo.STG_Tb_Users', N'U') IS NULL
BEGIN
    EXEC(N'SELECT TOP 0 * INTO dbo.STG_Tb_Users FROM [CSM_C004732].dbo.Tb_Users;');
END;
GO

IF COL_LENGTH('dbo.STG_Tb_Users', '_loaded_at') IS NULL
BEGIN
    ALTER TABLE dbo.STG_Tb_Users
        ADD _loaded_at DATETIME NULL;
END;
GO

TRUNCATE TABLE dbo.STG_Tb_Users;
GO

DECLARE @ColumnList NVARCHAR(MAX);
DECLARE @InsertSql NVARCHAR(MAX);

SELECT @ColumnList = STRING_AGG(QUOTENAME(name), ',')
FROM sys.columns
WHERE object_id = OBJECT_ID(N'dbo.STG_Tb_Users')
  AND name <> '_loaded_at';

IF @ColumnList IS NULL OR LTRIM(RTRIM(@ColumnList)) = ''
BEGIN
    RAISERROR(N'[STOP] STG_Tb_Users has no columns to load.', 16, 1);
    RETURN;
END;

SET @InsertSql = N'
    INSERT INTO dbo.STG_Tb_Users (' + @ColumnList + N')
    SELECT ' + @ColumnList + N'
    FROM [CSM_C004732].dbo.Tb_Users;';

EXEC sp_executesql @InsertSql;

UPDATE dbo.STG_Tb_Users
SET _loaded_at = GETDATE();
GO

PRINT N'[OK] STG_Tb_Users loaded from CSM_C004732.dbo.Tb_Users';
