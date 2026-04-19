# MAT 구성품 로직 백엔드 보강 스펙 (2026-04-19)

**대상**: ChatGPT (백엔드 담당)
**파일**: `dist_library/erp/MaterialService.php`, `dist_process/saas/Material.php`
**출처**: V1 `ftp://…/SHVQ/dist_process/Material.php`(1265줄) + `material_view.php`(560줄) 전수 조사

> 프론트(`views/saas/mat/view.php`, `css/v2/pages/mat.css`)는 2026-04-19 Claude가 P0~P2 보강 완료. 아래 5개 백엔드 항목이 붙으면 기능 루프가 닫힙니다. 현재 프론트는 응답이 없거나 필드가 비어도 무해한 fallback으로 동작합니다.

---

## 1. `searchComponent` — 카테고리 재귀 트리 포함 (P0)

**현재 동작**: 단일 `category_idx = ?` 만 매칭. 자식 카테고리의 품목은 검색되지 않음.
**V1 동작**: 선택 카테고리 + 모든 하위 카테고리 트리 전체를 WHERE IN 으로 포함.

### 수정 위치
[`dist_library/erp/MaterialService.php:1539-1542`](dist_library/erp/MaterialService.php:1539)
```php
if ($categoryIdx > 0 && $this->columnExists('Tb_Item', 'category_idx')) {
    $where[] = 'ISNULL(i.' . $this->qi('category_idx') . ', 0) = ?';
    $params[] = $categoryIdx;
}
```

### 수정 후 (V1 Material.php 1083-1094 포팅)
```php
if ($categoryIdx > 0 && $this->columnExists('Tb_Item', 'category_idx')) {
    $allCatIds = [$categoryIdx];
    if ($this->tableExists('Tb_ItemCategory') && $this->columnExists('Tb_ItemCategory', 'parent_idx')) {
        $queue = [$categoryIdx];
        while (!empty($queue)) {
            $ph = implode(',', array_fill(0, count($queue), '?'));
            $stmt = $this->db->prepare("SELECT idx FROM Tb_ItemCategory WHERE parent_idx IN ($ph)");
            $stmt->execute($queue);
            $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $queue = [];
            foreach ($children as $cid) {
                $cid = (int)$cid;
                if ($cid > 0 && !in_array($cid, $allCatIds, true)) {
                    $allCatIds[] = $cid;
                    $queue[] = $cid;
                }
            }
        }
    }
    $catPh = implode(',', array_fill(0, count($allCatIds), '?'));
    $where[] = 'ISNULL(i.' . $this->qi('category_idx') . ', 0) IN (' . $catPh . ')';
    $params = array_merge($params, $allCatIds);
}
```

### 검증
```
?todo=component_search&tab_idx=1&category_idx=10&q=볼트
→ category_idx=10의 모든 하위 카테고리(재귀)에 있는 material_pattern='구성품' 품목 반환
```

---

## 2. `searchComponent` — 결과에 `cat_path` 필드 추가 (P0)

**현재**: `category_name` (단일) 반환.
**V1**: `cat_path = "공사 > 전기 > LED"` (부모 체인 역추적).

### 수정 위치
[`dist_library/erp/MaterialService.php:1554-1575`](dist_library/erp/MaterialService.php:1554)

### 수정 후
기존 SELECT 쿼리 수행 후, PHP 레이어에서 경로 문자열 빌드:
```php
$stmt = $this->db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* 카테고리 경로 주입 */
if (!empty($rows) && $this->tableExists('Tb_ItemCategory')) {
    $tabIds = array_unique(array_map(fn($r) => (int)($r['tab_idx'] ?? 0), $rows));
    $tabIds = array_filter($tabIds);
    if ($tabIds !== []) {
        $tabPh = implode(',', array_fill(0, count($tabIds), '?'));
        $hasParent = $this->columnExists('Tb_ItemCategory', 'parent_idx');
        $stmt = $this->db->prepare('SELECT idx, '
            . ($hasParent ? 'ISNULL(parent_idx,0) AS parent_idx' : '0 AS parent_idx')
            . ', name FROM Tb_ItemCategory WHERE tab_idx IN (' . $tabPh . ')');
        $stmt->execute(array_values($tabIds));
        $catMap = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $catMap[(int)$c['idx']] = $c;
        }
        foreach ($rows as &$r) {
            $cid = (int)($r['category_idx'] ?? 0);
            $path = [];
            $limit = 8;
            while ($cid > 0 && $limit-- > 0 && isset($catMap[$cid])) {
                array_unshift($path, (string)$catMap[$cid]['name']);
                $cid = (int)$catMap[$cid]['parent_idx'];
            }
            $r['cat_path'] = implode(' › ', $path);
        }
        unset($r);
    }
}
return is_array($rows) ? $rows : [];
```

