#!/usr/bin/env bash
#
# run-tests.sh — run the Financial Tracker regression suite.
#
# Success is detected by EXIT CODE, not by output markers. Success markers in this
# project are inconsistent (some print "ALL ASSERTIONS PASSED", some prose, some
# nothing), and a missing PHP interpreter produces output that does not contain the
# word "FAIL" — which makes a naive grep-based loop report a FALSE GREEN for every
# test. This script therefore:
#
#   * captures the exit code of every test,
#   * treats exit 127 (command not found) as an ERROR, never a pass,
#   * additionally rejects output containing "ASSERTION(S) FAILED",
#   * and, when the test prints a "Failed: N" summary line, requires N == 0.
#
# Usage:
#   run-tests.sh                       # every database/*_test.php
#   run-tests.sh database/foo_test.php # a single test (repeatable)
#   run-tests.sh --lint                # php -l over the tree
#
# Env:
#   APP_CONTAINER   container name to use instead of "docker compose exec"
#   PHP_BIN         php binary path inside the container (default: php)
#   APP_ROOT        repo root as seen from INSIDE the container
#                   (default: /var/www/html)

set -uo pipefail

# scripts/ -> dev-stack-ops/ -> skills/ -> .github/ -> <repo root>
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$REPO_ROOT" || exit 2

APP_CONTAINER="${APP_CONTAINER:-}"
PHP_BIN="${PHP_BIN:-php}"
APP_ROOT="${APP_ROOT:-/var/www/html}"
TEST_DIR="database"

# --- locate the test files -------------------------------------------------
declare -a TESTS=()
LINT_ONLY=0

for arg in "$@"; do
    case "$arg" in
        --lint) LINT_ONLY=1 ;;
        *) TESTS+=("$arg") ;;
    esac
done

if [ "${#TESTS[@]}" -eq 0 ] && [ "$LINT_ONLY" -eq 0 ]; then
    if [ -d "$TEST_DIR" ]; then
        while IFS= read -r f; do
            TESTS+=("$f")
        done < <(find "$TEST_DIR" -maxdepth 1 -name '*_test.php' | sort)
    fi
fi

# --- container plumbing ----------------------------------------------------
have_compose() { docker compose version >/dev/null 2>&1; }

# Runs a command inside the container with the working directory set to APP_ROOT,
# so relative paths (database/foo_test.php) resolve the same way on both sides.
run_in_app() {
    if [ -n "$APP_CONTAINER" ]; then
        docker exec -w "$APP_ROOT" "$APP_CONTAINER" "$@"
    elif have_compose; then
        docker compose exec -T -w "$APP_ROOT" app "$@"
    else
        echo "ERROR: no container found. Start the stack (docker compose up -d) or set APP_CONTAINER." >&2
        return 127
    fi
}

# --- lint ------------------------------------------------------------------
# Must come after run_in_app is defined.
if [ "$LINT_ONLY" -eq 1 ]; then
    echo "== php -l =="
    run_in_app sh -c 'find . -name "*.php" -not -path "./vendor/*" -not -path "./.git/*" -print0 \
        | xargs -0 -n1 php -l' 2>&1
    exit $?
fi

if [ "${#TESTS[@]}" -eq 0 ]; then
    echo "No tests found in $TEST_DIR/ — nothing to run." >&2
    exit 0
fi

# --- run -------------------------------------------------------------------
PASS=0
FAIL=0
FAILED_TESTS=()

echo "== Running ${#TESTS[@]} test(s) =="

for t in "${TESTS[@]}"; do
    out="$(run_in_app "$PHP_BIN" "$t" 2>&1)"
    code=$?

    if [ "$code" -eq 127 ]; then
        printf '  [ERROR] %s — PHP not found in the container (exit 127)\n' "$t"
        FAIL=$((FAIL + 1)); FAILED_TESTS+=("$t"); continue
    fi

    # A test that prints a "Failed: N" summary line must report N == 0.
    nf="$(printf '%s\n' "$out" | grep -oE '^Failed: [0-9]+' | tail -1 | grep -oE '[0-9]+')"

    if [ "$code" -eq 0 ] && [ "${nf:-0}" = "0" ] && ! printf '%s\n' "$out" | grep -qiE 'ASSERTION\(S\) FAILED'; then
        printf '  [PASS] %s\n' "$t"
        PASS=$((PASS + 1))
    else
        printf '  [FAIL] %s (exit %s)\n' "$t" "$code"
        printf '%s\n' "$out" | sed 's/^/         /'
        FAIL=$((FAIL + 1)); FAILED_TESTS+=("$t")
    fi
done

echo "=== $PASS PASS / $FAIL FAIL ==="

if [ "$FAIL" -gt 0 ]; then
    echo "Failed tests:"
    for t in "${FAILED_TESTS[@]}"; do echo "  - $t"; done
    exit 1
fi

exit 0
