#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_LINT_TARGET="${ROOT_DIR}"
PHP_TEST_RUNNER="${ROOT_DIR}/tests/php/run.php"
PYTHON_TEST_DIR="${ROOT_DIR}/tests/python"

export PYTHONPATH="${ROOT_DIR}${PYTHONPATH:+:${PYTHONPATH}}"

cd "${ROOT_DIR}"

printf '\n==> PHP syntax lint\n'
find "$PHP_LINT_TARGET" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
printf 'PHP lint completed successfully.\n'

if [[ "${SKIP_PHP_INTEGRATION_TESTS:-0}" != "1" ]]; then
    printf '\n==> PHP integration tests\n'
    php "$PHP_TEST_RUNNER"
else
    printf '\n==> PHP integration tests (skipped)\n'
fi

printf '\n==> Python bytecode compilation (inc/)\n'
python3 -m compileall inc

printf '\n==> Python unit tests\n'
python3 -m unittest discover -s "$PYTHON_TEST_DIR" -p 'test_*.py'

printf '\nAll validation checks passed.\n'
