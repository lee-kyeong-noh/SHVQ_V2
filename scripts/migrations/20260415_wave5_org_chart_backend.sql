SET NOCOUNT ON;
SET XACT_ABORT ON;

USE [CSM_C004732_V2];

PRINT '=== Wave5 org chart backend schema start ===';

/* ------------------------------------------------------------------
   Tb_HeadOrgFolder
------------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.Tb_HeadOrgFolder', N'U') IS NULL
BEGIN
    PRINT 'Create dbo.Tb_HeadOrgFolder';
    CREATE TABLE dbo.Tb_HeadOrgFolder (
        idx              INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        head_idx         INT NOT NULL CONSTRAINT DF_Tb_HeadOrgFolder_head_idx DEFAULT(0),
        parent_idx       INT NULL,
        name             NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_HeadOrgFolder_name DEFAULT(N''),
        depth            INT NOT NULL CONSTRAINT DF_Tb_HeadOrgFolder_depth DEFAULT(1),
        sort_order       INT NOT NULL CONSTRAINT DF_Tb_HeadOrgFolder_sort_order DEFAULT(1),
        is_deleted       BIT NOT NULL CONSTRAINT DF_Tb_HeadOrgFolder_is_deleted DEFAULT(0),
        created_at       DATETIME NOT NULL CONSTRAINT DF_Tb_HeadOrgFolder_created_at DEFAULT(GETDATE()),
        created_by       INT NULL,
        updated_at       DATETIME NOT NULL CONSTRAINT DF_Tb_HeadOrgFolder_updated_at DEFAULT(GETDATE()),
        updated_by       INT NULL,
        deleted_at       DATETIME NULL,
        deleted_by       INT NULL,
        regdate          DATETIME NOT NULL CONSTRAINT DF_Tb_HeadOrgFolder_regdate DEFAULT(GETDATE()),
        registered_date  DATETIME NOT NULL CONSTRAINT DF_Tb_HeadOrgFolder_registered_date DEFAULT(GETDATE())
    );
END
ELSE
BEGIN
    PRINT 'Patch dbo.Tb_HeadOrgFolder missing columns';
    IF COL_LENGTH('dbo.Tb_HeadOrgFolder', 'head_idx') IS NULL ALTER TABLE dbo.Tb_HeadOrgFolder ADD head_idx INT NOT NULL CONSTRAINT DF_Tb_HeadOrgFolder_head_idx_patch DEFAULT(0);
    IF COL_LENGTH('dbo.Tb_HeadOrgFolder', 'parent_idx') IS NULL ALTER TABLE dbo.Tb_HeadOrgFolder ADD parent_idx INT NULL;
    IF COL_LENGTH('dbo.Tb_HeadOrgFolder', 'name') IS NULL ALTER TABLE dbo.Tb_HeadOrgFolder ADD name NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_HeadOrgFolder_name_patch DEFAULT(N'');
    IF COL_LENGTH('dbo.Tb_HeadOrgFolder', 'depth') IS NULL ALTER TABLE dbo.Tb_HeadOrgFolder ADD depth INT NOT NULL CONSTRAINT DF_Tb_HeadOrgFolder_depth_patch DEFAULT(1);
    IF COL_LENGTH('dbo.Tb_HeadOrgFolder', 'sort_order') IS NULL ALTER TABLE dbo.Tb_HeadOrgFolder ADD sort_order INT NOT NULL CONSTRAINT DF_Tb_HeadOrgFolder_sort_order_patch DEFAULT(1);

    IF COL_LENGTH('dbo.Tb_HeadOrgFolder', 'is_deleted') IS NULL ALTER TABLE dbo.Tb_HeadOrgFolder ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_HeadOrgFolder_is_deleted_patch DEFAULT(0);
    IF COL_LENGTH('dbo.Tb_HeadOrgFolder', 'created_at') IS NULL ALTER TABLE dbo.Tb_HeadOrgFolder ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_HeadOrgFolder_created_at_patch DEFAULT(GETDATE());
    IF COL_LENGTH('dbo.Tb_HeadOrgFolder', 'created_by') IS NULL ALTER TABLE dbo.Tb_HeadOrgFolder ADD created_by INT NULL;
    IF COL_LENGTH('dbo.Tb_HeadOrgFolder', 'updated_at') IS NULL ALTER TABLE dbo.Tb_HeadOrgFolder ADD updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_HeadOrgFolder_updated_at_patch DEFAULT(GETDATE());
    IF COL_LENGTH('dbo.Tb_HeadOrgFolder', 'updated_by') IS NULL ALTER TABLE dbo.Tb_HeadOrgFolder ADD updated_by INT NULL;
    IF COL_LENGTH('dbo.Tb_HeadOrgFolder', 'deleted_at') IS NULL ALTER TABLE dbo.Tb_HeadOrgFolder ADD deleted_at DATETIME NULL;
    IF COL_LENGTH('dbo.Tb_HeadOrgFolder', 'deleted_by') IS NULL ALTER TABLE dbo.Tb_HeadOrgFolder ADD deleted_by INT NULL;
    IF COL_LENGTH('dbo.Tb_HeadOrgFolder', 'regdate') IS NULL ALTER TABLE dbo.Tb_HeadOrgFolder ADD regdate DATETIME NOT NULL CONSTRAINT DF_Tb_HeadOrgFolder_regdate_patch DEFAULT(GETDATE());
    IF COL_LENGTH('dbo.Tb_HeadOrgFolder', 'registered_date') IS NULL ALTER TABLE dbo.Tb_HeadOrgFolder ADD registered_date DATETIME NOT NULL CONSTRAINT DF_Tb_HeadOrgFolder_registered_date_patch DEFAULT(GETDATE());
END

IF NOT EXISTS (
    SELECT 1 FROM sys.check_constraints
    WHERE parent_object_id = OBJECT_ID(N'dbo.Tb_HeadOrgFolder')
      AND name = N'CK_Tb_HeadOrgFolder_depth_1_3'
)
BEGIN
    ALTER TABLE dbo.Tb_HeadOrgFolder WITH NOCHECK
    ADD CONSTRAINT CK_Tb_HeadOrgFolder_depth_1_3 CHECK (depth BETWEEN 1 AND 3);
END

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_HeadOrgFolder')
      AND name = N'IX_Tb_HeadOrgFolder_head_parent_sort'
)
BEGIN
    CREATE INDEX IX_Tb_HeadOrgFolder_head_parent_sort
        ON dbo.Tb_HeadOrgFolder(head_idx, parent_idx, is_deleted, sort_order, idx);
END

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_HeadOrgFolder')
      AND name = N'IX_Tb_HeadOrgFolder_parent'
)
BEGIN
    CREATE INDEX IX_Tb_HeadOrgFolder_parent
        ON dbo.Tb_HeadOrgFolder(parent_idx, is_deleted, idx);
END

IF OBJECT_ID(N'dbo.Tb_HeadOffice', N'U') IS NOT NULL
   AND COL_LENGTH('dbo.Tb_HeadOffice', 'idx') IS NOT NULL
   AND NOT EXISTS (
       SELECT 1 FROM sys.foreign_keys
       WHERE parent_object_id = OBJECT_ID(N'dbo.Tb_HeadOrgFolder')
         AND name = N'FK_Tb_HeadOrgFolder_head_idx'
   )
BEGIN
    ALTER TABLE dbo.Tb_HeadOrgFolder WITH NOCHECK
    ADD CONSTRAINT FK_Tb_HeadOrgFolder_head_idx
    FOREIGN KEY (head_idx) REFERENCES dbo.Tb_HeadOffice(idx);
END

IF NOT EXISTS (
    SELECT 1 FROM sys.foreign_keys
    WHERE parent_object_id = OBJECT_ID(N'dbo.Tb_HeadOrgFolder')
      AND name = N'FK_Tb_HeadOrgFolder_parent_idx'
)
BEGIN
    ALTER TABLE dbo.Tb_HeadOrgFolder WITH NOCHECK
    ADD CONSTRAINT FK_Tb_HeadOrgFolder_parent_idx
    FOREIGN KEY (parent_idx) REFERENCES dbo.Tb_HeadOrgFolder(idx);
END

/* ------------------------------------------------------------------
   Tb_PhoneBook requested columns
------------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.Tb_PhoneBook', N'U') IS NOT NULL
BEGIN
    PRINT 'Ensure Tb_PhoneBook org/contact columns';
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'job_grade') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD job_grade NVARCHAR(50) NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'job_title') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD job_title NVARCHAR(50) NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'work_status') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD work_status NVARCHAR(20) NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'main_work') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD main_work NVARCHAR(200) NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'branch_folder_idx') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD branch_folder_idx INT NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'is_hidden') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD is_hidden INT NOT NULL CONSTRAINT DF_Tb_PhoneBook_is_hidden_wave5 DEFAULT(0);

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_PhoneBook')
          AND name = N'IX_Tb_PhoneBook_branch_folder_idx'
    )
    BEGIN
        CREATE INDEX IX_Tb_PhoneBook_branch_folder_idx
            ON dbo.Tb_PhoneBook(branch_folder_idx, idx);
    END
END

/* ------------------------------------------------------------------
   Tb_Members org_folder_idx
------------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.Tb_Members', N'U') IS NOT NULL
BEGIN
    PRINT 'Ensure Tb_Members org_folder_idx';
    IF COL_LENGTH('dbo.Tb_Members', 'org_folder_idx') IS NULL ALTER TABLE dbo.Tb_Members ADD org_folder_idx INT NULL;

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_Members')
          AND name = N'IX_Tb_Members_org_folder_idx'
    )
    BEGIN
        CREATE INDEX IX_Tb_Members_org_folder_idx
            ON dbo.Tb_Members(org_folder_idx, idx);
    END
END

/* ------------------------------------------------------------------
   Optional FK links (new rows check)
------------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.Tb_Members', N'U') IS NOT NULL
   AND COL_LENGTH('dbo.Tb_Members', 'org_folder_idx') IS NOT NULL
   AND NOT EXISTS (
       SELECT 1 FROM sys.foreign_keys
       WHERE parent_object_id = OBJECT_ID(N'dbo.Tb_Members')
         AND name = N'FK_Tb_Members_org_folder_idx'
   )
BEGIN
    ALTER TABLE dbo.Tb_Members WITH NOCHECK
    ADD CONSTRAINT FK_Tb_Members_org_folder_idx
    FOREIGN KEY (org_folder_idx) REFERENCES dbo.Tb_HeadOrgFolder(idx);
END

IF OBJECT_ID(N'dbo.Tb_PhoneBook', N'U') IS NOT NULL
   AND COL_LENGTH('dbo.Tb_PhoneBook', 'branch_folder_idx') IS NOT NULL
   AND NOT EXISTS (
       SELECT 1 FROM sys.foreign_keys
       WHERE parent_object_id = OBJECT_ID(N'dbo.Tb_PhoneBook')
         AND name = N'FK_Tb_PhoneBook_branch_folder_idx'
   )
BEGIN
    ALTER TABLE dbo.Tb_PhoneBook WITH NOCHECK
    ADD CONSTRAINT FK_Tb_PhoneBook_branch_folder_idx
    FOREIGN KEY (branch_folder_idx) REFERENCES dbo.Tb_HeadOrgFolder(idx);
END

PRINT '=== Wave5 org chart backend schema done ===';

DECLARE @summary TABLE (table_name NVARCHAR(100), row_count INT);

IF OBJECT_ID(N'dbo.Tb_HeadOrgFolder', N'U') IS NOT NULL
    INSERT INTO @summary(table_name, row_count) SELECT 'Tb_HeadOrgFolder', COUNT(*) FROM dbo.Tb_HeadOrgFolder;
IF OBJECT_ID(N'dbo.Tb_Members', N'U') IS NOT NULL
    INSERT INTO @summary(table_name, row_count) SELECT 'Tb_Members', COUNT(*) FROM dbo.Tb_Members;
IF OBJECT_ID(N'dbo.Tb_PhoneBook', N'U') IS NOT NULL
    INSERT INTO @summary(table_name, row_count) SELECT 'Tb_PhoneBook', COUNT(*) FROM dbo.Tb_PhoneBook;

SELECT table_name, row_count
FROM @summary
ORDER BY table_name;
