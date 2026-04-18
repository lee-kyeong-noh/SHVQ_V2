/*
  SHVQ V2 — Wave2 Groupware Core Schema
  DB: CSM_C004732_V2
  Domain: 조직/HR/채팅/전자결재 (웹메일 제외)
*/
SET NOCOUNT ON;

/* -------------------------------------------------
   Department
------------------------------------------------- */
IF OBJECT_ID(N'dbo.Tb_GwDepartment', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_GwDepartment (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        service_code NVARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        parent_idx INT NULL,
        dept_code NVARCHAR(40) NULL,
        dept_name NVARCHAR(120) NOT NULL,
        dept_type NVARCHAR(40) NULL,
        address NVARCHAR(300) NULL,
        tel NVARCHAR(50) NULL,
        depth INT NOT NULL CONSTRAINT DF_Tb_GwDepartment_Depth DEFAULT (0),
        sort_order INT NOT NULL CONSTRAINT DF_Tb_GwDepartment_Sort DEFAULT (0),
        is_active BIT NOT NULL CONSTRAINT DF_Tb_GwDepartment_IsActive DEFAULT (1),
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_GwDepartment_IsDeleted DEFAULT (0),
        created_by INT NULL,
        updated_by INT NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwDepartment_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwDepartment_UpdatedAt DEFAULT (GETDATE())
    );

    CREATE INDEX IX_Tb_GwDepartment_ScopeParent
        ON dbo.Tb_GwDepartment(service_code, tenant_id, is_deleted, parent_idx, sort_order, idx);
END;

IF OBJECT_ID(N'dbo.Tb_GwDepartment', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.Tb_GwDepartment', 'dept_type') IS NULL
        ALTER TABLE dbo.Tb_GwDepartment ADD dept_type NVARCHAR(40) NULL;
    IF COL_LENGTH('dbo.Tb_GwDepartment', 'address') IS NULL
        ALTER TABLE dbo.Tb_GwDepartment ADD address NVARCHAR(300) NULL;
    IF COL_LENGTH('dbo.Tb_GwDepartment', 'tel') IS NULL
        ALTER TABLE dbo.Tb_GwDepartment ADD tel NVARCHAR(50) NULL;
    IF COL_LENGTH('dbo.Tb_GwDepartment', 'depth') IS NULL
        ALTER TABLE dbo.Tb_GwDepartment ADD depth INT NOT NULL CONSTRAINT DF_Tb_GwDepartment_Depth_A DEFAULT (0) WITH VALUES;
END;

/* -------------------------------------------------
   Employee
------------------------------------------------- */
IF OBJECT_ID(N'dbo.Tb_GwEmployee', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_GwEmployee (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        service_code NVARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        user_idx INT NULL,
        emp_no NVARCHAR(50) NULL,
        emp_name NVARCHAR(120) NOT NULL,
        dept_idx INT NULL,
        position_name NVARCHAR(80) NULL,
        job_title NVARCHAR(80) NULL,
        phone NVARCHAR(50) NULL,
        email NVARCHAR(150) NULL,
        tel NVARCHAR(50) NULL,
        personal_email NVARCHAR(150) NULL,
        address NVARCHAR(300) NULL,
        social_number NVARCHAR(50) NULL,
        employment_type NVARCHAR(50) NULL,
        work_gubun NVARCHAR(200) NULL,
        license NVARCHAR(200) NULL,
        career_note NVARCHAR(200) NULL,
        last_promotion DATE NULL,
        emp_memo NVARCHAR(1000) NULL,
        salary_basic INT NULL,
        salary_qualification INT NULL,
        salary_part_position INT NULL,
        salary_position INT NULL,
        salary_overtime_fix INT NULL,
        salary_work INT NULL,
        salary_meal INT NULL,
        salary_car INT NULL,
        salary_etc INT NULL,
        bank_name NVARCHAR(100) NULL,
        bank_depositor NVARCHAR(100) NULL,
        bank_account NVARCHAR(100) NULL,
        card_name NVARCHAR(100) NULL,
        card_number NVARCHAR(100) NULL,
        card_memo NVARCHAR(500) NULL,
        photo_url NVARCHAR(500) NULL,
        status NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_GwEmployee_Status DEFAULT ('ACTIVE'),
        hire_date DATE NULL,
        leave_date DATE NULL,
        is_hidden BIT NOT NULL CONSTRAINT DF_Tb_GwEmployee_IsHidden DEFAULT (0),
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_GwEmployee_IsDeleted DEFAULT (0),
        deleted_at DATETIME NULL,
        created_by INT NULL,
        updated_by INT NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwEmployee_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwEmployee_UpdatedAt DEFAULT (GETDATE())
    );

    CREATE INDEX IX_Tb_GwEmployee_ScopeDept
        ON dbo.Tb_GwEmployee(service_code, tenant_id, is_deleted, dept_idx, idx);

    CREATE INDEX IX_Tb_GwEmployee_User
        ON dbo.Tb_GwEmployee(service_code, tenant_id, user_idx, is_deleted, idx);
END;

IF OBJECT_ID(N'dbo.Tb_GwEmployee', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'photo_url') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD photo_url NVARCHAR(500) NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'tel') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD tel NVARCHAR(50) NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'personal_email') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD personal_email NVARCHAR(150) NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'address') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD address NVARCHAR(300) NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'social_number') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD social_number NVARCHAR(50) NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'employment_type') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD employment_type NVARCHAR(50) NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'work_gubun') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD work_gubun NVARCHAR(200) NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'license') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD license NVARCHAR(200) NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'career_note') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD career_note NVARCHAR(200) NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'last_promotion') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD last_promotion DATE NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'emp_memo') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD emp_memo NVARCHAR(1000) NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'salary_basic') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD salary_basic INT NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'salary_qualification') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD salary_qualification INT NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'salary_part_position') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD salary_part_position INT NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'salary_position') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD salary_position INT NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'salary_overtime_fix') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD salary_overtime_fix INT NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'salary_work') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD salary_work INT NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'salary_meal') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD salary_meal INT NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'salary_car') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD salary_car INT NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'salary_etc') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD salary_etc INT NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'bank_name') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD bank_name NVARCHAR(100) NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'bank_depositor') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD bank_depositor NVARCHAR(100) NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'bank_account') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD bank_account NVARCHAR(100) NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'card_name') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD card_name NVARCHAR(100) NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'card_number') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD card_number NVARCHAR(100) NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'card_memo') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD card_memo NVARCHAR(500) NULL;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'is_hidden') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD is_hidden BIT NOT NULL CONSTRAINT DF_Tb_GwEmployee_IsHidden DEFAULT (0) WITH VALUES;
    IF COL_LENGTH('dbo.Tb_GwEmployee', 'deleted_at') IS NULL
        ALTER TABLE dbo.Tb_GwEmployee ADD deleted_at DATETIME NULL;
