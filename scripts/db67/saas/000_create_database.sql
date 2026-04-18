/*
  SHVQ_V2 - DB 초기화 스크립트 (67번 개발DB 전용)
  - 기본 DB명: CSM_C004732_V2
  - 필요 시 @DbName 값만 변경
*/

SET NOCOUNT ON;

DECLARE @DbName SYSNAME = N'CSM_C004732_V2';
DECLARE @Sql NVARCHAR(MAX);

IF DB_ID(@DbName) IS NULL
BEGIN
    SET @Sql = N'CREATE DATABASE ' + QUOTENAME(@DbName) + N';';
    EXEC sp_executesql @Sql;
    PRINT N'[OK] Database created: ' + @DbName;
END
ELSE
BEGIN
    PRINT N'[SKIP] Database already exists: ' + @DbName;
END;

SET @Sql = N'ALTER DATABASE ' + QUOTENAME(@DbName) + N' SET RECOVERY SIMPLE;';
EXEC sp_executesql @Sql;
PRINT N'[OK] Recovery model set to SIMPLE: ' + @DbName;
