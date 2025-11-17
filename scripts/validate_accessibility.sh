#!/usr/bin/env bash
set -euo pipefail

BASE_URL=${BASE_URL:-http://localhost:8080}
APP_PATH=${APP_PATH:-/chatdev}
PA11Y_BIN=${PA11Y_BIN:-}
STANDARD=${ACCESSIBILITY_STANDARD:-Section508}
OUTPUT_DIR=${ACCESSIBILITY_OUTPUT_DIR:-logs/accessibility}
VERBOSE=${VERBOSE_ACCESSIBILITY:-0}
INCLUDE_NOTICES=${ACCESSIBILITY_INCLUDE_NOTICES:-0}
INCLUDE_WARNINGS=${ACCESSIBILITY_INCLUDE_WARNINGS:-0}

if [[ -z "$PA11Y_BIN" ]]; then
    if command -v pa11y >/dev/null 2>&1; then
        PA11Y_BIN=$(command -v pa11y)
    else
        echo "pa11y is not installed. Install with: npm install -g pa11y" >&2
        exit 1
    fi
fi

if ! command -v jq >/dev/null 2>&1; then
    echo "jq is required to parse pa11y output. Install with: sudo yum install -y jq" >&2
    exit 1
fi

mkdir -p "$OUTPUT_DIR"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
RUN_DIR="$OUTPUT_DIR/run_${TIMESTAMP}"
mkdir -p "$RUN_DIR"
SUMMARY_MD="$RUN_DIR/summary.md"

cat <<EOF_SUMMARY >"$SUMMARY_MD"
# Accessibility Scan Summary
- Timestamp: $TIMESTAMP
- Base URL: ${BASE_URL%/}${APP_PATH}
- Standard: $STANDARD
- Include notices: $INCLUDE_NOTICES
- Include warnings: $INCLUDE_WARNINGS

## Pages
EOF_SUMMARY

SESSION_COOKIE=${ACCESSIBILITY_SESSION_COOKIE:-}
if [[ -z "$SESSION_COOKIE" ]]; then
    SESSION_HELPER="$(dirname "$0")/create_dev_session.php"
    if [[ -x "$SESSION_HELPER" ]]; then
        SESSION_COOKIE=$(php "$SESSION_HELPER" 2>/dev/null || true)
    fi
fi

SCANS_FILE="$RUN_DIR/scans.json"
cat <<'JSON' > "$SCANS_FILE"
[
  { "name": "root", "path": "", "actions": [] },
  { "name": "index", "path": "/index.php", "actions": [] },
  { "name": "upload", "path": "/upload.php", "actions": [] }
]
JSON

EXIT_CODE=0

while read -r SCAN; do
    NAME=$(echo "$SCAN" | jq -r '.name')
    PATH_SUFFIX=$(echo "$SCAN" | jq -r '.path')
    URL="${BASE_URL%/}${APP_PATH}${PATH_SUFFIX}"
    ACTIONS=$(echo "$SCAN" | jq -c '.actions')

    SAFE_NAME=$(echo "${NAME:-root}" | sed 's|[^A-Za-z0-9._-]|_|g')
    REPORT_FILE="$RUN_DIR/pa11y_${SAFE_NAME}.${TIMESTAMP}.json"
    CONFIG_FILE="$RUN_DIR/config_${SAFE_NAME}.${TIMESTAMP}.json"

    [[ $VERBOSE -eq 1 ]] && echo "Running pa11y against $URL"

    CHROME_SECTION=""
    if [[ "$(id -u)" == "0" ]]; then
        CHROME_SECTION=$'  "chromeLaunchConfig": {\n    "args": ["--no-sandbox", "--disable-setuid-sandbox", "--disable-dev-shm-usage", "--headless=new"]\n  },\n'
    fi

    cat <<EOF_CONFIG > "$CONFIG_FILE"
{
  "headers": ["Cookie: ${SESSION_COOKIE:-}"],
${CHROME_SECTION}  "actions": $ACTIONS
}
EOF_CONFIG

    PA11Y_ARGS=("$PA11Y_BIN" "--runner" "axe" "--reporter" "json" "--config" "$CONFIG_FILE")
    if [[ "$STANDARD" == "Section508" ]]; then
        export PA11Y_AXE_RUN_ONLY="tag:section508"
    else
        PA11Y_ARGS+=("--standard" "$STANDARD")
    fi
    (( INCLUDE_NOTICES == 1 )) && PA11Y_ARGS+=("--include-notices")
    (( INCLUDE_WARNINGS == 1 )) && PA11Y_ARGS+=("--include-warnings")

    if [[ "$(id -u)" == "0" ]]; then
        export PA11Y_CHROME_ARGS="${PA11Y_CHROME_ARGS:---no-sandbox --disable-setuid-sandbox --disable-dev-shm-usage --headless=new}"
    fi

    set +e
    "${PA11Y_ARGS[@]}" "$URL" >"$REPORT_FILE"
    RESULT=$?
    set -e

    ISSUE_COUNT="n/a"
    if [[ $RESULT -eq 0 && -f "$REPORT_FILE" ]]; then
        ISSUE_COUNT=$(jq 'length' "$REPORT_FILE" 2>/dev/null || echo "0")
        if [[ "$ISSUE_COUNT" =~ ^[0-9]+$ && "$ISSUE_COUNT" -gt 0 ]]; then
            echo "Accessibility issues reported on $URL (see $REPORT_FILE)"
            EXIT_CODE=1
        else
            [[ $VERBOSE -eq 1 ]] && echo "No issues reported on $URL"
        fi
    else
        echo "Accessibility scan failed on $URL" >&2
        EXIT_CODE=1
    fi

    {
        echo "### $URL"
        echo "- Issues: $ISSUE_COUNT"
        echo "- Report: $(basename "$REPORT_FILE")"
    } >> "$SUMMARY_MD"

    if [[ "$ISSUE_COUNT" =~ ^[0-9]+$ && "$ISSUE_COUNT" -gt 0 ]]; then
        jq -r '.[0:10] | "  - [" + (.code // "n/a") + "] " + (.message // "") + " (selector: " + (if (.selector | type) == "array" then (.selector | join(" → ")) else (.selector // "n/a") end) + ")"' "$REPORT_FILE" >> "$SUMMARY_MD" || true
    fi

    echo >> "$SUMMARY_MD"

done < <(jq -c '.[]' "$SCANS_FILE")

if [[ $EXIT_CODE -eq 0 ]]; then
    echo "Accessibility validation passed for all pages"
else
    echo "Accessibility validation detected issues" >&2
fi

echo "Summary: $SUMMARY_MD"
exit $EXIT_CODE
