#!/usr/bin/env bash
# MAT 스모크 테스트 — Material/Stock API
# 사용:
#   bash scripts/smoke_test_mat.sh <base_url> <cookie_file> [login_id] [password]
# 예시:
#   bash scripts/smoke_test_mat.sh "https://shvq.kr/SHVQ_V2" /tmp/shvq_mat.cookie
#   bash scripts/smoke_test_mat.sh "https://shvq.kr/SHVQ_V2" /tmp/shvq_mat.cookie admin 1234

set -u -o pipefail

BASE="${1:-https://shvq.kr/SHVQ_V2}"
COOKIE="${2:-/tmp/shvq_mat_smoke.cookie}"
LOGIN_ID="${3:-}"
PASSWORD="${4:-}"
API="$BASE/dist_process/saas"
PASS=0
FAIL=0
CSRF=""

section() {
  echo
  echo "━━ $1 ━━"
}

ok() {
  echo "  [PASS] $1"
  PASS=$((PASS + 1))
}

fail() {
  echo "  [FAIL] $1"
  FAIL=$((FAIL + 1))
}

json_status() {
  local raw="$1"
  RAW_JSON="$raw" python3 - <<'PY' 2>/dev/null || echo "INVALID_JSON"
import json, os, sys
try:
    d = json.loads(os.environ.get('RAW_JSON', ''))
except Exception:
    print("INVALID_JSON")
    sys.exit(0)
code = d.get('status') or d.get('code') or '?'
print(f"{code} | {str(d.get('message', ''))[:120]}")
PY
}

json_ok() {
  local raw="$1"
  local out
  out=$(RAW_JSON="$raw" python3 - <<'PY' 2>/dev/null || echo "0"
import json, os, sys
try:
    d = json.loads(os.environ.get('RAW_JSON', ''))
    print('1' if d.get('ok') else '0')
except Exception:
    print('0')
PY
)
  [ "$out" = "1" ]
}

json_path() {
  local raw="$1"
  local path="$2"
  local default="${3:-}"

  RAW_JSON="$raw" python3 - "$path" "$default" <<'PY' 2>/dev/null || printf '%s' "$default"
import json, os, sys

path = sys.argv[1]
default = sys.argv[2]

try:
    cur = json.loads(os.environ.get('RAW_JSON', ''))
except Exception:
    print(default)
    sys.exit(0)

for part in path.split('.'):
    if part == '':
        continue
    if isinstance(cur, dict):
        if part in cur:
            cur = cur[part]
            continue
        print(default)
        sys.exit(0)

    if isinstance(cur, list):
        if part.isdigit() and int(part) < len(cur):
            cur = cur[int(part)]
            continue
        print(default)
        sys.exit(0)

    print(default)
    sys.exit(0)

if cur is None:
    print(default)
elif isinstance(cur, (dict, list)):
    print(json.dumps(cur, ensure_ascii=False, separators=(',', ':')))
else:
    print(cur)
PY
}

request_get() {
  local url="$1"
  curl -s -b "$COOKIE" -c "$COOKIE" "$url"
}

request_post() {
  local url="$1"
  shift
  curl -s -b "$COOKIE" -c "$COOKIE" -X POST "$url" "$@"
}

check_ok() {
  local label="$1"
  local raw="$2"
  echo "  $label: $(json_status "$raw")"
  if json_ok "$raw"; then
    ok "$label"
    return 0
  fi
  fail "$label"
  return 1
}

refresh_csrf() {
  local raw
  raw=$(request_get "$API/Auth.php?todo=csrf")
  echo "  csrf: $(json_status "$raw")"
  CSRF=$(json_path "$raw" "data.csrf_token" "")

  if json_ok "$raw" && [ -n "$CSRF" ]; then
    ok "csrf token ready"
    return 0
  fi

  fail "csrf token ready"
  return 1
}

section "0. 인증 컨텍스트 준비"
refresh_csrf || true

if [ -n "$LOGIN_ID" ] && [ -n "$PASSWORD" ]; then
  LOGIN_RAW=$(request_post "$API/Auth.php" \
    --data-urlencode "todo=login" \
    --data-urlencode "login_id=$LOGIN_ID" \
    --data-urlencode "password=$PASSWORD" \
    --data-urlencode "remember=0" \
    --data-urlencode "csrf_token=$CSRF")
  check_ok "auth login" "$LOGIN_RAW" || true
  refresh_csrf || true
else
  echo "  info: login_id/password 미지정, 기존 cookie 세션으로 진행"
fi

section "1. MaterialSettings 조회/저장"
SETTINGS_GET_RAW=$(request_get "$API/MaterialSettings.php?todo=get")
check_ok "material_settings get" "$SETTINGS_GET_RAW" || true

PREFIX=$(json_path "$SETTINGS_GET_RAW" "data.settings.material_no_prefix" "MAT")
NO_FORMAT=$(json_path "$SETTINGS_GET_RAW" "data.settings.material_no_format" "MAT-[TAB]-[YYMM]-[SEQ]")
NO_SEQ_LEN=$(json_path "$SETTINGS_GET_RAW" "data.settings.material_no_seq_len" "4")
BARCODE_FORMAT=$(json_path "$SETTINGS_GET_RAW" "data.settings.barcode_format" "{CAT}-{TYPE}-{DATE}+{EMP}{SEQ}")
BARCODE_CAT_LEN=$(json_path "$SETTINGS_GET_RAW" "data.settings.barcode_cat_len" "4")
BARCODE_SEQ_LEN=$(json_path "$SETTINGS_GET_RAW" "data.settings.barcode_seq_len" "3")
MAX_DEPTH=$(json_path "$SETTINGS_GET_RAW" "data.settings.item_category_max_depth" "4")
PJT_ITEMS_JSON=$(json_path "$SETTINGS_GET_RAW" "data.settings.pjt_items" "[]")
CATEGORY_LABELS_JSON=$(json_path "$SETTINGS_GET_RAW" "data.settings.category_option_labels" "{}")