END;

IF OBJECT_ID(N'dbo.Tb_GwEmployee', N'U') IS NOT NULL
BEGIN
    UPDATE dbo.Tb_GwEmployee
       SET status = 'RESIGNED',
           updated_at = GETDATE()
     WHERE UPPER(ISNULL(status, '')) = 'INACTIVE'
       AND ISNULL(is_deleted, 0) = 0;
END;

/* -------------------------------------------------
   Employee V1 -> V2 data backfill (emp_name match)
------------------------------------------------- */
IF OBJECT_ID(N'dbo.Tb_GwEmployee', N'U') IS NOT NULL
   AND OBJECT_ID(N'dbo.Tb_Employee', N'U') IS NOT NULL
BEGIN
    DECLARE @setList NVARCHAR(MAX) = N'';
    DECLARE @sql NVARCHAR(MAX);
    DECLARE @srcNameExpr NVARCHAR(300) = N'';

    IF COL_LENGTH('dbo.Tb_Employee', 'emp_name') IS NOT NULL
        SET @srcNameExpr = N'LTRIM(RTRIM(CONVERT(NVARCHAR(400), ISNULL(src.emp_name, N''''))))';
    ELSE IF COL_LENGTH('dbo.Tb_Employee', 'name') IS NOT NULL
        SET @srcNameExpr = N'LTRIM(RTRIM(CONVERT(NVARCHAR(400), ISNULL(src.name, N''''))))';

    IF COL_LENGTH('dbo.Tb_Employee', 'tel') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'tel') IS NOT NULL
        SET @setList += N', tgt.tel = COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(200), src.tel))), N''''), tgt.tel)';
    IF COL_LENGTH('dbo.Tb_Employee', 'personal_email') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'personal_email') IS NOT NULL
        SET @setList += N', tgt.personal_email = COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(320), src.personal_email))), N''''), tgt.personal_email)';
    IF COL_LENGTH('dbo.Tb_Employee', 'address') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'address') IS NOT NULL
        SET @setList += N', tgt.address = COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(1000), src.address))), N''''), tgt.address)';
    IF COL_LENGTH('dbo.Tb_Employee', 'social_number') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'social_number') IS NOT NULL
        SET @setList += N', tgt.social_number = COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(80), src.social_number))), N''''), tgt.social_number)';
    IF COL_LENGTH('dbo.Tb_Employee', 'employment_type') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'employment_type') IS NOT NULL
        SET @setList += N', tgt.employment_type = COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(120), src.employment_type))), N''''), tgt.employment_type)';
    IF COL_LENGTH('dbo.Tb_Employee', 'work_gubun') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'work_gubun') IS NOT NULL
        SET @setList += N', tgt.work_gubun = COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(200), src.work_gubun))), N''''), tgt.work_gubun)';
    IF COL_LENGTH('dbo.Tb_Employee', 'license') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'license') IS NOT NULL
        SET @setList += N', tgt.license = COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(300), src.license))), N''''), tgt.license)';
    IF COL_LENGTH('dbo.Tb_Employee', 'career_note') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'career_note') IS NOT NULL
        SET @setList += N', tgt.career_note = COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(300), src.career_note))), N''''), tgt.career_note)';
    IF COL_LENGTH('dbo.Tb_Employee', 'last_promotion') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'last_promotion') IS NOT NULL
        SET @setList += N', tgt.last_promotion = COALESCE(TRY_CONVERT(DATE, src.last_promotion), tgt.last_promotion)';
    IF COL_LENGTH('dbo.Tb_Employee', 'emp_memo') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'emp_memo') IS NOT NULL
        SET @setList += N', tgt.emp_memo = COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(MAX), src.emp_memo))), N''''), tgt.emp_memo)';

    IF COL_LENGTH('dbo.Tb_Employee', 'salary_basic') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'salary_basic') IS NOT NULL
        SET @setList += N', tgt.salary_basic = COALESCE(TRY_CONVERT(INT, src.salary_basic), tgt.salary_basic)';
    IF COL_LENGTH('dbo.Tb_Employee', 'salary_qualification') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'salary_qualification') IS NOT NULL
        SET @setList += N', tgt.salary_qualification = COALESCE(TRY_CONVERT(INT, src.salary_qualification), tgt.salary_qualification)';
    IF COL_LENGTH('dbo.Tb_Employee', 'salary_part_position') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'salary_part_position') IS NOT NULL
        SET @setList += N', tgt.salary_part_position = COALESCE(TRY_CONVERT(INT, src.salary_part_position), tgt.salary_part_position)';
    IF COL_LENGTH('dbo.Tb_Employee', 'salary_position') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'salary_position') IS NOT NULL
        SET @setList += N', tgt.salary_position = COALESCE(TRY_CONVERT(INT, src.salary_position), tgt.salary_position)';
    IF COL_LENGTH('dbo.Tb_Employee', 'salary_overtime_fix') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'salary_overtime_fix') IS NOT NULL
        SET @setList += N', tgt.salary_overtime_fix = COALESCE(TRY_CONVERT(INT, src.salary_overtime_fix), tgt.salary_overtime_fix)';
    IF COL_LENGTH('dbo.Tb_Employee', 'salary_work') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'salary_work') IS NOT NULL
        SET @setList += N', tgt.salary_work = COALESCE(TRY_CONVERT(INT, src.salary_work), tgt.salary_work)';
    IF COL_LENGTH('dbo.Tb_Employee', 'salary_meal') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'salary_meal') IS NOT NULL
        SET @setList += N', tgt.salary_meal = COALESCE(TRY_CONVERT(INT, src.salary_meal), tgt.salary_meal)';
    IF COL_LENGTH('dbo.Tb_Employee', 'salary_car') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'salary_car') IS NOT NULL
        SET @setList += N', tgt.salary_car = COALESCE(TRY_CONVERT(INT, src.salary_car), tgt.salary_car)';
    IF COL_LENGTH('dbo.Tb_Employee', 'salary_etc') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'salary_etc') IS NOT NULL
        SET @setList += N', tgt.salary_etc = COALESCE(TRY_CONVERT(INT, src.salary_etc), tgt.salary_etc)';

    IF COL_LENGTH('dbo.Tb_Employee', 'bank_name') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'bank_name') IS NOT NULL
        SET @setList += N', tgt.bank_name = COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(200), src.bank_name))), N''''), tgt.bank_name)';
    IF COL_LENGTH('dbo.Tb_Employee', 'bank_depositor') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'bank_depositor') IS NOT NULL
        SET @setList += N', tgt.bank_depositor = COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(200), src.bank_depositor))), N''''), tgt.bank_depositor)';
    IF COL_LENGTH('dbo.Tb_Employee', 'bank_account') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'bank_account') IS NOT NULL
        SET @setList += N', tgt.bank_account = COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(200), src.bank_account))), N''''), tgt.bank_account)';
    IF COL_LENGTH('dbo.Tb_Employee', 'card_name') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'card_name') IS NOT NULL
        SET @setList += N', tgt.card_name = COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(200), src.card_name))), N''''), tgt.card_name)';
    IF COL_LENGTH('dbo.Tb_Employee', 'card_number') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'card_number') IS NOT NULL
        SET @setList += N', tgt.card_number = COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(200), src.card_number))), N''''), tgt.card_number)';
    IF COL_LENGTH('dbo.Tb_Employee', 'card_memo') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'card_memo') IS NOT NULL
        SET @setList += N', tgt.card_memo = COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(MAX), src.card_memo))), N''''), tgt.card_memo)';
    IF COL_LENGTH('dbo.Tb_Employee', 'is_hidden') IS NOT NULL AND COL_LENGTH('dbo.Tb_GwEmployee', 'is_hidden') IS NOT NULL
        SET @setList += N', tgt.is_hidden = COALESCE(TRY_CONVERT(BIT, src.is_hidden), tgt.is_hidden)';

    IF @setList <> N'' AND @srcNameExpr <> N''
    BEGIN
        SET @sql = N'
            UPDATE tgt
               SET ' + STUFF(@setList, 1, 2, N'') + N'
            FROM dbo.Tb_GwEmployee tgt
            INNER JOIN dbo.Tb_Employee src
              ON ' + @srcNameExpr + N' = LTRIM(RTRIM(ISNULL(tgt.emp_name, N'''')))
            WHERE ISNULL(tgt.is_deleted, 0) = 0
              AND LTRIM(RTRIM(ISNULL(tgt.emp_name, N''''))) <> N'''';';
        EXEC sp_executesql @sql;
    END
END;

/* -------------------------------------------------
   Employee Card
------------------------------------------------- */
IF OBJECT_ID(N'dbo.Tb_EmployeeCard', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_EmployeeCard (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        service_code NVARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        employee_idx INT NOT NULL,
        card_usage NVARCHAR(120) NULL,
        card_name NVARCHAR(120) NULL,
        card_number NVARCHAR(120) NULL,
        card_memo NVARCHAR(1000) NULL,
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_EmployeeCard_IsDeleted DEFAULT (0),
        created_by INT NULL,
        updated_by INT NULL,
        deleted_by INT NULL,
        deleted_at DATETIME NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_EmployeeCard_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_EmployeeCard_UpdatedAt DEFAULT (GETDATE())
    );

    CREATE INDEX IX_Tb_EmployeeCard_ScopeEmployee
        ON dbo.Tb_EmployeeCard(service_code, tenant_id, is_deleted, employee_idx, idx);

    CREATE INDEX IX_Tb_EmployeeCard_ScopeSearch
        ON dbo.Tb_EmployeeCard(service_code, tenant_id, is_deleted, card_name, card_number, idx);
END;

IF OBJECT_ID(N'dbo.Tb_EmployeeCard', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'card_usage') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD card_usage NVARCHAR(120) NULL;
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'card_name') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD card_name NVARCHAR(120) NULL;
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'card_number') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD card_number NVARCHAR(120) NULL;
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'card_memo') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD card_memo NVARCHAR(1000) NULL;
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'is_deleted') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_EmployeeCard_IsDeleted_A DEFAULT (0) WITH VALUES;
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'created_by') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD created_by INT NULL;
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'updated_by') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD updated_by INT NULL;
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'deleted_by') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD deleted_by INT NULL;
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'deleted_at') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD deleted_at DATETIME NULL;
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'created_at') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_EmployeeCard_CreatedAt_A DEFAULT (GETDATE()) WITH VALUES;
    IF COL_LENGTH('dbo.Tb_EmployeeCard', 'updated_at') IS NULL
        ALTER TABLE dbo.Tb_EmployeeCard ADD updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_EmployeeCard_UpdatedAt_A DEFAULT (GETDATE()) WITH VALUES;
