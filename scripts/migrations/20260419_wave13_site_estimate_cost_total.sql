SET NOCOUNT ON;
SET XACT_ABORT ON;

USE [CSM_C004732_V2];

PRINT '=== Wave13 Tb_SiteEstimate cost_total/increase_amount patch start ===';

IF OBJECT_ID(N'dbo.Tb_SiteEstimate', N'U') IS NULL
BEGIN
    PRINT 'Tb_SiteEstimate table is missing - skip';
END
ELSE
BEGIN
    IF COL_LENGTH('dbo.Tb_SiteEstimate', 'cost_total') IS NULL
    BEGIN
        ALTER TABLE dbo.Tb_SiteEstimate ADD cost_total INT NULL;
        PRINT 'Added column: cost_total (INT NULL)';
    END
    ELSE
    BEGIN
        PRINT 'Column already exists: cost_total';
    END

    IF COL_LENGTH('dbo.Tb_SiteEstimate', 'increase_amount') IS NULL
    BEGIN
        ALTER TABLE dbo.Tb_SiteEstimate ADD increase_amount INT NULL;
        PRINT 'Added column: increase_amount (INT NULL)';
    END
    ELSE
    BEGIN
        PRINT 'Column already exists: increase_amount';
    END
END

PRINT '=== Wave13 Tb_SiteEstimate cost_total/increase_amount patch done ===';

SELECT
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'Tb_SiteEstimate'
  AND COLUMN_NAME IN ('cost_total', 'increase_amount')
ORDER BY COLUMN_NAME;
