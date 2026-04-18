SET NOCOUNT ON;
SET XACT_ABORT ON;

USE [CSM_C004732_V2];

PRINT '=== Wave3 FieldManager/PhoneBook data migration start ===';

IF OBJECT_ID(N'dbo.Tb_Users_fieldManager', N'U') IS NULL
BEGIN
    RAISERROR('Target table dbo.Tb_Users_fieldManager is missing. Run table bootstrap first.', 16, 1);
    RETURN;
END

IF OBJECT_ID(N'dbo.Tb_PhoneBook', N'U') IS NULL
BEGIN
    RAISERROR('Target table dbo.Tb_PhoneBook is missing. Run table bootstrap first.', 16, 1);
    RETURN;
END

DECLARE @sourceDb SYSNAME = N'CSM_C004732';

/* ------------------------------------------------------------------
   Tb_Users_fieldManager
------------------------------------------------------------------ */
DECLARE @fmSourceExists BIT = CASE
    WHEN OBJECT_ID(QUOTENAME(@sourceDb) + N'.dbo.Tb_Users_fieldManager', N'U') IS NULL THEN 0
    ELSE 1
END;

DECLARE @fmMerge TABLE (action_name NVARCHAR(10), idx INT);

IF (@fmSourceExists = 1)
BEGIN
    PRINT 'Migrate Tb_Users_fieldManager from V1';

    SET IDENTITY_INSERT dbo.Tb_Users_fieldManager ON;

    ;WITH src AS (
        SELECT
            CAST(v1.idx AS INT) AS idx,
            CAST(ISNULL(v1.id, '') AS NVARCHAR(50)) AS id,
            CAST(ISNULL(v1.name, '') AS NVARCHAR(120)) AS name,
            CAST(ISNULL(v1.passwd, '') AS NVARCHAR(255)) AS passwd,
            CAST(v1.member_idx AS INT) AS member_idx,
            CAST(ISNULL(v1.site_idx, 0) AS INT) AS site_idx,
            CAST(ISNULL(v1.sosok, '') AS NVARCHAR(120)) AS sosok,
            CAST(ISNULL(v1.part, '') AS NVARCHAR(120)) AS part,
            CAST(ISNULL(v1.hp, '') AS NVARCHAR(40)) AS hp,
            CAST(ISNULL(v1.email, '') AS NVARCHAR(200)) AS email,
            CAST(ISNULL(CAST(v1.comment AS NVARCHAR(MAX)), N'') AS NVARCHAR(MAX)) AS comment,
            CAST(v1.employee_idx AS INT) AS employee_idx,
            CAST(ISNULL(v1.regdate, GETDATE()) AS DATETIME) AS regdate
        FROM [CSM_C004732].dbo.Tb_Users_fieldManager v1
    )
    MERGE dbo.Tb_Users_fieldManager AS tgt
    USING src ON tgt.idx = src.idx
    WHEN MATCHED THEN UPDATE SET
        tgt.id = src.id,
        tgt.name = src.name,
        tgt.passwd = src.passwd,
        tgt.member_idx = src.member_idx,
        tgt.site_idx = src.site_idx,
        tgt.sosok = src.sosok,
        tgt.part = src.part,
        tgt.hp = src.hp,
        tgt.email = src.email,
        tgt.comment = src.comment,
        tgt.employee_idx = src.employee_idx,
        tgt.is_deleted = 0,
        tgt.updated_at = GETDATE(),
        tgt.updated_by = src.employee_idx,
        tgt.regdate = ISNULL(tgt.regdate, src.regdate),
        tgt.registered_date = ISNULL(tgt.registered_date, src.regdate)
    WHEN NOT MATCHED THEN
        INSERT (
            idx, id, name, passwd, member_idx, site_idx, sosok, part, hp, email, comment,
            employee_idx, is_deleted, created_at, created_by, updated_at, updated_by,
            deleted_at, deleted_by, regdate, registered_date
        )
        VALUES (
            src.idx, src.id, src.name, src.passwd, src.member_idx, src.site_idx, src.sosok, src.part, src.hp, src.email, src.comment,
            src.employee_idx, 0, src.regdate, src.employee_idx, GETDATE(), src.employee_idx,
            NULL, NULL, src.regdate, src.regdate
        )
    OUTPUT $action, inserted.idx INTO @fmMerge(action_name, idx);

    SET IDENTITY_INSERT dbo.Tb_Users_fieldManager OFF;

    DECLARE @fmMaxIdx INT = (SELECT ISNULL(MAX(idx), 0) FROM dbo.Tb_Users_fieldManager);
    DBCC CHECKIDENT ('dbo.Tb_Users_fieldManager', RESEED, @fmMaxIdx) WITH NO_INFOMSGS;