END;

IF OBJECT_ID(N'dbo.Tb_EmployeeCard', N'U') IS NOT NULL
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_EmployeeCard')
          AND name = N'IX_Tb_EmployeeCard_ScopeEmployee'
    )
    BEGIN
        CREATE INDEX IX_Tb_EmployeeCard_ScopeEmployee
            ON dbo.Tb_EmployeeCard(service_code, tenant_id, is_deleted, employee_idx, idx);
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_EmployeeCard')
          AND name = N'IX_Tb_EmployeeCard_ScopeSearch'
    )
    BEGIN
        CREATE INDEX IX_Tb_EmployeeCard_ScopeSearch
            ON dbo.Tb_EmployeeCard(service_code, tenant_id, is_deleted, card_name, card_number, idx);
    END;
END;

/* -------------------------------------------------
   Settings
------------------------------------------------- */
IF OBJECT_ID(N'dbo.Tb_GwSettings', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_GwSettings (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        service_code NVARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        setting_group NVARCHAR(60) NOT NULL CONSTRAINT DF_Tb_GwSettings_Group DEFAULT ('groupware'),
        setting_key NVARCHAR(100) NOT NULL,
        setting_value NVARCHAR(MAX) NULL,
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_GwSettings_IsDeleted DEFAULT (0),
        created_by INT NULL,
        updated_by INT NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwSettings_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwSettings_UpdatedAt DEFAULT (GETDATE())
    );
END;

IF OBJECT_ID(N'dbo.Tb_GwSettings', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.Tb_GwSettings', 'setting_group') IS NULL
        ALTER TABLE dbo.Tb_GwSettings ADD setting_group NVARCHAR(60) NOT NULL CONSTRAINT DF_Tb_GwSettings_Group_A DEFAULT ('groupware') WITH VALUES;
    IF COL_LENGTH('dbo.Tb_GwSettings', 'setting_key') IS NULL
        ALTER TABLE dbo.Tb_GwSettings ADD setting_key NVARCHAR(100) NOT NULL CONSTRAINT DF_Tb_GwSettings_Key_A DEFAULT ('') WITH VALUES;
    IF COL_LENGTH('dbo.Tb_GwSettings', 'setting_value') IS NULL
        ALTER TABLE dbo.Tb_GwSettings ADD setting_value NVARCHAR(MAX) NULL;
    IF COL_LENGTH('dbo.Tb_GwSettings', 'is_deleted') IS NULL
        ALTER TABLE dbo.Tb_GwSettings ADD is_deleted BIT NOT NULL CONSTRAINT DF_Tb_GwSettings_IsDeleted_A DEFAULT (0) WITH VALUES;
    IF COL_LENGTH('dbo.Tb_GwSettings', 'created_by') IS NULL
        ALTER TABLE dbo.Tb_GwSettings ADD created_by INT NULL;
    IF COL_LENGTH('dbo.Tb_GwSettings', 'updated_by') IS NULL
        ALTER TABLE dbo.Tb_GwSettings ADD updated_by INT NULL;
    IF COL_LENGTH('dbo.Tb_GwSettings', 'created_at') IS NULL
        ALTER TABLE dbo.Tb_GwSettings ADD created_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwSettings_CreatedAt_A DEFAULT (GETDATE()) WITH VALUES;
    IF COL_LENGTH('dbo.Tb_GwSettings', 'updated_at') IS NULL
        ALTER TABLE dbo.Tb_GwSettings ADD updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwSettings_UpdatedAt_A DEFAULT (GETDATE()) WITH VALUES;
END;

IF OBJECT_ID(N'dbo.Tb_GwSettings', N'U') IS NOT NULL
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_GwSettings')
          AND name = N'UX_Tb_GwSettings_ScopeKey'
    )
    BEGIN
        CREATE UNIQUE INDEX UX_Tb_GwSettings_ScopeKey
            ON dbo.Tb_GwSettings(service_code, tenant_id, setting_group, setting_key);
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.Tb_GwSettings')
          AND name = N'IX_Tb_GwSettings_Scope'
    )
    BEGIN
        CREATE INDEX IX_Tb_GwSettings_Scope
            ON dbo.Tb_GwSettings(service_code, tenant_id, setting_group, is_deleted, idx);
    END;