refresh_csrf || true
SETTINGS_SAVE_RAW=$(request_post "$API/MaterialSettings.php" \
  --data-urlencode "todo=save" \
  --data-urlencode "material_no_prefix=$PREFIX" \
  --data-urlencode "material_no_format=$NO_FORMAT" \
  --data-urlencode "material_no_seq_len=$NO_SEQ_LEN" \
  --data-urlencode "barcode_format=$BARCODE_FORMAT" \
  --data-urlencode "barcode_cat_len=$BARCODE_CAT_LEN" \
  --data-urlencode "barcode_seq_len=$BARCODE_SEQ_LEN" \
  --data-urlencode "item_category_max_depth=$MAX_DEPTH" \
  --data-urlencode "csrf_token=$CSRF")
check_ok "material_settings save" "$SETTINGS_SAVE_RAW" || true

refresh_csrf || true
SETTINGS_PJT_RAW=$(request_post "$API/MaterialSettings.php" \
  --data-urlencode "todo=save_pjt_items" \
  --data-urlencode "pjt_items=$PJT_ITEMS_JSON" \
  --data-urlencode "csrf_token=$CSRF")
check_ok "material_settings save_pjt_items" "$SETTINGS_PJT_RAW" || true

refresh_csrf || true
SETTINGS_LABEL_RAW=$(request_post "$API/MaterialSettings.php" \
  --data-urlencode "todo=save_category_option_labels" \
  --data-urlencode "category_option_labels=$CATEGORY_LABELS_JSON" \
  --data-urlencode "csrf_token=$CSRF")
check_ok "material_settings save_category_option_labels" "$SETTINGS_LABEL_RAW" || true

section "2. Material CRUD"
TOKEN="MATSMK$(date +%m%d%H%M%S)"
ITEM_CODE="${TOKEN}"
ITEM_NAME="스모크품목_${TOKEN}"

refresh_csrf || true
CREATE_RAW=$(request_post "$API/Material.php" \
  --data-urlencode "todo=create" \
  --data-urlencode "item_code=$ITEM_CODE" \
  --data-urlencode "name=$ITEM_NAME" \
  --data-urlencode "standard=SMOKE-SPEC" \
  --data-urlencode "unit=EA" \
  --data-urlencode "inventory_management=유" \
  --data-urlencode "safety_count=1" \
  --data-urlencode "base_count=5" \
  --data-urlencode "csrf_token=$CSRF")
check_ok "material create" "$CREATE_RAW" || true

ITEM_IDX=$(json_path "$CREATE_RAW" "data.idx" "0")
if [ "${ITEM_IDX:-0}" -le 0 ]; then
  ITEM_IDX=$(json_path "$CREATE_RAW" "data.row.idx" "0")
fi
if [ "${ITEM_IDX:-0}" -gt 0 ]; then
  ok "material idx=$ITEM_IDX"
else
  fail "material idx parse"
fi

DETAIL_RAW="{}"
if [ "${ITEM_IDX:-0}" -gt 0 ]; then
  DETAIL_RAW=$(request_get "$API/Material.php?todo=detail&idx=$ITEM_IDX")
  check_ok "material detail" "$DETAIL_RAW" || true
fi

if [ "${ITEM_IDX:-0}" -gt 0 ]; then
  refresh_csrf || true
  UPDATE_RAW=$(request_post "$API/Material.php" \
    --data-urlencode "todo=update" \
    --data-urlencode "idx=$ITEM_IDX" \
    --data-urlencode "standard=SMOKE-SPEC-UPDATED" \
    --data-urlencode "csrf_token=$CSRF")
  check_ok "material update" "$UPDATE_RAW" || true
fi

LIST_RAW=$(request_get "$API/Material.php?todo=list&search=$TOKEN&limit=10")
check_ok "material list(search)" "$LIST_RAW" || true
LIST_TOTAL=$(json_path "$LIST_RAW" "data.total" "0")
if [ "${LIST_TOTAL:-0}" -ge 1 ]; then
  ok "material list total=$LIST_TOTAL"
else
  fail "material list total"
fi

section "3. Stock Log 다중타입 조회"
LOG_RAW=$(request_get "$API/Stock.php?todo=stock_log&stock_type_in=1,4&limit=10")
check_ok "stock_log stock_type_in=1,4" "$LOG_RAW" || true

section "4. Material 삭제(정리)"
if [ "${ITEM_IDX:-0}" -gt 0 ]; then
  refresh_csrf || true
  DELETE_RAW=$(request_post "$API/Material.php" \
    --data-urlencode "todo=delete" \
    --data-urlencode "idx_list=$ITEM_IDX" \
    --data-urlencode "csrf_token=$CSRF")
  check_ok "material delete" "$DELETE_RAW" || true

  AFTER_DELETE_RAW=$(request_get "$API/Material.php?todo=detail&idx=$ITEM_IDX")
  echo "  material detail(after delete): $(json_status "$AFTER_DELETE_RAW")"
  if json_ok "$AFTER_DELETE_RAW"; then
    fail "material delete verify"
  else
    ok "material delete verify"
  fi
else
  echo "  skip: 생성 idx 없음으로 delete 단계 건너뜀"
fi

section "결과"
TOTAL=$((PASS + FAIL))
echo "  PASS: $PASS / $TOTAL"
echo "  FAIL: $FAIL / $TOTAL"

if [ "$FAIL" -eq 0 ]; then
  echo "  [ALL PASS]"
  exit 0
fi

echo "  [SOME FAILED]"
exit 1
