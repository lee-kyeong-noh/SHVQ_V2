#!/usr/bin/env bash
# SHVQ V2 - GRP Smoke Test (Wave2 Groupware)
# Usage:
#   bash scripts/smoke_test_grp.sh <base_url> <cookie_file>
# Example:
#   bash scripts/smoke_test_grp.sh https://shvq.kr/SHVQ_V2 ./cookies.txt

set -uo pipefail

BASE_URL="${1:-https://shvq.kr/SHVQ_V2}"
COOKIE_FILE="${2:-/tmp/shvq_smoke.cookie}"
API_BASE="${BASE_URL%/}/dist_process/saas"

PASS=0
FAIL=0
SKIP=0

declare -a PASS_CASES=()
declare -a FAIL_CASES=()
declare -a SKIP_CASES=()

declare RESP_CODE=""
declare RESP_BODY=""

declare CSRF_TOKEN=""
declare USER_PK=0
declare ROLE_LEVEL=0

declare DEPT_ID=0
declare EMP_ID=0
declare HOL_ID=0
declare OT_ID=0
declare DOC_ID=0
declare ROOM_ID=0
declare MSG_ID=0

timestamp() {
    date '+%H:%M:%S'
}

section() {
    echo
    echo "== $1 =="
}

pass_case() {
    local name="$1"
    local note="${2:-}"
    PASS=$((PASS + 1))
    PASS_CASES+=("${name}${note:+ | $note}")
    echo "[$(timestamp)] PASS | ${name}${note:+ | $note}"
}

fail_case() {
    local name="$1"
    local note="${2:-}"
    FAIL=$((FAIL + 1))
    FAIL_CASES+=("${name}${note:+ | $note}")
    echo "[$(timestamp)] FAIL | ${name}${note:+ | $note}"
}

skip_case() {
    local name="$1"
    local note="${2:-}"
    SKIP=$((SKIP + 1))
    SKIP_CASES+=("${name}${note:+ | $note}")
    echo "[$(timestamp)] SKIP | ${name}${note:+ | $note}"
}

