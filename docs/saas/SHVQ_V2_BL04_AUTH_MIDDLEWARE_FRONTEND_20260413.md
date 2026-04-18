# BL-04 인증·권한 미들웨어 점검 — 프론트엔드 사이드 분석

- 작성일: 2026-04-13
- 범위: 프론트엔드 CSRF·인증 패턴 (`js/core/api.js`, `js/core/csrf.js`, `views/saas/mail/*.php`)
- 담당: Claude (프론트 검토)

---

## 1. 프론트엔드 CSRF 전송 정책

### 1-1. 토큰 발급 흐름

```
페이지 로드
  → SHV.csrf.init()
    → GET dist_process/saas/Auth.php?todo=csrf
    → 응답 data.csrf_token → SHV.csrf.set(token)  [메모리 저장]
  → 이후 POST/GET 모든 요청에 자동 주입
```

로그인 성공·세션 복원 응답에도 `csrf_token`이 포함되면 `SHV.csrf.set()`으로 자동 갱신됨 (`api.js` L105).

### 1-2. API 메서드별 CSRF 전송 방식

| 메서드 | 헤더 | Body 필드 | 백엔드 검증 대상 |
|---|---|---|---|
| `SHV.api.get(url, params)` | `X-CSRF-Token: {token}` | — | GET은 writeTodos에 해당 없음 → 검증 스킵 |
| `SHV.api.post(url, data)` | `X-CSRF-Token: {token}` | `csrf_token={token}` (FormData) | `$_POST['csrf_token']` → `$_SERVER['HTTP_X_CSRF_TOKEN']` 순 |
| `SHV.api.upload(url, formData)` | `X-CSRF-Token: {token}` | `csrf_token={token}` (FormData.append) | 동일 |

### 1-3. 백엔드 CSRF 검증 우선순위 (`CsrfService::validateFromRequest`)

```
1순위: 명시적 $token 인자 (직접 전달)
2순위: $_POST['csrf_token']          ← SHV.api.post/upload가 주입
3순위: $_SERVER['HTTP_X_CSRF_TOKEN'] ← SHV.api.get이 헤더로 전송
```

---

## 2. Mail.php 권한 게이트 실제 코드 대조

### 2-1. writeTodos 배열 (POST + CSRF 필수)

```php
$writeTodos = [
    'mail_send',
    'mail_draft_save',
    'mail_draft_delete',
    'mail_delete',
    'mail_mark_read',
    'account_save',
    'account_delete',
    'account_test',
];
```

### 2-2. role 게이트

| 대상 | 최소 role_level | 설명 |
|---|---|---|
| 읽기 전용 todo (folder_list, mail_list, mail_detail 등) | 1 (로그인) | role 게이트 없음 |
| mailWriteTodos (mail_send / draft / delete / mark_read) | **2** | writer 이상 |
| accountWriteTodos (account_save / delete / test) | **4** | manager 이상 |

### 2-3. 프론트엔드 호출 방식 정합성 체크

| todo | JS 호출 방식 | CSRF 자동 주입 | 정합성 |
|---|---|---|---|
| `mail_send` | `SHV.api.upload()` | ✅ `csrf_token` body | ✅ |
| `mail_draft_save` | `SHV.api.upload()` | ✅ | ✅ |
| `mail_draft_delete` | `SHV.api.post()` | ✅ | ✅ |
| `mail_delete` | `SHV.api.post()` | ✅ | ✅ |
| `mail_mark_read` | `SHV.api.post()` | ✅ | ✅ |
| `account_save` | `SHV.api.post()` | ✅ | ✅ |
| `account_delete` | `SHV.api.post()` | ✅ | ✅ |
| `account_test` | `SHV.api.post()` | ✅ | ✅ |
| 읽기 todo | `SHV.api.get()` | — (헤더는 전송됨) | ✅ |

---

## 3. 발견된 이슈 및 조치

### [이슈 1 — 해결 완료] `_csrf_token` 히든 필드 이름 불일치

- **파일**: `views/saas/mail/compose.php`
- **현상**: 폼에 `<input name="_csrf_token">` 히든 필드 → `FormData`에 `_csrf_token`으로 포함됨
- **문제**: 백엔드는 `$_POST['csrf_token']` (언더스코어 없음)으로 검증 → `_csrf_token` 무시됨
- **실제 동작**: `SHV.api.upload()`이 별도로 `csrf_token` 추가 → 기능은 정상이나 `_csrf_token`이 dead field
- **조치**: `compose.php`에서 `<input name="_csrf_token">` 제거. `SHV.api.upload()`의 자동 주입으로 통일
- **상태**: ✅ **수정 완료 (2026-04-13)**

### [이슈 2 — 백엔드 조치 필요] `mail_admin_settings_save` todo 미등록

- **배경**: `views/saas/mail/settings.php` 관리자설정 저장 버튼이 `mail_admin_settings_save` todo 호출
- **현상**: `Mail.php`의 `writeTodos`에 `mail_admin_settings_save` 없음 → POST+CSRF 검증 스킵 + 실제 저장 로직 없음
- **조치**: ChatGPT(백엔드) → `writeTodos`에 `mail_admin_settings_save` 추가 + 저장 로직 구현 필요
- **role 권고**: `role_level >= 4` (관리자 전용)
- **상태**: ⏳ **백엔드 구현 대기**

### [참고 — 비이슈] GET 요청에도 CSRF 헤더 전송

- `SHV.api.get()`이 `X-CSRF-Token` 헤더를 모든 GET 요청에 포함
- 백엔드는 읽기 todo에 CSRF 검증 없음 → 무해, 추가 방어층으로 유지

---

## 4. 프론트 기준 권장 CSRF 규칙 정리

1. **모든 상태 변경(POST) 요청은 `SHV.api.post()` 또는 `SHV.api.upload()` 사용** — CSRF 자동 주입 보장
2. **PHP 뷰 폼에 `_csrf_token` 히든 필드 추가 금지** — SHV.api가 `csrf_token`으로 자동 주입. 이름 혼동 방지
3. **신규 todo 추가 시**: 백엔드 `writeTodos`에 등록 + 프론트 `SHV.api.post/upload` 사용 필수

---

## 5. BL-04 완료 체크 (프론트 사이드)

- [x] 프론트 CSRF 전송 패턴 명세 완료
- [x] Mail.php writeTodos ↔ 프론트 호출 방식 대조 완료
- [x] 불일치 이슈 1건 (`_csrf_token` 히든 필드) 수정 완료
- [ ] 백엔드: `mail_admin_settings_save` writeTodos 등록 및 구현 (ChatGPT 담당)
- [ ] 백엔드: 전체 17개 API 권한 매트릭스 코드 대조 (ChatGPT 담당)
