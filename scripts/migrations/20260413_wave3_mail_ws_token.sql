/*
  SHVQ_V2 - Wave3 Mail WebSocket Token Table
  목적:
    Node.js IMAP IDLE Worker / 브라우저 WebSocket·SSE 연결 인증용 단기 토큰 테이블
    Tb_Mail_WsToken (token, user_pk, account_idx, service_code, tenant_id, expires_at)

  실행 DB: CSM_C004732_V2
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
USE [CSM_C004732_V2];
GO

IF OBJECT_ID(N'dbo.Tb_Mail_WsToken', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Tb_Mail_WsToken (
        token        NVARCHAR(64)  NOT NULL
            CONSTRAINT PK_Tb_Mail_WsToken PRIMARY KEY CLUSTERED,
        user_pk      INT           NOT NULL,
        account_idx  INT           NULL,
        service_code VARCHAR(30)   NOT NULL
            CONSTRAINT DF_Tb_Mail_WsToken_sc  DEFAULT ('shvq'),
        tenant_id    INT           NOT NULL
            CONSTRAINT DF_Tb_Mail_WsToken_tid DEFAULT (0),
        expires_at   DATETIME      NOT NULL,
        created_at   DATETIME      NOT NULL
            CONSTRAINT DF_Tb_Mail_WsToken_ca  DEFAULT (GETDATE())
    );
    PRINT N'[OK] Tb_Mail_WsToken 테이블 생성 완료.';
END
ELSE
BEGIN
    PRINT N'[SKIP] Tb_Mail_WsToken 이미 존재합니다.';
END;
GO

/* expires_at 인덱스 — 만료 토큰 정리 쿼리 성능 */
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_Mail_WsToken')
      AND name = N'IX_Tb_Mail_WsToken_Expires'
)
    CREATE INDEX IX_Tb_Mail_WsToken_Expires
    ON dbo.Tb_Mail_WsToken (expires_at);
GO

/* user_pk 인덱스 — 사용자별 토큰 조회 */
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.Tb_Mail_WsToken')
      AND name = N'IX_Tb_Mail_WsToken_UserPk'
)
    CREATE INDEX IX_Tb_Mail_WsToken_UserPk
    ON dbo.Tb_Mail_WsToken (user_pk, expires_at);
GO

PRINT N'[OK] Wave3 mail_ws_token 스키마 패치 완료.';