### 프론트 소비 지점
[`views/saas/mat/view.php:1617-1649`](views/saas/mat/view.php:1617) `doCompSearch` 결과 렌더 시, `cat_path`가 존재하면 카테고리 뱃지로 표시하도록 추가 가능. 현재는 `category_name` fallback 사용 중.

---

## 3. `list` — 부모 품목 자식 합산가 (`child_total_price`) (P1)

**V1 동작**: 목록 응답의 부모 품목에 "자식들의 `sale_price * qty` 합계" 별도 컬럼.

### 수정 위치
[`dist_library/erp/MaterialService.php:145-165`](dist_library/erp/MaterialService.php:145) SELECT 블록 내 `component_count` 근처.

### 수정 후 (컬럼 추가)
```sql
, {$this->tableExists('Tb_ItemComponent') ? "
  (SELECT ISNULL(SUM(ISNULL(child.sale_price,0) * ISNULL(ic.qty,ic.child_qty)), 0)
   FROM Tb_ItemComponent ic
   LEFT JOIN Tb_Item child ON child.idx = ic.child_item_idx
   WHERE ic.parent_item_idx = i.idx AND ISNULL(ic.is_deleted,0) = 0)
" : "0"} AS child_total_price
```

> 테이블 fallback 및 qty 컬럼 이름 차이는 기존 `itemComponentTable()`/`itemChildQtyColumn()` 패턴 재사용 권장.

### 프론트 소비 지점
[`views/saas/mat/list.php`](views/saas/mat/list.php) 목록 테이블 (현재 `component_count`만 표시). 컬럼 추가는 목록 페이지 별도 작업.

---

## 4. `list` — `exclude_component` 파라미터 (P2)

**V1 동작**: `?exclude_child=1` 전달 시, 자식으로 등록된 품목 + `material_pattern='구성품'`인 품목을 검색 결과에서 제외 (견적/매입 모달에서 재사용).

### 수정 위치
[`dist_library/erp/MaterialService.php:33-90`](dist_library/erp/MaterialService.php:33) `list()` WHERE 생성부.

### 수정 후
```php
$excludeComponent = (int)($query['exclude_component'] ?? $query['exclude_child'] ?? 0) === 1;
if ($excludeComponent && $this->tableExists('Tb_Item')) {
    $where[] = "ISNULL(i.material_pattern, N'') <> N'구성품'";
    if ($this->tableExists('Tb_ItemComponent')) {
        $where[] = "i.idx NOT IN (SELECT child_item_idx FROM Tb_ItemComponent WHERE ISNULL(is_deleted,0)=0)";
    } elseif ($this->tableExists('Tb_ItemChild')) {
        $where[] = "i.idx NOT IN (SELECT child_item_idx FROM Tb_ItemChild)";
    }
}
```

### 사용 예
```
?todo=material_list&exclude_component=1&q=볼트
→ 견적 등록 모달에서 직접 선택 가능한 품목만 반환
```

---

## 5. `componentList` — 자식 가격 필드 추가 (P1)

**현재**: 자식의 이름·규격·단위·재고관리·유형·follow_mode 는 있으나 **가격이 없음**.
**목적**: 프론트가 자식 합산가 계산 및 각 행 단가 표시 가능하게 함.

### 수정 위치
[`dist_library/erp/MaterialService.php:1599-1615`](dist_library/erp/MaterialService.php:1599) componentList SELECT.

### 추가 컬럼 (SELECT 확장)
```sql
, {$this->itemIntExpr('sale_price', 0)} AS child_sale_price
, {$this->itemIntExpr('cost', 0)}       AS child_cost
```

> `itemIntExpr()` 이미 존재 — 다른 SELECT 블록(1555-1564)과 동일 패턴.

### 프론트 소비 지점
[`views/saas/mat/view.php:599-610`](views/saas/mat/view.php:599) 합계 계산에서 이미 `$comp['child_sale_price']`를 읽고 있음 (값 없으면 0 fallback). 백엔드 반영 즉시 합계 배지 표시 가능.

---

## 6. (참고) 기존 완결 API — 재확인만

- `component_add` ← V1 `link_child_item`: material_pattern='구성품' 검증 ✓ 중복 검증 ✓ 자기자신 차단 ✓
- `component_delete` ← V1 `unlink_child_item`: soft delete 지원 ✓ (V2 개선)
- `component_update` ← V1 `update_child_qty`: qty 최소 1 미보장 → [추가 보강 권장](dist_library/erp/MaterialService.php:1686): `componentUpdate()` 초입에 `$qty = max(1, $qty);` 한 줄 추가.

---

## 7. 적용 후 프론트 회귀 체크리스트

