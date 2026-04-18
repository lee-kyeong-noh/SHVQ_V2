# SHVQ_V2 API-테이블 CRUD 매핑 매트릭스 v1

- 작성일: 2026-04-12
- 입력 근거: Claude API 액션 추출 초안
- 범위: `dist_process/*.php` 기준 `todo` 액션과 DB 테이블 CRUD 영향 매핑

---

## 1) 요약

1. 총 액션 수: `584개`
2. 영향 고유 테이블: `55개+`
3. 목적
  - API 전환 우선순위 산정
  - Shadow Write 대상 식별
  - 이관 검증 쿼리 기준 정의

---

## 2) 파일별 액션 분포

| API 파일 | todo 액션 수 | 주요 테이블 |
|---|---:|---|
| Site.php | 77 | Tb_Site, Tb_SiteEstimate, Tb_EstimateItem, Tb_Bill, Tb_BillGroup, Tb_Agree_Board |
| Project.php | 69 | Tb_PjtPlan, Tb_PjtPlanPhase, Tb_PjtPlanEstItem, Tb_PjtProject, Tb_Project_Hogi |
| Settings.php | 42 | Tb_Department, Tb_PjtSettings, Tb_UserSettings, Tb_PjtStatus |
| Material.php | 37 | Tb_Item, Tb_ItemCategory, Tb_ItemChild, Tb_ItemHistory |
| Member.php | 24 | Tb_Members, Tb_PhoneBook, Tb_BranchOrgFolder, Tb_MemberHogi |
| Purchase.php | 21 | Tb_Product_Purchase, Tb_Company, Tb_PhoneBook, Tb_FileAttach |
| Expense.php | 18 | Tb_Expense, Tb_Employee, Tb_EmployeeCard |
| Fund.php | 17 | Tb_Asset_Account, Tb_Expenditure_Resolution, Tb_Asset |
| Sales.php | 15 | Tb_TaxInvoice, Tb_Bill, Tb_SiteEstimate, Tb_BillGroup |
| HeadOffice.php | 14 | Tb_HeadOffice, Tb_Members, Tb_HeadOrgFolder |
| Stock.php | 14 | Tb_Stock, Tb_StockLog, Tb_StockSetting |
| Employee.php | 9 | Tb_Employee, Tb_Users, Tb_Permission, Tb_EmployeeCard |
| Trash.php | 7 | Tb_Trash, Tb_PjtPlan, Tb_SiteEstimate |

---

## 3) 매핑 표준

1. 필수 컬럼
  - `api_file`, `todo`, `table_name`, `crud`, `risk_tier`, `wave`, `owner`
2. 확장 컬럼
  - `transaction_scope`, `shadow_write_required`, `migration_check_sql`, `rollback_strategy`
3. CRUD 정의
  - C: INSERT
  - R: SELECT
  - U: UPDATE
  - D: DELETE(또는 `is_deleted=1` 소프트삭제)

---

## 4) 우선순위 규칙

1. Tier 1
  - Site/Material/Stock/Member/HeadOffice/Employee 핵심 쓰기 액션
2. Tier 2
  - Settings/Purchase/Sales/Expense/Fund/Trash 및 Site 보조 쓰기
3. Tier 3
  - 정렬/이동/저위험 유지보수 액션

세부 Tier 기준은 `SHVQ_V2_SHADOW_WRITE_TARGET_SPEC_V1.md`를 따른다.

---

## 5) 활용 방식

1. API 전환 매트릭스와 연결
  - `wave` 우선순위 산정 근거로 사용
2. DB 변경 매트릭스와 연결
  - 컬럼/인덱스 추가 필요성 근거로 사용
3. E2E와 연결
  - 각 시나리오에 `related_api`/`related_table`을 연결해 추적성 확보

---

## 6) 오픈 항목

1. 584개 액션의 `todo`별 상세 파라미터 정규화
2. API-테이블 단위 트랜잭션 경계 정의
3. 테이블별 이관 검증 SQL 템플릿 확정
