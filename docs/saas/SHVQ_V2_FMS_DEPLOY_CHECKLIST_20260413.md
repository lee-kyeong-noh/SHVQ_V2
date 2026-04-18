# SHVQ_V2 FMS 배포 체크리스트 (Wave1)

- 작성일: 2026-04-13
- 목적: FMS 백엔드 변경 배포 시 동시작업 덮어쓰기/스키마 불일치/운영 회귀를 최소화

## 1) 배포 대상 확정

- 변경 파일 목록 작성
- API/Service/문서/테스트 파일 분리
- `php -l` 문법 검증 완료

## 2) 서버 최신본 확인 (필수)

- 업로드 전 각 대상 파일에 대해 서버 파일의 크기/수정시각 확인
- 서버 파일이 더 최신이면:
  - 즉시 FTP 다운로드
  - 로컬 변경과 병합
  - 재검증 후 업로드

## 3) 로컬 검증

- `php -l` 대상 전체 통과
- 회귀 스모크 실행
  - `tests/AuthRegressionTest.php`
  - `tests/FmsRegressionTest.php`
- Shadow 관련 검증
  - `Platform.php?todo=shadow_wave1_matrix`
  - `ShadowAudit.php?todo=summary&days=7`

## 4) 업로드 절차

- FTP 업로드 명령(예시):

```bash
curl -s --ftp-pasv -u "vision_ftp:dlrudfh@019" -T {로컬파일} "ftp://211.116.112.67:21/SHVQ_V2/{경로}"
```

- 업로드 후 즉시 서버 재다운로드
- 로컬/서버 MD5 또는 diff 비교

## 5) 운영 확인

- 로그인 후 FMS 화면에서 API 호출 기본 확인
- 주요 todo 확인
  - Site: `list`, `insert_est`, `update_est`, `upsert_est_items`
  - HeadOffice: `list`, `insert`, `bulk_update`, `delete`
  - Member: `list`, `insert`, `member_inline_update`, `member_delete`
- 오류 로그 확인
  - `Tb_IntErrorQueue` 증가 여부
  - `Tb_SvcAuditLog` 적재 여부

## 6) 롤백 기준

- 5xx 급증, CSRF 오류 대량 발생, Shadow 큐 실패 누적 시 즉시 롤백
- 롤백 방법
  - 직전 서버 백업본 재업로드
  - 롤백 후 `ShadowAudit` 요약값 재확인

## 7) 개발일지 기록

- DevLog API에 배포 작업 기록
- 필수 값
  - `system_type=V2`
  - `category=FMS`
  - `title=FMS Wave1 backend deploy`
  - `content=변경 요약 + 검증/업로드 결과`
  - `file_count=실제 변경 파일 수`