| 케이스 | 기대 동작 |
|---|---|
| `material_pattern='구성품'` 품목 상세 진입 | 노란 배너 + 구성품 섹션 미표시 |
| 부모 품목에 구성품 등록 | 카드 헤더에 "합계 N원" 배지 표시 (SPEC 5 적용 후) |
| 검색 모달에 2자 입력 | 250ms 후 자동 검색 결과 표시 |
| 검색 결과에서 이미 연결된 품목 제외 | 프론트 `_linkedChildIds`가 서버 응답 필터 |
| 검색 결과에 카테고리 경로 표시 | `cat_path` 사용 (SPEC 2 적용 후) |
| 견적 모달에서 자식/구성품 미노출 | `exclude_component=1` 전달 (SPEC 4 적용 후) |
| `is_split` 토글 클릭 | 조회 모드에서 즉시 `item_inline_update` POST → DB 반영 |
| `follow_mode` 2-button 토글 (구성품 유형) | 동일 |

---

## 8. 테이블/컬럼 존재 확인 메모

V2 DB(`CSM_C004732_V2`)에 `Tb_ItemComponent` 실재 여부 확인 필요 — 2026-04-14 manual.php 변경이력에 "구성품 테이블 V2 DB 미생성"으로 기록돼 있으나 이후 상태 확인 못 함. `fallback Tb_ItemChild`가 V1 DB만 있을 경우 V2에서는 동작하지 않음.

ChatGPT 쪽에서 다음 중 하나 처리 필요:
- (A) V2 DB에 `Tb_ItemComponent` 마이그레이션 (권장 스키마 ↓)
- (B) V1 DB(`CSM_C004732`)의 `Tb_ItemChild`를 `Tb_ItemComponent`로 **복사 이관** + 이후 분리 유지

### 권장 V2 스키마 (`Tb_ItemComponent`)
```sql
CREATE TABLE Tb_ItemComponent (
    idx             INT IDENTITY(1,1) PRIMARY KEY,
    parent_item_idx INT NOT NULL,
    child_item_idx  INT NOT NULL,
    qty             DECIMAL(18,2) DEFAULT 1,
    sort_order      INT DEFAULT 0,
    is_deleted      TINYINT DEFAULT 0,
    created_by      INT DEFAULT 0,
    created_at      DATETIME DEFAULT GETDATE(),
    updated_by      INT DEFAULT 0,
    updated_at      DATETIME DEFAULT GETDATE()
);
CREATE INDEX IX_ItemComponent_Parent ON Tb_ItemComponent (parent_item_idx, is_deleted);
CREATE INDEX IX_ItemComponent_Child  ON Tb_ItemComponent (child_item_idx, is_deleted);
```

> V1 스키마(qty INT, sort_order, reg_date) 대비 V2는 soft delete + 감사 컬럼 완비. `componentAdd` 코드가 이미 존재 여부로 선택적으로 INSERT 처리중이라 위 스키마는 그대로 호환.

---

**작성**: Claude
**공동 검토 필요**: ChatGPT (백엔드 반영)
**연관 이슈**: [views/saas/manage/manual.php](views/saas/manage/manual.php) 2026-04-19 MAT 변경이력 카드, DevLog V2/MAT

---

## 9. 추가 구현 메모 (Codex 병합 보강)

아래 4개는 기존 스펙의 의도를 유지하면서 V2 코드베이스 기준으로 구현 안정성을 높이기 위한 보강 메모입니다.

1. `searchComponent` 재귀 카테고리
- V2 `MaterialService`에는 이미 `categorySubtreeIds(int $rootIdx)` 헬퍼가 존재 (`dist_library/erp/MaterialService.php:2050`).
- 동일 재귀 로직을 새로 작성하기보다 위 헬퍼 재사용을 권장.

2. `list`의 `child_total_price` 타입 정규화
- `list()` 응답은 `normalizeListRows()` 후처리를 거치므로, 신규 금액 필드 `child_total_price`도 float 캐스팅 대상에 포함 권장.
- 예: `foreach (['cost','sale_price','safety_count','base_count','qty','child_total_price'] as $key)`

3. `exclude_component` API 파라미터 전달
- `dist_process/saas/Material.php`의 `material_list` 분기는 이미 `$_GET + $_POST`를 `MaterialService::list($query)`로 그대로 전달.
- 즉 `exclude_component`는 서비스 레이어 구현만으로 동작 가능 (컨트롤러 분기 추가 불필요).

4. `componentUpdate` 최소 수량 보정
- `componentAdd()`는 이미 `$qty = max(1, $qty);` 적용됨.
- `componentUpdate()`에도 동일 규칙을 적용해 V1 동작(`update_child_qty`)과 일관성 유지 권장.