trim_json() {
    python3 - <<'PY' <<<"$1"
import json, sys
raw = sys.stdin.read()
try:
    obj = json.loads(raw)
    print(json.dumps(obj, ensure_ascii=False)[:400])
except Exception:
    print(raw.strip().replace('\n', ' ')[:400])
PY
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

json_contains_idx() {
    local list_path="$1"
    local idx="$2"
    python3 - "$list_path" "$idx" <<'PY' <<<"${RESP_BODY}"
import json, sys
path = sys.argv[1]
needle = int(sys.argv[2])
try:
    data = json.loads(sys.stdin.read())
except Exception:
    print("0")
    raise SystemExit(0)
cur = data
for token in [p for p in path.split('.') if p != '']:
    if isinstance(cur, dict) and token in cur:
        cur = cur[token]
    else:
        print("0")
        raise SystemExit(0)
if not isinstance(cur, list):
    print("0")
    raise SystemExit(0)
for row in cur:
    if isinstance(row, dict) and int(row.get('idx', 0)) == needle:
        print("1")
        raise SystemExit(0)
print("0")
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

assert_http_200_and_ok() {
    local case_name="$1"
    if [[ "$RESP_CODE" != "200" ]]; then
        fail_case "$case_name" "http=$RESP_CODE body=$(trim_json "$RESP_BODY")"
        return
    fi
    if ! json_bool_ok; then
        local code msg
        code="$(json_path "code")"
        msg="$(json_path "message")"
        fail_case "$case_name" "ok=false code=${code:-?} message=${msg:-?}"
        return
    fi
    pass_case "$case_name"
}

assert_http_200_and_fail() {
    local case_name="$1"
    if [[ "$RESP_CODE" != "200" && "$RESP_CODE" != "400" && "$RESP_CODE" != "403" && "$RESP_CODE" != "404" && "$RESP_CODE" != "409" && "$RESP_CODE" != "422" ]]; then
        fail_case "$case_name" "unexpected-http=$RESP_CODE body=$(trim_json "$RESP_BODY")"
        return
    fi
    if json_bool_ok; then
        fail_case "$case_name" "expected-failure but ok=true"
        return
    fi
    pass_case "$case_name" "expected failure observed"
}

refresh_csrf() {
    request_get "$API_BASE/Auth.php?todo=csrf"
    if [[ "$RESP_CODE" != "200" ]]; then
        return 1
    fi
    if ! json_bool_ok; then
        return 1
    fi
    CSRF_TOKEN="$(json_path "data.csrf_token")"
    [[ -n "$CSRF_TOKEN" ]]
}

api_get() {
    local endpoint="$1"
    local query="${2:-}"
    if [[ -n "$query" ]]; then
        request_get "$API_BASE/${endpoint}?${query}"
    else
        request_get "$API_BASE/${endpoint}"
    fi
}

api_post() {
    local endpoint="$1"
    shift
    request_post "$API_BASE/${endpoint}" "$@"
}

cleanup_dept() {
    if [[ "$DEPT_ID" -le 0 ]]; then
        return
    fi

    if [[ "$ROLE_LEVEL" -lt 4 ]]; then
        skip_case "cleanup dept_delete" "requires role>=4"
        return
    fi

    if ! refresh_csrf; then
        fail_case "cleanup dept_delete" "csrf refresh failed"
        return
    fi

    api_post "Employee.php" \
        --data-urlencode "todo=dept_delete" \
        --data-urlencode "dept_id=${DEPT_ID}" \
        --data-urlencode "csrf_token=${CSRF_TOKEN}"

    if [[ "$RESP_CODE" == "200" ]] && json_bool_ok; then
        pass_case "cleanup dept_delete" "dept_id=${DEPT_ID}"
    else
        fail_case "cleanup dept_delete" "http=$RESP_CODE body=$(trim_json "$RESP_BODY")"
    fi
}

section "0) Auth Context + CSRF"

api_post "Auth.php" --data-urlencode "todo=remember_session"
if [[ "$RESP_CODE" != "200" ]] || ! json_bool_ok; then
    fail_case "auth remember_session" "login session missing or invalid cookie file"
    echo
    echo "Cookie file authentication is required."
    echo "Run after browser login with a valid cookie file: $COOKIE_FILE"
    exit 1
fi
USER_PK="$(json_path "data.user.user_pk")"
ROLE_LEVEL="$(json_path "data.user.role_level")"
[[ "$USER_PK" =~ ^[0-9]+$ ]] || USER_PK=0
[[ "$ROLE_LEVEL" =~ ^[0-9]+$ ]] || ROLE_LEVEL=0
pass_case "auth remember_session" "user_pk=$USER_PK role_level=$ROLE_LEVEL"

if refresh_csrf; then
    pass_case "csrf token" "len=${#CSRF_TOKEN}"
else
    fail_case "csrf token" "body=$(trim_json "$RESP_BODY")"
    exit 1
fi

section "1) Department"

DEPT_NAME="SMOKE_DEPT_$(date +%H%M%S)"
api_post "Employee.php" \
    --data-urlencode "todo=dept_insert" \
    --data-urlencode "dept_name=${DEPT_NAME}" \
    --data-urlencode "csrf_token=${CSRF_TOKEN}"
assert_http_200_and_ok "dept_insert"
if json_bool_ok; then
    DEPT_ID="$(json_path "data.item.idx")"
    [[ "$DEPT_ID" =~ ^[0-9]+$ ]] || DEPT_ID=0
fi
if [[ "$DEPT_ID" -gt 0 ]]; then
    pass_case "dept_insert idx" "dept_id=$DEPT_ID"
else
    fail_case "dept_insert idx" "idx missing"
fi

api_get "Employee.php" "todo=dept_list"
assert_http_200_and_ok "dept_list"
if [[ "$DEPT_ID" -gt 0 ]]; then
    found="$(json_contains_idx "data.items" "$DEPT_ID")"
    if [[ "$found" == "1" ]]; then
        pass_case "dept_list contains inserted"
    else
        fail_case "dept_list contains inserted" "dept_id=$DEPT_ID not found"
    fi
fi

section "2) Employee"

if ! refresh_csrf; then
    fail_case "csrf refresh before employee"
else
    EMP_NAME="SMOKE_EMP_$(date +%H%M%S)"
    api_post "Employee.php" \
        --data-urlencode "todo=insert_employee" \
        --data-urlencode "emp_name=${EMP_NAME}" \
        --data-urlencode "dept_idx=${DEPT_ID}" \
        --data-urlencode "status=ACTIVE" \
        --data-urlencode "csrf_token=${CSRF_TOKEN}"
    assert_http_200_and_ok "insert_employee"
    if json_bool_ok; then
        EMP_ID="$(json_path "data.item.idx")"
        [[ "$EMP_ID" =~ ^[0-9]+$ ]] || EMP_ID=0
    fi

    if [[ "$EMP_ID" -gt 0 ]]; then
        pass_case "insert_employee idx" "emp_id=$EMP_ID"

        api_get "Employee.php" "todo=employee_detail&idx=${EMP_ID}"
        assert_http_200_and_ok "employee_detail"
        detail_idx="$(json_path "data.item.idx")"
        if [[ "$detail_idx" == "$EMP_ID" ]]; then
            pass_case "employee_detail idx match"
        else
            fail_case "employee_detail idx match" "expected=$EMP_ID got=${detail_idx:-?}"
        fi

        if refresh_csrf; then
            api_post "Employee.php" \
                --data-urlencode "todo=update_employee" \
                --data-urlencode "idx=${EMP_ID}" \
                --data-urlencode "emp_name=${EMP_NAME}_UPD" \
                --data-urlencode "dept_idx=${DEPT_ID}" \
                --data-urlencode "status=ACTIVE" \
                --data-urlencode "csrf_token=${CSRF_TOKEN}"
            assert_http_200_and_ok "update_employee"
        else
            fail_case "csrf refresh before update_employee"
        fi
    else
        fail_case "insert_employee idx" "employee id missing; skip downstream employee tests"
    fi
fi

section "3) Employee Photo Upload"
if [[ "$EMP_ID" -le 0 ]]; then
    skip_case "upload_photo tests" "employee id unavailable"
else
    if refresh_csrf; then
        TMP_PHOTO="$(mktemp "${TMPDIR:-/tmp}/shvq_emp_photo.XXXXXX.png")"
        if python3 - "$TMP_PHOTO" <<'PY'
import base64, sys
png = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7+fRkAAAAASUVORK5CYII=")
with open(sys.argv[1], "wb") as fp:
    fp.write(png)
print("ok")
PY
        then
            request_post "$API_BASE/Employee.php" \
                -F "todo=upload_photo" \
                -F "employee_id=${EMP_ID}" \
                -F "photo=@${TMP_PHOTO};type=image/png" \
                -F "csrf_token=${CSRF_TOKEN}"
            assert_http_200_and_ok "upload_photo"

            if json_bool_ok; then
                PHOTO_URL="$(json_path "data.photo_url")"
                if [[ -n "$PHOTO_URL" && "$PHOTO_URL" == http* && "$PHOTO_URL" == */uploads/*/employee/* ]]; then
                    pass_case "upload_photo photo_url" "$PHOTO_URL"
                else
                    fail_case "upload_photo photo_url" "invalid=${PHOTO_URL:-?}"
                fi

                api_get "Employee.php" "todo=employee_detail&idx=${EMP_ID}"
                assert_http_200_and_ok "employee_detail after upload_photo"
                if json_bool_ok; then
                    detail_photo_url="$(json_path "data.item.photo_url")"
                    if [[ -n "$detail_photo_url" && "$detail_photo_url" == "$PHOTO_URL" ]]; then
                        pass_case "employee_detail photo_url match"
                    else
                        fail_case "employee_detail photo_url match" "expected=${PHOTO_URL:-?} got=${detail_photo_url:-?}"
                    fi
                fi
            fi
        else
            fail_case "upload_photo fixture" "png fixture generation failed"
        fi

        rm -f "${TMP_PHOTO:-}"
    else
        fail_case "csrf refresh before upload_photo"
    fi
fi

section "4) Holiday (request/cancel)"
if [[ "$EMP_ID" -le 0 ]]; then
    skip_case "holiday tests" "employee id unavailable"
else
    if refresh_csrf; then
        api_post "Employee.php" \
            --data-urlencode "todo=save_holiday" \
            --data-urlencode "employee_idx=${EMP_ID}" \
            --data-urlencode "holiday_type=ANNUAL" \
            --data-urlencode "start_date=2026-05-01" \
            --data-urlencode "end_date=2026-05-02" \
            --data-urlencode "reason=smoke holiday" \
            --data-urlencode "csrf_token=${CSRF_TOKEN}"
        assert_http_200_and_ok "save_holiday"
        if json_bool_ok; then
            HOL_ID="$(json_path "data.item.idx")"
            [[ "$HOL_ID" =~ ^[0-9]+$ ]] || HOL_ID=0
        fi

        if [[ "$HOL_ID" -gt 0 ]]; then
            pass_case "save_holiday idx" "holiday_id=$HOL_ID"
            if refresh_csrf; then
                api_post "Employee.php" \
                    --data-urlencode "todo=holiday_cancel" \
                    --data-urlencode "holiday_id=${HOL_ID}" \
                    --data-urlencode "csrf_token=${CSRF_TOKEN}"
                assert_http_200_and_ok "holiday_cancel"
                if json_bool_ok; then
                    hol_status="$(json_path "data.item.status")"
                    if [[ "$hol_status" == "CANCELED" ]]; then
                        pass_case "holiday_cancel status" "CANCELED"
                    else
                        fail_case "holiday_cancel status" "expected=CANCELED got=${hol_status:-?}"
                    fi
                fi
            else
                fail_case "csrf refresh before holiday_cancel"
            fi
        else
            fail_case "save_holiday idx" "holiday id missing"
        fi
    else
        fail_case "csrf refresh before save_holiday"
    fi
fi

section "5) Overtime (request/cancel)"
if [[ "$EMP_ID" -le 0 ]]; then
    skip_case "overtime tests" "employee id unavailable"
else
    if refresh_csrf; then
        api_post "Employee.php" \
            --data-urlencode "todo=save_overtime" \
            --data-urlencode "employee_idx=${EMP_ID}" \
            --data-urlencode "work_date=2026-05-01" \
            --data-urlencode "start_time=2026-05-01 19:00:00" \
            --data-urlencode "end_time=2026-05-01 21:00:00" \
            --data-urlencode "reason=smoke overtime" \
            --data-urlencode "csrf_token=${CSRF_TOKEN}"
        assert_http_200_and_ok "save_overtime"
        if json_bool_ok; then
            OT_ID="$(json_path "data.item.idx")"
            [[ "$OT_ID" =~ ^[0-9]+$ ]] || OT_ID=0
        fi

        if [[ "$OT_ID" -gt 0 ]]; then
            pass_case "save_overtime idx" "overtime_id=$OT_ID"
            if refresh_csrf; then
                api_post "Employee.php" \
                    --data-urlencode "todo=overtime_cancel" \
                    --data-urlencode "overtime_id=${OT_ID}" \
                    --data-urlencode "csrf_token=${CSRF_TOKEN}"
                assert_http_200_and_ok "overtime_cancel"
                if json_bool_ok; then
                    ot_status="$(json_path "data.item.status")"
                    if [[ "$ot_status" == "CANCELED" ]]; then
                        pass_case "overtime_cancel status" "CANCELED"
                    else
                        fail_case "overtime_cancel status" "expected=CANCELED got=${ot_status:-?}"
                    fi
                fi
            else
                fail_case "csrf refresh before overtime_cancel"
            fi
        else
            fail_case "save_overtime idx" "overtime id missing"
        fi
    else
        fail_case "csrf refresh before save_overtime"
    fi
fi

section "6) Approval (write/submit/req/dup-guard)"
if [[ "$USER_PK" -le 0 ]]; then
    skip_case "approval tests" "user pk unavailable"
else
    if refresh_csrf; then
        DOC_TITLE="SMOKE_DOC_$(date +%H%M%S)"
        api_post "Approval.php" \
            --data-urlencode "todo=approval_write" \
            --data-urlencode "title=${DOC_TITLE}" \
            --data-urlencode "body_text=smoke body" \
            --data-urlencode "doc_type=GENERAL" \
            --data-urlencode "approver_ids=${USER_PK}" \
            --data-urlencode "csrf_token=${CSRF_TOKEN}"
        assert_http_200_and_ok "approval_write"
        if json_bool_ok; then
            DOC_ID="$(json_path "data.item.idx")"
            [[ "$DOC_ID" =~ ^[0-9]+$ ]] || DOC_ID=0
        fi

        if [[ "$DOC_ID" -gt 0 ]]; then
            pass_case "approval_write idx" "doc_id=$DOC_ID"

            if refresh_csrf; then
                api_post "Approval.php" \
                    --data-urlencode "todo=approval_submit" \
                    --data-urlencode "doc_id=${DOC_ID}" \
                    --data-urlencode "csrf_token=${CSRF_TOKEN}"
                assert_http_200_and_ok "approval_submit"
            else
                fail_case "csrf refresh before approval_submit"
            fi

            api_get "Approval.php" "todo=approval_req"
            assert_http_200_and_ok "approval_req"
            found_req="$(json_contains_idx "data.items" "$DOC_ID")"
            if [[ "$found_req" == "1" ]]; then
                pass_case "approval_req contains submitted doc"
            else
                fail_case "approval_req contains submitted doc" "doc_id=$DOC_ID not listed"
            fi

            if refresh_csrf; then
                api_post "Approval.php" \
                    --data-urlencode "todo=approval_approve" \
                    --data-urlencode "doc_id=${DOC_ID}" \
                    --data-urlencode "comment=smoke approve" \
                    --data-urlencode "csrf_token=${CSRF_TOKEN}"
                assert_http_200_and_ok "approval_approve first"

                if refresh_csrf; then
                    api_post "Approval.php" \
                        --data-urlencode "todo=approval_approve" \
                        --data-urlencode "doc_id=${DOC_ID}" \
                        --data-urlencode "comment=smoke approve duplicate" \
                        --data-urlencode "csrf_token=${CSRF_TOKEN}"
                    assert_http_200_and_fail "approval_approve duplicate guard"
                else
                    fail_case "csrf refresh before duplicate approve"
                fi
            else
                fail_case "csrf refresh before approval_approve"
            fi
        else
            fail_case "approval_write idx" "doc id missing"
        fi
    else
        fail_case "csrf refresh before approval_write"
    fi
fi

section "7) Chat (room/message/read/unread)"
if [[ "$USER_PK" -le 0 ]]; then
    skip_case "chat tests" "user pk unavailable"
else
    if refresh_csrf; then
        ROOM_NAME="SMOKE_ROOM_$(date +%H%M%S)"
        api_post "Chat.php" \
            --data-urlencode "todo=room_create" \
            --data-urlencode "room_name=${ROOM_NAME}" \
            --data-urlencode "room_type=GROUP" \
            --data-urlencode "member_ids=${USER_PK}" \
            --data-urlencode "csrf_token=${CSRF_TOKEN}"
        assert_http_200_and_ok "room_create"
        if json_bool_ok; then
            ROOM_ID="$(json_path "data.item.idx")"
            [[ "$ROOM_ID" =~ ^[0-9]+$ ]] || ROOM_ID=0
        fi

        if [[ "$ROOM_ID" -gt 0 ]]; then
            pass_case "room_create idx" "room_id=$ROOM_ID"

            if refresh_csrf; then
                api_post "Chat.php" \
                    --data-urlencode "todo=message_send" \
                    --data-urlencode "room_idx=${ROOM_ID}" \
                    --data-urlencode "message_text=smoke message" \
                    --data-urlencode "csrf_token=${CSRF_TOKEN}"
                assert_http_200_and_ok "message_send"
                if json_bool_ok; then
                    MSG_ID="$(json_path "data.item.idx")"
                    [[ "$MSG_ID" =~ ^[0-9]+$ ]] || MSG_ID=0
                fi

                if [[ "$MSG_ID" -gt 0 ]]; then
                    pass_case "message_send idx" "message_id=$MSG_ID"

                    if refresh_csrf; then
                        api_post "Chat.php" \
                            --data-urlencode "todo=mark_read" \
                            --data-urlencode "room_idx=${ROOM_ID}" \
                            --data-urlencode "last_message_idx=${MSG_ID}" \
                            --data-urlencode "csrf_token=${CSRF_TOKEN}"
                        assert_http_200_and_ok "mark_read"
                    else
                        fail_case "csrf refresh before mark_read"
                    fi
                else
                    fail_case "message_send idx" "message id missing"
                fi
            else
                fail_case "csrf refresh before message_send"
            fi

            api_get "Chat.php" "todo=unread_count"
            assert_http_200_and_ok "unread_count"
            if json_bool_ok; then
                unread_count="$(json_path "data.unread_count")"
                if [[ "$unread_count" =~ ^[0-9]+$ ]]; then
                    pass_case "unread_count numeric" "value=$unread_count"
                else
                    fail_case "unread_count numeric" "got=${unread_count:-?}"
                fi
            fi
        else
            fail_case "room_create idx" "room id missing"
        fi
    else
        fail_case "csrf refresh before room_create"
    fi
fi

section "8) Cleanup"
cleanup_dept

section "Summary"
TOTAL=$((PASS + FAIL + SKIP))
echo "PASS : $PASS"
echo "FAIL : $FAIL"
echo "SKIP : $SKIP"
echo "TOTAL: $TOTAL"

if [[ ${#FAIL_CASES[@]} -gt 0 ]]; then
    echo
    echo "[FAIL DETAILS]"
    for x in "${FAIL_CASES[@]}"; do
        echo " - $x"
    done
fi

if [[ ${#SKIP_CASES[@]} -gt 0 ]]; then
    echo
    echo "[SKIP DETAILS]"
    for x in "${SKIP_CASES[@]}"; do
        echo " - $x"
    done
fi

if [[ "$FAIL" -gt 0 ]]; then
    exit 1
fi

exit 0