END
ELSE
BEGIN
    PRINT 'Skip Tb_Users_fieldManager migration: source table missing on V1';
END

/* ------------------------------------------------------------------
   Tb_PhoneBook
------------------------------------------------------------------ */
DECLARE @pbSourceExists BIT = CASE
    WHEN OBJECT_ID(QUOTENAME(@sourceDb) + N'.dbo.Tb_PhoneBook', N'U') IS NULL THEN 0
    ELSE 1
END;

DECLARE @pbMerge TABLE (action_name NVARCHAR(10), idx INT);

IF (@pbSourceExists = 1)
BEGIN
    PRINT 'Migrate Tb_PhoneBook from V1';

    SET IDENTITY_INSERT dbo.Tb_PhoneBook ON;

    ;WITH src AS (
        SELECT
            CAST(v1.idx AS INT) AS idx,
            CAST(ISNULL(v1.member_idx, 0) AS INT) AS member_idx,
            CAST(ISNULL(v1.site_idx, 0) AS INT) AS site_idx,
            CAST(ISNULL(v1.name, '') AS NVARCHAR(120)) AS name,
            CAST(ISNULL(v1.sosok, '') AS NVARCHAR(120)) AS sosok,
            CAST(ISNULL(v1.part, '') AS NVARCHAR(120)) AS part,
            CAST(ISNULL(v1.hp, '') AS NVARCHAR(40)) AS hp,
            CAST(ISNULL(v1.email, '') AS NVARCHAR(200)) AS email,
            CAST(ISNULL(CAST(v1.comment AS NVARCHAR(MAX)), N'') AS NVARCHAR(MAX)) AS comment,
            CAST(v1.employee_idx AS INT) AS employee_idx,
            CAST(ISNULL(v1.branch_folder_idx, 0) AS INT) AS branch_folder_idx,
            CAST(ISNULL(v1.work_status, N'') AS NVARCHAR(20)) AS work_status,
            CAST(ISNULL(v1.main_work, N'') AS NVARCHAR(200)) AS main_work,
            CAST(ISNULL(v1.history_log, N'') AS NVARCHAR(MAX)) AS history_log,
            CAST(ISNULL(v1.job_grade, N'') AS NVARCHAR(50)) AS job_grade,
            CAST(ISNULL(v1.job_title, N'') AS NVARCHAR(50)) AS job_title,
            CAST(ISNULL(v1.is_hidden, 0) AS INT) AS is_hidden,
            CAST(v1.company_idx AS INT) AS company_idx,
            CAST(ISNULL(v1.regdate, GETDATE()) AS DATETIME) AS regdate
        FROM [CSM_C004732].dbo.Tb_PhoneBook v1
    )
    MERGE dbo.Tb_PhoneBook AS tgt
    USING src ON tgt.idx = src.idx
    WHEN MATCHED THEN UPDATE SET
        tgt.member_idx = src.member_idx,
        tgt.site_idx = src.site_idx,
        tgt.name = src.name,
        tgt.sosok = src.sosok,
        tgt.part = src.part,
        tgt.hp = src.hp,
        tgt.email = src.email,
        tgt.comment = src.comment,
        tgt.employee_idx = src.employee_idx,
        tgt.branch_folder_idx = src.branch_folder_idx,
        tgt.work_status = src.work_status,
        tgt.main_work = src.main_work,
        tgt.history_log = src.history_log,
        tgt.job_grade = src.job_grade,
        tgt.job_title = src.job_title,
        tgt.is_hidden = src.is_hidden,
        tgt.company_idx = src.company_idx,
        tgt.is_deleted = 0,
        tgt.updated_at = GETDATE(),
        tgt.updated_by = src.employee_idx,
        tgt.regdate = ISNULL(tgt.regdate, src.regdate),
        tgt.registered_date = ISNULL(tgt.registered_date, src.regdate)
    WHEN NOT MATCHED THEN
        INSERT (
            idx, member_idx, site_idx, name, sosok, part, hp, email, comment,
            employee_idx, branch_folder_idx, work_status, main_work, history_log,
            job_grade, job_title, is_hidden, company_idx,
            is_deleted, created_at, created_by, updated_at, updated_by,
            deleted_at, deleted_by, regdate, registered_date
        )
        VALUES (
            src.idx, src.member_idx, src.site_idx, src.name, src.sosok, src.part, src.hp, src.email, src.comment,
            src.employee_idx, src.branch_folder_idx, src.work_status, src.main_work, src.history_log,
            src.job_grade, src.job_title, src.is_hidden, src.company_idx,
            0, src.regdate, src.employee_idx, GETDATE(), src.employee_idx,
            NULL, NULL, src.regdate, src.regdate
        )
    OUTPUT $action, inserted.idx INTO @pbMerge(action_name, idx);

    SET IDENTITY_INSERT dbo.Tb_PhoneBook OFF;

    DECLARE @pbMaxIdx INT = (SELECT ISNULL(MAX(idx), 0) FROM dbo.Tb_PhoneBook);
    DBCC CHECKIDENT ('dbo.Tb_PhoneBook', RESEED, @pbMaxIdx) WITH NO_INFOMSGS;
