/*
  SHVQ_V2 - Wave1 MAT material_pattern 보강
  목적:
  1) Tb_Item.material_pattern 컬럼 보장
  2) V1(CSM_C004732) -> V2(CSM_C004732_V2) material_pattern 백필
     - 1차: legacy_idx 매핑
     - 2차: item_code 매핑(legacy_idx 미매핑 보조)
  3) 재실행해도 안전(idempotent)
*/

SET NOCOUNT ON;
USE [CSM_C004732_V2];
GO

IF OBJECT_ID(N'dbo.Tb_Item', N'U') IS NULL
BEGIN
    RAISERROR(N'[STOP] Target table missing: dbo.Tb_Item', 16, 1);
    RETURN;
END;
GO

IF COL_LENGTH('dbo.Tb_Item', 'material_pattern') IS NULL
BEGIN
    ALTER TABLE dbo.Tb_Item ADD material_pattern NVARCHAR(20) NULL;
END;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @UpdatedByLegacyIdx INT = 0;
    DECLARE @UpdatedByItemCode INT = 0;

    IF DB_ID(N'CSM_C004732') IS NOT NULL
       AND OBJECT_ID(N'[CSM_C004732].dbo.[Tb_Item]', N'U') IS NOT NULL
       AND COL_LENGTH('CSM_C004732.dbo.Tb_Item', 'material_pattern') IS NOT NULL
    BEGIN
        UPDATE t
        SET t.material_pattern = LEFT(LTRIM(RTRIM(CONVERT(NVARCHAR(20), s.material_pattern))), 20)
        FROM dbo.Tb_Item t
        INNER JOIN [CSM_C004732].dbo.Tb_Item s
            ON t.legacy_idx = s.idx
        WHERE ISNULL(LTRIM(RTRIM(CONVERT(NVARCHAR(20), s.material_pattern))), N'') <> N''
          AND ISNULL(LTRIM(RTRIM(CONVERT(NVARCHAR(20), t.material_pattern))), N'') = N'';
        SET @UpdatedByLegacyIdx = @@ROWCOUNT;

        UPDATE t
        SET t.material_pattern = LEFT(LTRIM(RTRIM(CONVERT(NVARCHAR(20), s.material_pattern))), 20)
        FROM dbo.Tb_Item t
        INNER JOIN [CSM_C004732].dbo.Tb_Item s
            ON LTRIM(RTRIM(CONVERT(NVARCHAR(120), t.item_code)))
             = LTRIM(RTRIM(CONVERT(NVARCHAR(120), s.item_code)))
        WHERE ISNULL(LTRIM(RTRIM(CONVERT(NVARCHAR(20), s.material_pattern))), N'') <> N''
          AND ISNULL(LTRIM(RTRIM(CONVERT(NVARCHAR(20), t.material_pattern))), N'') = N'';
        SET @UpdatedByItemCode = @@ROWCOUNT;
    END;

    COMMIT TRANSACTION;

    PRINT N'[OK] material_pattern patch completed';
    SELECT
        @UpdatedByLegacyIdx AS updated_by_legacy_idx,
        @UpdatedByItemCode AS updated_by_item_code,
        (SELECT COUNT(1)
         FROM dbo.Tb_Item
         WHERE ISNULL(LTRIM(RTRIM(CONVERT(NVARCHAR(20), material_pattern))), N'') <> N'') AS filled_material_pattern_count,
        (SELECT COUNT(1) FROM dbo.Tb_Item) AS total_item_count;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    DECLARE @ErrMsg NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @ErrProc NVARCHAR(200) = ERROR_PROCEDURE();
    DECLARE @ErrLine INT = ERROR_LINE();
    RAISERROR(N'[FAIL] %s (proc=%s, line=%d)', 16, 1, @ErrMsg, ISNULL(@ErrProc, N'-'), @ErrLine);
END CATCH;
GO

/* 수동 확인: CCTV내부배선_여유 */
SELECT TOP 20
    idx,
    item_code,
    name,
    material_pattern,
    legacy_idx
FROM dbo.Tb_Item
WHERE name LIKE N'%CCTV%내부배선%여유%'
   OR item_code LIKE N'%CCTV%내부배선%여유%'
ORDER BY idx;
GO
