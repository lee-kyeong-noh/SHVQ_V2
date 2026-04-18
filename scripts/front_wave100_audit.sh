#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

TS="$(TZ=Asia/Seoul date '+%Y-%m-%d %H:%M:%S')"
REPORT="scripts/reports/front_wave100_audit_$(TZ=Asia/Seoul date '+%Y%m%d_%H%M%S').txt"

count_lines() {
  local cmd="$1"
  eval "$cmd" | wc -l | tr -d ' '
}

total_front_files="$(count_lines "rg --files | rg '\\.(php|js|css)$'")"
menu_route_count="$(count_lines "rg -o 'href=\"\\?r=[a-zA-Z0-9_]+' index.php | sed 's/^href=\"\\?r=//' | sort -u")"
route_map_count="$(count_lines "rg -n '^\\s*\x27[a-zA-Z0-9_]+\x27\\s*:' js/core/router.js")"
native_dialog_count="$(count_lines "rg -n '\\b(alert|confirm|prompt)\\s*\\(' js CAD views index.php")"
inline_onclick_count="$(count_lines "rg -n 'onclick=' *.php CAD/*.php CAD/**/*.php views/**/*.php 2>/dev/null || true")"
inline_style_count="$(count_lines "rg -n 'style=\"' *.php CAD/*.php CAD/**/*.php views/**/*.php 2>/dev/null || true")"
todo_marker_count="$(count_lines "rg -n 'TODO|FIXME|HACK|XXX' js css views *.php CAD 2>/dev/null || true")"

missing_routes_file="$(mktemp)"
while IFS= read -r route; do
  if rg -q "^\\s*'${route}'\\s*:" js/core/router.js; then
    continue
  fi
  if [[ -f "${route}.php" ]]; then
    continue
  fi
  echo "$route" >> "$missing_routes_file"
done < <(rg -o 'href="\?r=[a-zA-Z0-9_]+' index.php | sed 's/^href="\?r=//' | sort -u)

missing_route_count="$(wc -l < "$missing_routes_file" | tr -d ' ')"

{
  echo "[SHVQ_V2 Front Wave100 Audit]"
  echo "time=$TS"
  echo "root=$ROOT_DIR"
  echo ""
  echo "total_front_files=$total_front_files"
  echo "menu_route_count=$menu_route_count"
  echo "route_map_count=$route_map_count"
  echo "native_dialog_count=$native_dialog_count"
  echo "inline_onclick_count=$inline_onclick_count"
  echo "inline_style_count=$inline_style_count"
  echo "todo_marker_count=$todo_marker_count"
  echo "missing_route_count=$missing_route_count"
  if [[ "$missing_route_count" != "0" ]]; then
    echo ""
    echo "[missing_routes]"
    cat "$missing_routes_file"
  fi
} | tee "$REPORT"

rm -f "$missing_routes_file"
echo "report_saved=$REPORT"
