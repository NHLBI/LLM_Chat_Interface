#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_LINT_TARGET="${ROOT_DIR}"
PHP_TEST_RUNNER="${ROOT_DIR}/tests/php/run.php"
PYTHON_TEST_DIR="${ROOT_DIR}/tests/python"

export PYTHONPATH="${ROOT_DIR}${PYTHONPATH:+:${PYTHONPATH}}"

PYTHON_BIN="$(
  php -r 'require_once "get_config.php"; require_once "inc/rag_paths.php"; echo rag_python_binary($config ?? null);'
)"
if [[ -z "${PYTHON_BIN}" || ! -x "${PYTHON_BIN}" ]]; then
  printf '\n[ERROR] RAG python interpreter not found or not executable: %s\n' "${PYTHON_BIN}"
  exit 1
fi

cd "${ROOT_DIR}"

printf '\n==> PHP syntax lint\n'
find "$PHP_LINT_TARGET" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
printf 'PHP lint completed successfully.\n'

# Show session timeout from get_config.php (low sensitivity)
php -r 'require_once "get_config.php"; $t=$config["session"]["timeout"]??null; echo "Session timeout (s): ", ($t===null?"unknown":$t), PHP_EOL;' >/dev/null

# Optional DB connectivity check using get_config.php (no credentials printed)
should_run_php_tests=1
if [[ "${SKIP_PHP_INTEGRATION_TESTS:-0}" != "1" ]]; then
    php -d detect_unicode=0 -r '
        error_reporting(E_ERROR | E_PARSE);
        require_once "'"${ROOT_DIR}"'/get_config.php";
        try {
            $db = $config["database"];
            $dsn = sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $db["host"], $db["dbname"]);
            $pdo = new PDO($dsn, $db["username"], $db["password"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            exit(1);
        }
        exit(0);
    ' >/dev/null 2>&1 || should_run_php_tests=0
fi

if [[ "${SKIP_PHP_INTEGRATION_TESTS:-0}" != "1" ]]; then
    if [[ "${should_run_php_tests}" == "1" ]]; then
        printf '\n==> PHP integration tests\n'
        php "$PHP_TEST_RUNNER"
    else
        printf '\n==> PHP integration tests (skipped: database unavailable)\n'
    fi
else
    printf '\n==> PHP integration tests (skipped)\n'
fi

printf '\n==> Python bytecode compilation (inc/)\n'
"${PYTHON_BIN}" -m compileall inc

printf '\n==> Python unit tests\n'
"${PYTHON_BIN}" -m unittest discover -s "$PYTHON_TEST_DIR" -p 'test_*.py'

printf '\nAll validation checks passed.\n'
