# SHVQ V2 Mail Phase 4 Smoke + Regression Report

- Date: 2026-04-14
- Scope: Phase 4 (Cron 최적화) + 통합 회귀 점검
- Owner: Codex

## 1) Pre-Check Baseline (변경 전)

기준 파일: `cron/saas/mail_sync_cron.php`, `dist_process/saas/Mail.php`

- Cron 기본 배치 크기: `50`
- Cron 대상 테이블: `Tb_Mail_Accounts` (V2 표준 계정 테이블 아님)
- 최근 로그인 24시간 필터: 없음
- Redis online 제외 필터: 없음
- `--dry-run` 옵션: 없음
- `mail_list` 진입 시 로그인 직후 자동 sync 트리거: 없음

## 2) Phase 4 Applied Changes

### Cron (`cron/saas/mail_sync_cron.php`)

- 기본 배치 `200`으로 상향 (`--limit` 기본값 200)
- 대상 테이블을 `Tb_IntProviderAccount` 기반으로 통일
- 최근 24시간 로그인 필터 추가
  - 우선: auth user table `last_login_at`
  - 대체: `Tb_IntProviderAccount.last_login_at`
  - 컬럼 미존재 시 필터 미적용(안전 폴백)
- Redis `mail:online:<userPk>` 스캔 후 online 사용자 제외
- `last_synced_at` 오래된 계정 우선 정렬
- `--dry-run` 옵션 추가 (대상 계정만 출력, 동기화 미실행)

### Mail API (`dist_process/saas/Mail.php`)

- `mail_list` 요청에서 로그인 직후 자동 sync 트리거 추가
  - 조건: 세션 `login_at` 기준 최근 20분 이내
  - 조건: 폴더 `INBOX`
  - 조건: `last_synced_at` 미존재 또는 5분 이상 경과
  - 실패 시 목록 조회는 계속 진행 (UX 보호)

## 3) Post-Change Smoke Checks (실행 결과)

### 3.1 Syntax/Build Checks

- `php -l cron/saas/mail_sync_cron.php` → PASS
- `php -l dist_process/saas/Mail.php` → PASS
- `node --check node/worker.js` → PASS
- `node --check node/fcm.js` → PASS

### 3.2 Static Feature Checks

- Cron 옵션/필터/정렬 반영 확인 (`rg`)
  - `--limit` 기본 200
  - `--dry-run`
  - `Tb_IntProviderAccount`
  - `last_login_at` 필터
  - `mail:online:*` 제외
  - `last_synced_at` 정렬
- Mail API 자동 sync 트리거 코드 존재 확인 (`rg`)
  - 로그인 직후 주석/로직
  - 20분 윈도우
  - 5분 stale 판정

### 3.3 Runtime Dry-Run

- 실행: `php cron/saas/mail_sync_cron.php --dry-run=1 --limit=10 --folder=INBOX`
- 결과: **BLOCKED (로컬 실행환경 제약)**
  - 오류: `SQLSTATE[08001] ... ODBC Driver 18 ... OpenSSL library could not be loaded`
  - 판단: 코드 이슈가 아니라 로컬 PHP SQLSRV/OpenSSL 런타임 이슈
  - 조치: 서버(실운영 런타임)에서 동일 커맨드로 재검증 필요

## 4) Regression Checklist (Phase 4 완료 후)

다음 항목을 서버에서 순차 검증하면 Phase 4 회귀를 마감할 수 있습니다.

1. `php cron/saas/mail_sync_cron.php --dry-run=1 --limit=20 --folder=INBOX`
2. dry-run 대상에 online 유저 계정이 제외되는지 확인
3. `php cron/saas/mail_sync_cron.php --limit=20 --folder=INBOX`
4. 동기화 로그에서 오래된 `last_synced_at` 계정 우선 처리 확인
5. 로그인 직후 메일 목록 진입 시 `mail_list` 1회 요청만으로 sync 갱신 확인
6. `mail_list` 응답 지연/오류 시 자동 sync 실패가 목록 조회를 막지 않는지 확인

## 5) Conclusion

- Phase 4 코드 반영은 완료되었고 정적/문법 회귀는 PASS.
- 런타임 dry-run은 로컬 SQLSRV(OpenSSL) 제약으로 BLOCKED.
- 서버 런타임에서 dry-run + 실동기화 2단계 검증을 마치면 Phase 4 회귀 종료 가능.