END
ELSE
BEGIN
    PRINT 'Skip Tb_PhoneBook migration: source table missing on V1';
END

/* ------------------------------------------------------------------
   Summary
------------------------------------------------------------------ */
DECLARE @pbUnresolvedMember INT = 0;
IF OBJECT_ID(N'dbo.Tb_Members', N'U') IS NOT NULL
BEGIN
    SELECT @pbUnresolvedMember = COUNT(*)
    FROM dbo.Tb_PhoneBook p
    LEFT JOIN dbo.Tb_Members m ON m.idx = p.member_idx
    WHERE ISNULL(p.member_idx, 0) > 0
      AND m.idx IS NULL;
END

SELECT
    (SELECT COUNT(*) FROM [CSM_C004732].dbo.Tb_Users_fieldManager) AS fm_source_total,
    (SELECT COUNT(*) FROM dbo.Tb_Users_fieldManager) AS fm_target_total,
    (SELECT COUNT(*) FROM @fmMerge WHERE action_name = 'INSERT') AS fm_inserted_count,
    (SELECT COUNT(*) FROM @fmMerge WHERE action_name = 'UPDATE') AS fm_updated_count,
    (SELECT COUNT(*) FROM [CSM_C004732].dbo.Tb_PhoneBook) AS pb_source_total,
    (SELECT COUNT(*) FROM dbo.Tb_PhoneBook) AS pb_target_total,
    (SELECT COUNT(*) FROM @pbMerge WHERE action_name = 'INSERT') AS pb_inserted_count,
    (SELECT COUNT(*) FROM @pbMerge WHERE action_name = 'UPDATE') AS pb_updated_count,
    @pbUnresolvedMember AS pb_unresolved_member_idx_count;

PRINT '=== Wave3 FieldManager/PhoneBook data migration done ===';
