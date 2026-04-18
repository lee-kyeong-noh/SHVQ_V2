SET NOCOUNT ON;
SET XACT_ABORT ON;

USE [CSM_C004732_V2];

PRINT '=== Wave3 FieldManager/PhoneBook table bootstrap start ===';

/* ------------------------------------------------------------------
   Tb_Users_fieldManager
------------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.Tb_Users_fieldManager', N'U') IS NULL
BEGIN
    PRINT 'Create dbo.Tb_Users_fieldManager';
    CREATE TABLE dbo.Tb_Users_fieldManager (
        idx              INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id               NVARCHAR(50) NOT NULL CONSTRAINT DF_Tb_Users_fieldManager_id DEFAULT(N''),
        name             NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_Users_fieldManager_name DEFAULT(N''),
        passwd           NVARCHAR(255) NULL,
        member_idx       INT NULL,
        site_idx         INT NOT NULL CONSTRAINT DF_Tb_Users_fieldManager_site_idx DEFAULT(0),
        sosok            NVARCHAR(120) NULL,
        part             NVARCHAR(120) NULL,
        hp               NVARCHAR(40) NULL,
        email            NVARCHAR(200) NULL,
        comment          NVARCHAR(MAX) NULL,
        employee_idx     INT NULL,
        is_deleted       BIT NOT NULL CONSTRAINT DF_Tb_Users_fieldManager_is_deleted DEFAULT(0),
        created_at       DATETIME NOT NULL CONSTRAINT DF_Tb_Users_fieldManager_created_at DEFAULT(GETDATE()),
        created_by       INT NULL,
        updated_at       DATETIME NOT NULL CONSTRAINT DF_Tb_Users_fieldManager_updated_at DEFAULT(GETDATE()),
        updated_by       INT NULL,
        deleted_at       DATETIME NULL,
        deleted_by       INT NULL,
        regdate          DATETIME NOT NULL CONSTRAINT DF_Tb_Users_fieldManager_regdate DEFAULT(GETDATE()),
        registered_date  DATETIME NOT NULL CONSTRAINT DF_Tb_Users_fieldManager_registered_date DEFAULT(GETDATE())
    );
END
ELSE
BEGIN
    PRINT 'Patch dbo.Tb_Users_fieldManager missing columns';
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'id') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD id NVARCHAR(50) NOT NULL CONSTRAINT DF_Tb_Users_fieldManager_id_patch DEFAULT(N'');
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'name') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD name NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_Users_fieldManager_name_patch DEFAULT(N'');
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'passwd') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD passwd NVARCHAR(255) NULL;
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'member_idx') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD member_idx INT NULL;
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'site_idx') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD site_idx INT NOT NULL CONSTRAINT DF_Tb_Users_fieldManager_site_idx_patch DEFAULT(0);
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'sosok') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD sosok NVARCHAR(120) NULL;
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'part') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD part NVARCHAR(120) NULL;
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'hp') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD hp NVARCHAR(40) NULL;
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'email') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD email NVARCHAR(200) NULL;
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'comment') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD comment NVARCHAR(MAX) NULL;
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'employee_idx') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD employee_idx INT NULL;

    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'is_deleted') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_Users_fieldManager_is_deleted_patch DEFAULT(0);
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'created_at') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_Users_fieldManager_created_at_patch DEFAULT(GETDATE());
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'created_by') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD created_by INT NULL;
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'updated_at') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_Users_fieldManager_updated_at_patch DEFAULT(GETDATE());
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'updated_by') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD updated_by INT NULL;
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'deleted_at') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD deleted_at DATETIME NULL;
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'deleted_by') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD deleted_by INT NULL;
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'regdate') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD regdate DATETIME NOT NULL CONSTRAINT DF_Tb_Users_fieldManager_regdate_patch DEFAULT(GETDATE());
    IF COL_LENGTH('dbo.Tb_Users_fieldManager', 'registered_date') IS NULL ALTER TABLE dbo.Tb_Users_fieldManager ADD registered_date DATETIME NOT NULL CONSTRAINT DF_Tb_Users_fieldManager_registered_date_patch DEFAULT(GETDATE());
END

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_Users_fieldManager')
      AND name = N'IX_Tb_Users_fieldManager_member_site_deleted'
)
BEGIN
    CREATE INDEX IX_Tb_Users_fieldManager_member_site_deleted
        ON dbo.Tb_Users_fieldManager(member_idx, site_idx, is_deleted, idx);
END

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_Users_fieldManager')
      AND name = N'IX_Tb_Users_fieldManager_hp_deleted'
)
BEGIN
    CREATE INDEX IX_Tb_Users_fieldManager_hp_deleted
        ON dbo.Tb_Users_fieldManager(hp, is_deleted, idx);
END

/* ------------------------------------------------------------------
   Tb_PhoneBook
------------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.Tb_PhoneBook', N'U') IS NULL
BEGIN
    PRINT 'Create dbo.Tb_PhoneBook';
    CREATE TABLE dbo.Tb_PhoneBook (
        idx               INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        member_idx        INT NOT NULL CONSTRAINT DF_Tb_PhoneBook_member_idx DEFAULT(0),
        site_idx          INT NOT NULL CONSTRAINT DF_Tb_PhoneBook_site_idx DEFAULT(0),
        name              NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_PhoneBook_name DEFAULT(N''),
        sosok             NVARCHAR(120) NULL,
        part              NVARCHAR(120) NULL,
        hp                NVARCHAR(40) NULL,
        email             NVARCHAR(200) NULL,
        comment           NVARCHAR(MAX) NULL,
        employee_idx      INT NULL,
        branch_folder_idx INT NULL,
        work_status       NVARCHAR(20) NULL,
        main_work         NVARCHAR(200) NULL,
        history_log       NVARCHAR(MAX) NULL,
        job_grade         NVARCHAR(50) NULL,
        job_title         NVARCHAR(50) NULL,
        is_hidden         INT NOT NULL CONSTRAINT DF_Tb_PhoneBook_is_hidden DEFAULT(0),
        company_idx       INT NULL,
        is_deleted        BIT NOT NULL CONSTRAINT DF_Tb_PhoneBook_is_deleted DEFAULT(0),
        created_at        DATETIME NOT NULL CONSTRAINT DF_Tb_PhoneBook_created_at DEFAULT(GETDATE()),
        created_by        INT NULL,
        updated_at        DATETIME NOT NULL CONSTRAINT DF_Tb_PhoneBook_updated_at DEFAULT(GETDATE()),
        updated_by        INT NULL,
        deleted_at        DATETIME NULL,
        deleted_by        INT NULL,
        regdate           DATETIME NOT NULL CONSTRAINT DF_Tb_PhoneBook_regdate DEFAULT(GETDATE()),
        registered_date   DATETIME NOT NULL CONSTRAINT DF_Tb_PhoneBook_registered_date DEFAULT(GETDATE())
    );
END
ELSE
BEGIN
    PRINT 'Patch dbo.Tb_PhoneBook missing columns';
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'member_idx') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD member_idx INT NOT NULL CONSTRAINT DF_Tb_PhoneBook_member_idx_patch DEFAULT(0);
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'site_idx') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD site_idx INT NOT NULL CONSTRAINT DF_Tb_PhoneBook_site_idx_patch DEFAULT(0);
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'name') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD name NVARCHAR(120) NOT NULL CONSTRAINT DF_Tb_PhoneBook_name_patch DEFAULT(N'');
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'sosok') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD sosok NVARCHAR(120) NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'part') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD part NVARCHAR(120) NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'hp') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD hp NVARCHAR(40) NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'email') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD email NVARCHAR(200) NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'comment') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD comment NVARCHAR(MAX) NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'employee_idx') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD employee_idx INT NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'branch_folder_idx') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD branch_folder_idx INT NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'work_status') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD work_status NVARCHAR(20) NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'main_work') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD main_work NVARCHAR(200) NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'history_log') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD history_log NVARCHAR(MAX) NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'job_grade') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD job_grade NVARCHAR(50) NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'job_title') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD job_title NVARCHAR(50) NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'is_hidden') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD is_hidden INT NOT NULL CONSTRAINT DF_Tb_PhoneBook_is_hidden_patch DEFAULT(0);
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'company_idx') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD company_idx INT NULL;

    IF COL_LENGTH('dbo.Tb_PhoneBook', 'is_deleted') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_PhoneBook_is_deleted_patch DEFAULT(0);
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'created_at') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_PhoneBook_created_at_patch DEFAULT(GETDATE());
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'created_by') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD created_by INT NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'updated_at') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_PhoneBook_updated_at_patch DEFAULT(GETDATE());
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'updated_by') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD updated_by INT NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'deleted_at') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD deleted_at DATETIME NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'deleted_by') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD deleted_by INT NULL;
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'regdate') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD regdate DATETIME NOT NULL CONSTRAINT DF_Tb_PhoneBook_regdate_patch DEFAULT(GETDATE());
    IF COL_LENGTH('dbo.Tb_PhoneBook', 'registered_date') IS NULL ALTER TABLE dbo.Tb_PhoneBook ADD registered_date DATETIME NOT NULL CONSTRAINT DF_Tb_PhoneBook_registered_date_patch DEFAULT(GETDATE());
END

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_PhoneBook')
      AND name = N'IX_Tb_PhoneBook_member_site_deleted'
)
BEGIN
    CREATE INDEX IX_Tb_PhoneBook_member_site_deleted
        ON dbo.Tb_PhoneBook(member_idx, site_idx, is_deleted, idx);
END

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_PhoneBook')
      AND name = N'IX_Tb_PhoneBook_hp_deleted'
)
BEGIN
    CREATE INDEX IX_Tb_PhoneBook_hp_deleted
        ON dbo.Tb_PhoneBook(hp, is_deleted, idx);
END

PRINT '=== Wave3 FieldManager/PhoneBook table bootstrap done ===';

SELECT
    'Tb_Users_fieldManager' AS table_name,
    COUNT(*) AS row_count
FROM dbo.Tb_Users_fieldManager
UNION ALL
SELECT
    'Tb_PhoneBook' AS table_name,
    COUNT(*) AS row_count
FROM dbo.Tb_PhoneBook;
