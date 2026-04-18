# SHVQ_V2

SHVQ SaaS v2 신규 개발 루트 폴더입니다.

## 포함된 초기 구성
- `docs/saas/`: SaaS 아키텍처/메뉴 IA 설계서
- `scripts/db67/saas/`: 67번 개발DB 기준 SQL 초안
- `dist_process/saas/`: API 엔드포인트 영역(백엔드)
- `dist_library/saas/`: 도메인 서비스/어댑터 영역
- `cron/saas/`: 배치/동기화 작업 영역

## 라우팅 원칙
- `index.php?system=...&r=...` 쿼리 기반 라우팅 사용
