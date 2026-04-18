# SHVQ_V2 Shadow Queue 운영 메모 v1

- 작성일: 2026-04-12
- 목적: Wave 1 Shadow Write 실패 큐 운영 API/크론 사용 기준

---

## 1) 개요

1. 큐 테이블: `Tb_IntErrorQueue`
2. 필터 키: `provider='shadow'`, `job_type='shadow_write'`
3. 상태: `PENDING`, `RETRYING`, `FAILED`, `RESOLVED`

---

## 2) 운영 API (`dist_process/saas/Platform.php`)

1. 통계 조회
  - `todo=shadow_queue_stats`
  - 파라미터: `service_code`, `tenant_id`
2. 목록 조회
  - `todo=shadow_queue_list`
  - 파라미터: `page`, `limit`, `status`, `service_code`, `tenant_id`
3. 재큐(수동 재처리)
  - `todo=shadow_queue_requeue`
  - 파라미터: `idx`, `note`, `csrf_token`
4. 수동 해결처리
  - `todo=shadow_queue_resolve`
  - 파라미터: `idx`, `note`, `csrf_token`
5. 테스트 큐 삽입 (시스템관리자만)
  - `todo=shadow_queue_insert_test`
  - 파라미터: `csrf_token`

---

## 3) 크론 (`cron/saas/notify_dispatcher.php`)

1. 기본 동작
  - stale `RETRYING` 항목 자동 `PENDING` 복구
  - backlog 임계치 초과 감시 및 감사로그(`shadow.queue.alert`) 기록
2. 옵션
  - `--stale-minutes=20`
  - `--threshold=100`
  - `--older-than-minutes=10`
  - `--service-code=shvq`
  - `--tenant-id=1`
  - `--dry-run`
3. 예시
  - `php cron/saas/notify_dispatcher.php --dry-run`
  - `php cron/saas/notify_dispatcher.php --stale-minutes=15 --threshold=50`

---

## 4) 환경변수

1. `SHADOW_WRITE_ENABLED`
2. `SHADOW_QUEUE_TABLE`
3. `SHADOW_QUEUE_PROVIDER`
4. `SHADOW_QUEUE_JOB_TYPE`
5. `SHADOW_MAX_RETRY`
6. `SHADOW_RETRY_BACKOFF_BASE_MINUTES`
7. `SHADOW_RETRY_BACKOFF_MAX_MINUTES`
8. `SHADOW_MONITOR_STALE_RETRYING_MINUTES`
9. `SHADOW_MONITOR_BACKLOG_THRESHOLD`
10. `SHADOW_MONITOR_BACKLOG_OLDER_MINUTES`
11. `SHADOW_MIN_ROLE_LEVEL`
