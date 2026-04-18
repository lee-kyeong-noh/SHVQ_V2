#!/usr/bin/env bash
# SHVQ V2 - GRP Front API Contract Check (read-only)
# Usage:
#   bash scripts/grp_front_api_check.sh <base_url> <cookie_file>
# Example:
#   bash scripts/grp_front_api_check.sh https://shvq.kr/SHVQ_V2 ./cookies.txt

set -uo pipefail

BASE_URL="${1:-https://shvq.kr/SHVQ_V2}"
COOKIE_FILE="${2:-/tmp/shvq_grp_front.cookie}"
API_BASE="${BASE_URL%/}/dist_process/saas"

PASS=0
FAIL=0

declare -a FAIL_CASES=()

declare RESP_CODE=""
declare RESP_BODY=""

timestamp() {
    date '+%H:%M:%S'
}

pass_case() {
    PASS=$((PASS + 1))
    echo "[$(timestamp)] PASS | $1"
}

fail_case() {
    FAIL=$((FAIL + 1))
    FAIL_CASES+=("$1")
    echo "[$(timestamp)] FAIL | $1"
}

trim_json() {
    python3 - <<'PY' <<<"$1"
import json, sys
raw = sys.stdin.read()
try:
    obj = json.loads(raw)
    print(json.dumps(obj, ensure_ascii=False)[:300])
except Exception:
    print(raw.strip().replace('\n', ' ')[:300])
PY
}

request_get() {
    local url="$1"
    local tmp
    tmp="$(mktemp)"
    RESP_CODE="$(curl -sS -b "$COOKIE_FILE" -c "$COOKIE_FILE" -o "$tmp" -w "%{http_code}" "$url" 2>/dev/null || echo "000")"
    RESP_BODY="$(cat "$tmp" 2>/dev/null || true)"
    rm -f "$tmp"
}

request_post() {
    local url="$1"
    shift || true
    local tmp
    tmp="$(mktemp)"
    RESP_CODE="$(curl -sS -b "$COOKIE_FILE" -c "$COOKIE_FILE" -X POST -o "$tmp" -w "%{http_code}" "$url" "$@" 2>/dev/null || echo "000")"
    RESP_BODY="$(cat "$tmp" 2>/dev/null || true)"
    rm -f "$tmp"
}

json_path() {
    local path="$1"
    python3 - "$path" <<'PY' <<<"${RESP_BODY}"
import json, sys
path = sys.argv[1]
try:
    data = json.loads(sys.stdin.read())
except Exception:
    print("")
    raise SystemExit(0)

cur = data
for token in [p for p in path.split('.') if p != '']:
    if isinstance(cur, dict):
        if token not in cur:
            print("")
            raise SystemExit(0)
        cur = cur[token]
    elif isinstance(cur, list):
        try:
            idx = int(token)
        except Exception:
            print("")
            raise SystemExit(0)
        if idx < 0 or idx >= len(cur):
            print("")
            raise SystemExit(0)
        cur = cur[idx]
    else:
        print("")
        raise SystemExit(0)

if cur is None:
    print("")
elif isinstance(cur, (dict, list)):
    print(json.dumps(cur, ensure_ascii=False))
else:
    print(str(cur))
PY
}

json_bool_ok() {
    local v
    v="$(json_path "ok")"
    [[ "$v" == "True" || "$v" == "true" || "$v" == "1" ]]
}

json_type_is() {
    local path="$1"
    local expected="$2"
    python3 - "$path" "$expected" <<'PY' <<<"${RESP_BODY}"
import json, sys
path = sys.argv[1]
expected = sys.argv[2]

try:
    data = json.loads(sys.stdin.read())
except Exception:
    print("0")
    raise SystemExit(0)

cur = data
for token in [p for p in path.split('.') if p != '']:
    if isinstance(cur, dict):
        if token not in cur:
            print("0")
            raise SystemExit(0)
        cur = cur[token]
    elif isinstance(cur, list):
        try:
            idx = int(token)
        except Exception:
            print("0")
            raise SystemExit(0)
        if idx < 0 or idx >= len(cur):
            print("0")
            raise SystemExit(0)
        cur = cur[idx]
    else:
        print("0")
        raise SystemExit(0)

actual = "unknown"
if isinstance(cur, list):
    actual = "array"
elif isinstance(cur, dict):
    actual = "object"
elif isinstance(cur, bool):
    actual = "bool"
elif isinstance(cur, int):
    actual = "int"
elif isinstance(cur, float):
    actual = "float"
elif isinstance(cur, str):
    actual = "string"

print("1" if actual == expected else "0")
PY
}

