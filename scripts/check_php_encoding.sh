#!/usr/bin/env bash
# 백엔드 PHP 파일 인코딩/구문/mojibake 가드
# 사용법:
#   bash scripts/check_php_encoding.sh                    # 전체 (dist_library, dist_process, config)
#   bash scripts/check_php_encoding.sh path/to/file.php   # 단일 파일
#   bash scripts/check_php_encoding.sh path/dir           # 디렉토리
# 종료코드: 0 통과 / 1 실패

set -u

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if ! command -v php >/dev/null 2>&1; then
    echo "ERROR: php 명령을 찾을 수 없습니다." >&2
    exit 1
fi

# 인자 없으면 프로젝트 루트 기준 기본 디렉토리 스캔 / 인자 있으면 cwd 그대로
if [ $# -gt 0 ]; then
    TARGETS=("$@")
else
    cd "$PROJECT_ROOT"
    TARGETS=("dist_library" "dist_process" "config")
fi

FAIL_COUNT=0
TOTAL_COUNT=0
FAIL_FILES=()

# mojibake 패턴 (CP949↔UTF-8 깨짐 시 자주 등장하는 토큰)
# - U+FFFD (replacement char) `�`
# - "以묐", "硫붿", "愿由": CP949 한글 → UTF-8 잘못 변환 시 나오는 한자 패턴
# - "꾩슂", "덉뒿", "덈떎", "뙎", "쒕", "苑먯": 한글 자소 깨짐
# - "??'" "??)" "??,": 미종결 mojibake 문자열 끝 패턴
MOJIBAKE_REGEX='�|以묐|硫붿|愿由|꾩슂|덉뒿|덈떎|뙎|뿞|쒕|貂|苑먯|苑됱|이묐|꼟|뼩|뙭|뙑|뙐|뮜|뮕|뮎|뮊|뮌|뱎|뱍|뱋|뱉|뱆|뱅|뱄|뱁|뱀'

check_file() {
    local file="$1"
    local rel="${file#$PROJECT_ROOT/}"
    TOTAL_COUNT=$((TOTAL_COUNT + 1))

    # 1) BOM 검사 (EF BB BF로 시작하면 BOM)
    local first_bytes
    first_bytes=$(head -c 3 "$file" | od -An -tx1 | tr -d ' \n')
    if [ "$first_bytes" = "efbbbf" ]; then
        echo "FAIL [BOM] $rel"
        FAIL_FILES+=("$rel  (BOM 있음)")
        FAIL_COUNT=$((FAIL_COUNT + 1))
        return
    fi

    # 2) UTF-8 mime encoding 검사 (us-ascii도 utf-8 호환이라 통과)
    local enc
    enc=$(file --mime-encoding -b "$file" 2>/dev/null)
    case "$enc" in
        utf-8|us-ascii|binary) ;;
        *)
            echo "FAIL [ENC=$enc] $rel"
            FAIL_FILES+=("$rel  (인코딩 $enc, UTF-8 아님)")
            FAIL_COUNT=$((FAIL_COUNT + 1))
            return
            ;;
    esac

    # 3) PHP 구문 검사
    local lint lrc
    lint=$(php -l "$file" 2>&1)
    lrc=$?
    if [ "$lrc" -ne 0 ]; then
        echo "FAIL [PARSE] $rel"
        echo "$lint" | sed 's/^/    /'
        FAIL_FILES+=("$rel  (PHP 파스 실패)")
        FAIL_COUNT=$((FAIL_COUNT + 1))
        return
    fi

    # 4) mojibake 패턴 검사 (대표 토큰만 — false positive 줄이기)
    if grep -qE "$MOJIBAKE_REGEX" "$file"; then
        echo "FAIL [MOJIBAKE] $rel"
        grep -nE "$MOJIBAKE_REGEX" "$file" | head -5 | sed 's/^/    /'
        FAIL_FILES+=("$rel  (mojibake 패턴 검출)")
        FAIL_COUNT=$((FAIL_COUNT + 1))
        return
    fi
}

scan_target() {
    local target="$1"
    if [ -f "$target" ]; then
        check_file "$target"
    elif [ -d "$target" ]; then
        while IFS= read -r f; do
            check_file "$f"
        done < <(find "$target" -type f -name '*.php')
    else
        echo "WARN: $target 없음 (건너뜀)"
    fi
}

echo "=== PHP 인코딩/구문/mojibake 가드 ==="
for t in "${TARGETS[@]}"; do
    scan_target "$t"
done

echo ""
echo "=== 결과: $((TOTAL_COUNT - FAIL_COUNT))/$TOTAL_COUNT 통과 ==="
if [ $FAIL_COUNT -gt 0 ]; then
    echo "실패 $FAIL_COUNT 건:"
    for f in "${FAIL_FILES[@]}"; do
        echo "  - $f"
    done
    exit 1
fi
echo "전체 통과"
exit 0
