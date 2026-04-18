# SHVQ V2 Mail 운영 배포 체크리스트 (Phase 1~4 통합)

- Date: 2026-04-14
- 대상: Mail Worker + Mail API + Cron 최적화
- 목적: 무중단/저위험 배포 + 신속 롤백

## 1) 배포 전 준비

1. SQL 마이그레이션 파일 확인
   - `scripts/migrations/20260414_wave5_mail_fcm_realtime.sql`
2. Node 의존성 확인
   - `node/package.json`에 `firebase-admin` 포함
3. FCM 서비스계정 확인
   - `FCM_SERVICE_ACCOUNT_JSON` 또는 `FCM_SERVICE_ACCOUNT_PATH`
4. PM2 설정 확인
   - `instances=2`, `exec_mode=cluster`, `max_memory_restart=4G`
5. Cron 커맨드 확정
   - `php cron/saas/mail_sync_cron.php --folder=INBOX --limit=200`

## 2) 배포 순서 (권장)

1. DB 마이그레이션 적용
2. Node 의존성 설치 (`node` 디렉터리)
   - `npm install --production`
3. Worker 재시작
   - `pm2 restart shv-v2-mail-worker`
4. Worker 상태 확인
   - `pm2 status`
   - `pm2 logs shv-v2-mail-worker --lines 200`
5. Cron dry-run 검증
   - `php cron/saas/mail_sync_cron.php --dry-run=1 --limit=20 --folder=INBOX`
6. Cron 실동기화 검증
   - `php cron/saas/mail_sync_cron.php --limit=20 --folder=INBOX`
7. Task Scheduler/Cron 등록값을 `--limit=200`으로 반영

## 3) 배포 후 헬스체크

1. Worker `/status` 확인
   - `fetch_queue`, `fetch_workers`, `fcm_pending` 값 확인
2. 온라인/오프라인 알림 분기 확인
   - 메일 탭 활성: SSE/WS 수신
   - 메일 탭 비활성: FCM 수신
3. 로그인 직후 자동 sync 확인
   - 로그인 후 INBOX 첫 진입 시 stale 조건(5분)에서 sync 트리거
4. DB 확인
   - `Tb_Mail_FcmToken` upsert/삭제 동작
   - `Tb_Mail_MessageCache.fcm_notified` 업데이트 동작

## 4) 장애 대응

### 4.1 증상별 1차 조치

- Worker 비정상 종료 반복
  1. `pm2 logs`에서 스택트레이스 확인
  2. FCM 자격증명 env 값 검증
  3. Redis/DB 연결 상태 확인
- FCM 미수신
  1. `Tb_Mail_FcmToken` 토큰 존재 여부 확인
  2. invalid token 자동삭제 로그 확인
  3. `fcm_notified=0` 유지 후 cron fallback 동작 확인
- Cron 부하 과다
  1. `--limit` 일시 하향(예: 80)
  2. 실행 간격 조정

### 4.2 롤백 순서

1. PM2 worker를 이전 빌드(이전 `worker.js` + `ecosystem.config.js`)로 복귀
2. `Mail.php`를 이전 버전으로 복귀
3. Cron 스크립트를 이전 버전으로 복귀
4. DB 스키마는 유지 (호환 컬럼/테이블은 backward-safe)

## 5) 최종 승인 기준

1. Worker 30분 이상 안정(재시작 루프 없음)
2. Cron dry-run/실행 정상
3. 로그인 직후 자동 sync 확인
4. 온라인 제외 + 24h 로그인 필터 적용 확인
5. FCM/SSE 분기 정상 확인