assert_ok_array() {
    local case_name="$1"
    local endpoint="$2"
    local query="$3"
    local path="$4"

    request_get "$API_BASE/${endpoint}?${query}"
    if [[ "$RESP_CODE" != "200" ]]; then
        fail_case "$case_name | http=$RESP_CODE body=$(trim_json "$RESP_BODY")"
        return
    fi
    if ! json_bool_ok; then
        fail_case "$case_name | ok=false code=$(json_path "code") message=$(json_path "message")"
        return
    fi
    if [[ "$(json_type_is "$path" "array")" != "1" ]]; then
        fail_case "$case_name | $path is not array"
        return
    fi
    pass_case "$case_name"
}

assert_ok_number() {
    local case_name="$1"
    local endpoint="$2"
    local query="$3"
    local path="$4"

    request_get "$API_BASE/${endpoint}?${query}"
    if [[ "$RESP_CODE" != "200" ]]; then
        fail_case "$case_name | http=$RESP_CODE body=$(trim_json "$RESP_BODY")"
        return
    fi
    if ! json_bool_ok; then
        fail_case "$case_name | ok=false code=$(json_path "code") message=$(json_path "message")"
        return
    fi

    local v
    v="$(json_path "$path")"
    if [[ ! "$v" =~ ^-?[0-9]+$ ]]; then
        fail_case "$case_name | $path is not number (value=${v:-empty})"
        return
    fi
    pass_case "$case_name"
}

echo
echo "== 0) Session Check =="
request_post "$API_BASE/Auth.php" --data-urlencode "todo=remember_session"
if [[ "$RESP_CODE" != "200" ]] || ! json_bool_ok; then
    fail_case "auth remember_session | cookie invalid or expired"
    echo "Cookie file is required: $COOKIE_FILE"
    exit 1
fi
pass_case "auth remember_session"

echo
echo "== 1) Emp Dashboard =="
assert_ok_number "emp summary department_count" "Employee.php" "todo=summary" "data.counts.department_count"
assert_ok_number "emp summary chat_unread_count" "Employee.php" "todo=summary" "data.counts.chat_unread_count"
assert_ok_number "emp summary approval_pending_my_count" "Employee.php" "todo=summary" "data.counts.approval_pending_my_count"

echo
echo "== 2) Org Chart =="
assert_ok_array "org_chart dept_list" "Employee.php" "todo=dept_list&limit=200" "data.items"
assert_ok_array "org_chart employee_list" "Employee.php" "todo=employee_list&limit=200" "data.items"

echo
echo "== 3) Org Chart Card =="
assert_ok_array "org_chart_card phonebook_list" "Employee.php" "todo=phonebook_list&limit=200" "data.items"

echo
echo "== 4) Holiday =="
assert_ok_array "holiday holiday_list" "Employee.php" "todo=holiday_list&limit=200" "data.items"
assert_ok_array "holiday employee_list(for modal)" "Employee.php" "todo=employee_list&limit=300" "data.items"

echo
echo "== 5) Work Overtime =="
assert_ok_array "work_overtime overtime_list" "Employee.php" "todo=overtime_list&limit=200" "data.items"
assert_ok_array "work_overtime employee_list(for modal)" "Employee.php" "todo=employee_list&limit=300" "data.items"

echo
echo "== 6) Attitude =="
assert_ok_array "attitude attendance_list" "Employee.php" "todo=attendance_list&limit=200" "data.items"

echo
echo "== 7) Approval Placeholder Backend Ready =="
assert_ok_array "approval_req list" "Approval.php" "todo=approval_req&limit=100" "data.items"
assert_ok_array "approval_done list" "Approval.php" "todo=approval_done&limit=100" "data.items"
assert_ok_array "doc_all list" "Approval.php" "todo=doc_all&limit=100" "data.items"
assert_ok_array "approval_official list" "Approval.php" "todo=approval_official&limit=100" "data.items"

echo
echo "== 8) Chat Placeholder Backend Ready =="
assert_ok_array "chat room_list" "Chat.php" "todo=room_list&limit=100" "data.items"
assert_ok_number "chat unread_count" "Chat.php" "todo=unread_count" "data.unread_count"

echo
echo "== Summary =="
TOTAL=$((PASS + FAIL))
echo "PASS : $PASS"
echo "FAIL : $FAIL"
echo "TOTAL: $TOTAL"

if [[ ${#FAIL_CASES[@]} -gt 0 ]]; then
    echo
    echo "[FAIL DETAILS]"
    for x in "${FAIL_CASES[@]}"; do
        echo " - $x"
    done
fi

if [[ "$FAIL" -gt 0 ]]; then
    exit 1
fi

exit 0