END;

/* -------------------------------------------------
   PhoneBook
------------------------------------------------- */
IF OBJECT_ID(N'dbo.Tb_GwPhoneBook', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_GwPhoneBook (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        service_code NVARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        employee_idx INT NULL,
        contact_name NVARCHAR(120) NOT NULL,
        company_name NVARCHAR(120) NULL,
        department_name NVARCHAR(120) NULL,
        position_name NVARCHAR(80) NULL,
        phone NVARCHAR(50) NULL,
        email NVARCHAR(150) NULL,
        memo NVARCHAR(500) NULL,
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_GwPhoneBook_IsDeleted DEFAULT (0),
        created_by INT NULL,
        updated_by INT NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwPhoneBook_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwPhoneBook_UpdatedAt DEFAULT (GETDATE())
    );

    CREATE INDEX IX_Tb_GwPhoneBook_Scope
        ON dbo.Tb_GwPhoneBook(service_code, tenant_id, is_deleted, idx);
END;

/* -------------------------------------------------
   Attendance
------------------------------------------------- */
IF OBJECT_ID(N'dbo.Tb_GwAttendance', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_GwAttendance (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        service_code NVARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        employee_idx INT NOT NULL,
        work_date DATE NOT NULL,
        check_in DATETIME NULL,
        check_out DATETIME NULL,
        work_minutes INT NOT NULL CONSTRAINT DF_Tb_GwAttendance_WorkMinutes DEFAULT (0),
        status NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_GwAttendance_Status DEFAULT ('NORMAL'),
        note NVARCHAR(500) NULL,
        created_by INT NULL,
        updated_by INT NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwAttendance_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwAttendance_UpdatedAt DEFAULT (GETDATE())
    );

    CREATE UNIQUE INDEX UX_Tb_GwAttendance_WorkDate
        ON dbo.Tb_GwAttendance(service_code, tenant_id, employee_idx, work_date);
END;

/* -------------------------------------------------
   Holiday
------------------------------------------------- */
IF OBJECT_ID(N'dbo.Tb_GwHoliday', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_GwHoliday (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        service_code NVARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        employee_idx INT NOT NULL,
        holiday_type NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_GwHoliday_Type DEFAULT ('ANNUAL'),
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        reason NVARCHAR(1000) NULL,
        status NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_GwHoliday_Status DEFAULT ('REQUESTED'),
        approved_by INT NULL,
        approved_at DATETIME NULL,
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_GwHoliday_IsDeleted DEFAULT (0),
        created_by INT NULL,
        updated_by INT NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwHoliday_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwHoliday_UpdatedAt DEFAULT (GETDATE())
    );

    CREATE INDEX IX_Tb_GwHoliday_ScopeStatus
        ON dbo.Tb_GwHoliday(service_code, tenant_id, is_deleted, status, start_date, idx);
END;

/* -------------------------------------------------
   Overtime
------------------------------------------------- */
IF OBJECT_ID(N'dbo.Tb_GwOvertime', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_GwOvertime (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        service_code NVARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        employee_idx INT NOT NULL,
        work_date DATE NOT NULL,
        start_time DATETIME NULL,
        end_time DATETIME NULL,
        minutes INT NOT NULL CONSTRAINT DF_Tb_GwOvertime_Minutes DEFAULT (0),
        reason NVARCHAR(1000) NULL,
        status NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_GwOvertime_Status DEFAULT ('REQUESTED'),
        approved_by INT NULL,
        approved_at DATETIME NULL,
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_GwOvertime_IsDeleted DEFAULT (0),
        created_by INT NULL,
        updated_by INT NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwOvertime_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwOvertime_UpdatedAt DEFAULT (GETDATE())
    );

    CREATE INDEX IX_Tb_GwOvertime_ScopeStatus
        ON dbo.Tb_GwOvertime(service_code, tenant_id, is_deleted, status, work_date, idx);
END;

/* -------------------------------------------------
   Chat
------------------------------------------------- */
IF OBJECT_ID(N'dbo.Tb_GwChatRoom', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_GwChatRoom (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        service_code NVARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        room_type NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_GwChatRoom_Type DEFAULT ('GROUP'),
        room_name NVARCHAR(150) NOT NULL,
        created_by INT NULL,
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_GwChatRoom_IsDeleted DEFAULT (0),
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwChatRoom_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwChatRoom_UpdatedAt DEFAULT (GETDATE()),
        last_message_at DATETIME NULL
    );

    CREATE INDEX IX_Tb_GwChatRoom_Scope
        ON dbo.Tb_GwChatRoom(service_code, tenant_id, is_deleted, last_message_at, idx);
END;

IF OBJECT_ID(N'dbo.Tb_GwChatRoomMember', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_GwChatRoomMember (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        room_idx INT NOT NULL,
        user_idx INT NOT NULL,
        member_role NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_GwChatRoomMember_Role DEFAULT ('MEMBER'),
        joined_at DATETIME NULL,
        left_at DATETIME NULL,
        last_read_message_idx INT NOT NULL CONSTRAINT DF_Tb_GwChatRoomMember_LastRead DEFAULT (0),
        is_muted BIT NOT NULL CONSTRAINT DF_Tb_GwChatRoomMember_IsMuted DEFAULT (0)
    );

    CREATE UNIQUE INDEX UX_Tb_GwChatRoomMember_RoomUser
        ON dbo.Tb_GwChatRoomMember(room_idx, user_idx);

    CREATE INDEX IX_Tb_GwChatRoomMember_User
        ON dbo.Tb_GwChatRoomMember(user_idx, left_at, room_idx);
END;

IF OBJECT_ID(N'dbo.Tb_GwChatMessage', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_GwChatMessage (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        room_idx INT NOT NULL,
        service_code NVARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        sender_user_idx INT NULL,
        message_type NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_GwChatMessage_Type DEFAULT ('TEXT'),
        message_text NVARCHAR(MAX) NULL,
        payload_json NVARCHAR(MAX) NULL,
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_GwChatMessage_IsDeleted DEFAULT (0),
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwChatMessage_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwChatMessage_UpdatedAt DEFAULT (GETDATE())
    );

    CREATE INDEX IX_Tb_GwChatMessage_Room
        ON dbo.Tb_GwChatMessage(room_idx, idx, is_deleted);

    CREATE INDEX IX_Tb_GwChatMessage_Scope
        ON dbo.Tb_GwChatMessage(service_code, tenant_id, room_idx, idx);
END;

/* -------------------------------------------------
   Approval
------------------------------------------------- */
IF OBJECT_ID(N'dbo.Tb_GwApprovalDoc', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_GwApprovalDoc (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        service_code NVARCHAR(30) NOT NULL,
        tenant_id INT NOT NULL,
        doc_no NVARCHAR(40) NOT NULL,
        doc_type NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_GwApprovalDoc_Type DEFAULT ('GENERAL'),
        title NVARCHAR(200) NOT NULL,
        body_text NVARCHAR(MAX) NULL,
        writer_user_idx INT NOT NULL,
        status NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_GwApprovalDoc_Status DEFAULT ('DRAFT'),
        current_line_order INT NULL,
        submitted_at DATETIME NULL,
        completed_at DATETIME NULL,
        is_deleted BIT NOT NULL CONSTRAINT DF_Tb_GwApprovalDoc_IsDeleted DEFAULT (0),
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwApprovalDoc_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwApprovalDoc_UpdatedAt DEFAULT (GETDATE())
    );

    CREATE UNIQUE INDEX UX_Tb_GwApprovalDoc_DocNo
        ON dbo.Tb_GwApprovalDoc(service_code, tenant_id, doc_no);

    CREATE INDEX IX_Tb_GwApprovalDoc_ScopeStatus
        ON dbo.Tb_GwApprovalDoc(service_code, tenant_id, is_deleted, status, updated_at, idx);
END;

IF OBJECT_ID(N'dbo.Tb_GwApprovalLine', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_GwApprovalLine (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        doc_idx INT NOT NULL,
        line_order INT NOT NULL,
        approver_user_idx INT NOT NULL,
        decision_status NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_GwApprovalLine_Status DEFAULT ('PENDING'),
        decided_at DATETIME NULL,
        comment_text NVARCHAR(1000) NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwApprovalLine_CreatedAt DEFAULT (GETDATE()),
        updated_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwApprovalLine_UpdatedAt DEFAULT (GETDATE())
    );

    CREATE INDEX IX_Tb_GwApprovalLine_Doc
        ON dbo.Tb_GwApprovalLine(doc_idx, line_order, decision_status, idx);

    CREATE INDEX IX_Tb_GwApprovalLine_Approver
        ON dbo.Tb_GwApprovalLine(approver_user_idx, decision_status, doc_idx);
END;

IF OBJECT_ID(N'dbo.Tb_GwApprovalComment', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_GwApprovalComment (
        idx INT IDENTITY(1,1) PRIMARY KEY,
        doc_idx INT NOT NULL,
        user_idx INT NOT NULL,
        comment_type NVARCHAR(20) NOT NULL CONSTRAINT DF_Tb_GwApprovalComment_Type DEFAULT ('COMMENT'),
        comment_text NVARCHAR(2000) NOT NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Tb_GwApprovalComment_CreatedAt DEFAULT (GETDATE())
    );

    CREATE INDEX IX_Tb_GwApprovalComment_Doc
        ON dbo.Tb_GwApprovalComment(doc_idx, idx);
END;

PRINT N'[OK] 20260413_wave2_groupware_core.sql applied';
