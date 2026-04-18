# SHVQ_V2 Tenant Context 장애 복구 Runbook v1

- 작성일: 2026-04-12
- 대상: 로그인 후 `AUTH_CONTEXT_MISSING` 또는 tenant 매핑 누락 장애
- 범위: `Tb_SvcTenant`, `Tb_SvcTenantUser`, `AuthService::resolveTenantContext()`

---

## 1) 증상

1. 로그인 API에서 `AUTH_CONTEXT_MISSING` 반환
2. Remember Session 복구(`remember_session`) 실패
3. 특정 사용자만 로그인 불가(다른 사용자 정상)

---

## 2) 1차 점검(5분)

1. 사용자 계정 활성 상태 확인 (`Tb_Users.status`)
2. 사용자-테넌트 매핑 존재 여부 확인 (`Tb_SvcTenantUser`)
3. 테넌트 활성 상태 확인 (`Tb_SvcTenant.status='ACTIVE'`)

권장 점검 SQL:

```sql
SELECT TOP 1 idx, id, status
FROM dbo.Tb_Users
WHERE id = @login_id;

SELECT tu.idx, tu.user_idx, tu.tenant_id, tu.status AS tenant_user_status,
       t.service_code, t.tenant_code, t.status AS tenant_status, t.is_default
FROM dbo.Tb_SvcTenantUser tu
JOIN dbo.Tb_SvcTenant t ON t.idx = tu.tenant_id
WHERE tu.user_idx = @user_pk
ORDER BY t.is_default DESC, tu.idx ASC;
```

---

## 3) 표준 복구 절차

1. 매핑 누락 시
  - `Tb_SvcTenantUser`에 사용자 매핑 신규 INSERT
2. 매핑 비활성 시
  - `tu.status`를 `ACTIVE`로 수정
3. 테넌트 비활성 시
  - `Tb_SvcTenant.status`를 `ACTIVE`로 수정(운영 승인 필요)
4. 기본 테넌트 지정
  - 사용자 대표 테넌트에 `is_default=1` 지정

---

## 4) 임시 우회(장애 완화)

1. 영향 사용자만 임시로 수동 tenant 매핑 후 재로그인
2. 긴급 시 remember 토큰 폐기 후 재발급
3. 우회 후 반드시 원인 테이블 정합성 재점검

---

## 5) 사후 조치

1. 감사로그 확인: `Tb_AuthAuditLog` (`DENY_TENANT`, `auth.login`)
2. 재발 방지:
  - 사용자 생성/이관 시 `Tb_SvcTenantUser` 필수 생성
  - 배포 체크리스트에 tenant 매핑 검증 SQL 추가
3. 장애보고:
  - DevLog에 장애원인/복구시간/영향사용자 기록

---

## 6) 롤백 기준

1. 테넌트 활성화 후 권한 이상징후 발생 시 즉시 원복
2. 잘못 연결된 매핑 발견 시 `Tb_SvcTenantUser`를 원상복구
3. 롤백 후 로그를 기준으로 재처리
