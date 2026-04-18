SET NOCOUNT ON;

BEGIN TRY
    BEGIN TRAN;

    IF OBJECT_ID(N'dbo.Tb_EmployeeCard', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.Tb_EmployeeCard (
            idx INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
            service_code NVARCHAR(50) NOT NULL,
            tenant_id INT NOT NULL,
            employee_idx INT NOT NULL,
            card_usage NVARCHAR(50) NULL,
            card_name NVARCHAR(100) NULL,
            card_number NVARCHAR(120) NULL,
            card_memo NVARCHAR(500) NULL,
            is_deleted BIT NOT NULL CONSTRAINT DF_Tb_EmployeeCard_is_deleted DEFAULT (0),
            created_by INT NULL,
            updated_by INT NULL,
            deleted_by INT NULL,
            created_at DATETIME NOT NULL CONSTRAINT DF_Tb_EmployeeCard_created_at DEFAULT (GETDATE()),
            updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_EmployeeCard_updated_at DEFAULT (GETDATE()),
            deleted_at DATETIME NULL
        );
    END;

    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'card_usage') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD card_usage NVARCHAR(50) NULL;
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'card_name') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD card_name NVARCHAR(100) NULL;
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'card_number') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD card_number NVARCHAR(120) NULL;
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'card_memo') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD card_memo NVARCHAR(500) NULL;
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'is_deleted') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_EmployeeCard_is_deleted_patch DEFAULT (0);
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'created_by') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD created_by INT NULL;
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'updated_by') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD updated_by INT NULL;
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'deleted_by') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD deleted_by INT NULL;
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'created_at') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_EmployeeCard_created_at_patch DEFAULT (GETDATE());
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'updated_at') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_EmployeeCard_updated_at_patch DEFAULT (GETDATE());
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'deleted_at') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD deleted_at DATETIME NULL;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_EmployeeCard')
          AND name = N'IX_Tb_EmployeeCard_scope_employee'
    )
    BEGIN
        CREATE INDEX IX_Tb_EmployeeCard_scope_employee
            ON dbo.Tb_EmployeeCard(service_code, tenant_id, employee_idx, is_deleted, idx DESC);
    END;

    IF OBJECT_ID(N'dbo.Tb_GwEmployee', N'U') IS NOT NULL
       AND COL_LENGTH('dbo.Tb_GwEmployee', 'card_name') IS NOT NULL
       AND COL_LENGTH('dbo.Tb_GwEmployee', 'card_number') IS NOT NULL
       AND COL_LENGTH('dbo.Tb_GwEmployee', 'card_memo') IS NOT NULL
    BEGIN
        INSERT INTO dbo.Tb_EmployeeCard (
            service_code, tenant_id, employee_idx,
            card_usage, card_name, card_number, card_memo,
            is_deleted, created_by, updated_by, created_at, updated_at
        )
        SELECT
            e.service_code,
            e.tenant_id,
            e.idx AS employee_idx,
            N'통합' AS card_usage,
            NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(100), e.card_name))), N'') AS card_name,
            NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(120), e.card_number))), N'') AS card_number,
            NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(500), e.card_memo))), N'') AS card_memo,
            0 AS is_deleted,
            e.created_by,
            e.updated_by,
            ISNULL(e.created_at, GETDATE()) AS created_at,
            ISNULL(e.updated_at, GETDATE()) AS updated_at
        FROM dbo.Tb_GwEmployee e
        WHERE ISNULL(e.is_deleted, 0) = 0
          AND (
                NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(100), e.card_name))), N'') IS NOT NULL
             OR NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(120), e.card_number))), N'') IS NOT NULL
             OR NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(500), e.card_memo))), N'') IS NOT NULL
          )
          AND NOT EXISTS (
                SELECT 1
                FROM dbo.Tb_EmployeeCard c
                WHERE c.service_code = e.service_code
                  AND c.tenant_id = e.tenant_id
                  AND c.employee_idx = e.idx
                  AND ISNULL(c.is_deleted, 0) = 0
                  AND ISNULL(NULLIF(LTRIM(RTRIM(c.card_name)), N''), N'') = ISNULL(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(100), e.card_name))), N''), N'')
                  AND ISNULL(NULLIF(LTRIM(RTRIM(c.card_number)), N''), N'') = ISNULL(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(120), e.card_number))), N''), N'')
                  AND ISNULL(NULLIF(LTRIM(RTRIM(c.card_memo)), N''), N'') = ISNULL(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(500), e.card_memo))), N''), N'')
          );
    END;

    COMMIT TRAN;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRAN;

    DECLARE @ErrMsg NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @ErrSeverity INT = ERROR_SEVERITY();
    DECLARE @ErrState INT = ERROR_STATE();
    RAISERROR(@ErrMsg, @ErrSeverity, @ErrState);
END CATCH;
