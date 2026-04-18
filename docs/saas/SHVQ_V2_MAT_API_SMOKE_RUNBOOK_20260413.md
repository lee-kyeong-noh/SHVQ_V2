# SHVQ V2 MAT API 스모크 런북

- 작성일: 2026-04-13
- 대상 API: `dist_process/saas/Material.php`, `dist_process/saas/MaterialSettings.php`, `dist_process/saas/Stock.php`
- 목적: MAT 백엔드 핵심 경로(설정 저장, 품목 CRUD, 재고이력 다중타입 조회) 즉시 검증

## 1) 실행 전제

1. 기본은 세션 쿠키 방식 검증
2. 쿠키가 없으면 로그인 계정(`login_id/password`)을 스크립트 인자로 전달
3. 쓰기 API(`create/update/delete`, `settings save`)는 `role_level >= 2` 권한 필요

## 2) 실행 방법

```bash
# 쿠키 세션이 이미 있을 때
bash scripts/smoke_test_mat.sh "https://shvq.kr/SHVQ_V2" /tmp/shvq_mat.cookie

# 로그인까지 포함해서 실행할 때
bash scripts/smoke_test_mat.sh "https://shvq.kr/SHVQ_V2" /tmp/shvq_mat.cookie "admin" "password"
```

## 3) 스모크 시퀀스

| 순서 | API | todo | 방식 | 성공 기준 |
|---|---|---|---|---|
| 0 | `Auth.php` | `csrf` | GET | `ok=true`, `data.csrf_token` 존재 |
| 1 | `Auth.php` | `login` | POST(옵션) | `ok=true` |
| 2 | `MaterialSettings.php` | `get` | GET | `data.settings` 존재 |
| 3 | `MaterialSettings.php` | `save` | POST | `ok=true` |
| 4 | `MaterialSettings.php` | `save_pjt_items` | POST | `ok=true` |
| 5 | `MaterialSettings.php` | `save_category_option_labels` | POST | `ok=true` |
| 6 | `Material.php` | `create` | POST | `ok=true`, `data.idx` 생성 |
| 7 | `Material.php` | `detail` | GET | `ok=true`, 생성 idx 조회 |
| 8 | `Material.php` | `update` | POST | `ok=true` |
| 9 | `Material.php` | `list` | GET | `ok=true`, `data.total >= 1` |
| 10 | `Stock.php` | `stock_log` + `stock_type_in=1,4` | GET | `ok=true` |
| 11 | `Material.php` | `delete` | POST | `ok=true` |
| 12 | `Material.php` | `detail`(삭제후) | GET | `ok=false` (NOT_FOUND) |

## 4) 파라미터 기준

### 4.1 Material API

- 목록: `todo=list`, `search`, `tab_idx`, `category_idx`, `p`, `limit`
- 상세: `todo=detail`, `idx`
- 등록: `todo=create`, `name`(필수), `item_code`, `standard`, `unit`, `inventory_management`, `safety_count`, `base_count`, `csrf_token`
- 수정: `todo=update`, `idx`(필수), 수정 필드 + `csrf_token`
- 삭제: `todo=delete`, `idx_list`(comma 가능) + `csrf_token`

### 4.2 MaterialSettings API

- 조회: `todo=get`
- 기본 저장: `todo=save`,
  `material_no_prefix`, `material_no_format`, `material_no_seq_len`,
  `barcode_format`, `barcode_cat_len`, `barcode_seq_len`, `item_category_max_depth`, `csrf_token`
- PJT 항목: `todo=save_pjt_items`, `pjt_items`(JSON 문자열), `csrf_token`
- 카테고리 옵션라벨: `todo=save_category_option_labels`, `category_option_labels`(JSON 문자열), `csrf_token`

### 4.3 Stock API

- 다중 타입 조회: `todo=stock_log`, `stock_type_in=1,4`
- 호환 키: `stock_type_list`, `stock_types`도 동일 의미로 허용

## 5) 실패 대응 체크리스트

1. `AUTH_REQUIRED`: 쿠키 만료 또는 로그인 미실행
2. `CSRF_INVALID`: 토큰 재발급 후 재시도
3. `FORBIDDEN`: 계정 권한(`role_level`) 확인
4. `INVALID_PARAM`: `idx`, `idx_list`, JSON 문자열 형식 점검
5. `SERVER_ERROR`: 서버 `error_log`에서 `MaterialService`, `MaterialSettingsService`, `StockService` 로그 확인

## 6) 결과 해석

- 최종 출력이 `[ALL PASS]`면 MAT 핵심 API 체인이 정상
- `[SOME FAILED]`면 FAIL 항목의 `status | message` 기준으로 즉시 원인 분류 가능
