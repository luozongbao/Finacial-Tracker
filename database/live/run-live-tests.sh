#!/usr/bin/env bash
#
# run-live-tests.sh -- run the LIVE tier against the running application.
#
# There are two tiers:
#
#   SOURCE tier  database/*_test.php     reads the files, drives a sandbox
#                                         copy against a scratch database
#   LIVE tier    database/live/*_test.php  talks to the application that is
#                                         actually serving requests
#
# The LIVE tier exists because the SOURCE tier cannot see a whole class of
# defect: the one that only exists under the WEB SAPI. The install bug that
# blocked v1.0 was exactly that -- PHP_BINARY is empty under LSPHP and
# populated under the CLI SAPI, so the CLI test passed while the wizard could
# never succeed on the shipped server.
#
# It is deliberately NOT part of run-tests.sh: it needs the app container
# running with config.php in place, and it writes to the dev database.
#
# Success is detected by exit code AND by a completion marker. A test that
# dies half way through leaves exit code 0 and no summary -- a handler that
# calls exit(), or a fatal error with display_errors off -- and every tier
# after the point of death never ran. Such a run is an ERROR, never a PASS.
#
# Usage:
#   run-live-tests.sh                          # every database/live/*_test.php
#   run-live-tests.sh database/live/foo.php    # a single test
#
# Env:
#   LIVE_BASE_URL  the application under test. Default http://127.0.0.1,
#                  which is the app from INSIDE the container. On the host
#                  it is http://127.0.0.1:8088.
#   PHP_BIN        php binary path inside the container (default: php)

set -uo pipefail

# scripts/ -> live/ -> database/ -> <repo root>
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT" || exit 2

PHP_BIN="${PHP_BIN:-php}"
LIVE_BASE_URL="${LIVE_BASE_URL:-http://127.0.0.1}"
LIVE_DIR="database/live"

declare -a TESTS=()

if [ "$#" -gt 0 ]; then
    TESTS=("$@")
elif [ -d "$LIVE_DIR" ]; then
    while IFS= read -r f; do
        TESTS+=("$f")
    done < <(find "$LIVE_DIR" -maxdepth 1 -name '*_test.php' | sort)
fi

if [ "${#TESTS[@]}" -eq 0 ]; then
    echo "No live tests found in $LIVE_DIR" >&2
    exit 2
fi

# The tests run INSIDE the container, where the application answers on
# 127.0.0.1. When the caller overrides LIVE_BASE_URL for the host side, the
# value is passed through to the test as well.
export LIVE_BASE_URL

PASS=0
FAIL=0
declare -a FAILED_TESTS=()

echo "== Running ${#TESTS[@]} live test(s) against $LIVE_BASE_URL =="

for t in "${TESTS[@]}"; do
    out="$(docker compose exec -T app "$PHP_BIN" "/var/www/html/$t" 2>&1)"
    code=$?

    # A "Failed: N" summary line must report N == 0.
    nf="$(printf '%s\n' "$out" | grep -oE '^Failed: [0-9]+' | tail -1 | grep -oE '[0-9]+')"

    # The run must also have PRINTED a completion marker. Without one it
    # died somewhere and never finished.
    completed=1
    if ! printf '%s\n' "$out" | grep -qE '^Failed: [0-9]+' \
        && ! printf '%s\n' "$out" | grep -qiE 'ALL ASSERTIONS PASSED'; then
        completed=0
    fi

    if [ "$code" -eq 0 ] && [ "$completed" -eq 1 ] && [ "${nf:-0}" = "0" ] \
        && ! printf '%s\n' "$out" | grep -qiE 'ASSERTION\(S\) FAILED'; then
        printf '  [PASS] %s\n' "$t"
        PASS=$((PASS + 1))
    elif [ "$completed" -eq 0 ]; then
        printf '  [ERROR] %s -- no completion marker: the run ended early (exit %s)\n' "$t" "$code"
        printf '%s\n' "$out" | tail -20 | sed 's/^/         /'
        FAIL=$((FAIL + 1)); FAILED_TESTS+=("$t")
    else
        printf '  [FAIL] %s (exit %s)\n' "$t" "$code"
        printf '%s\n' "$out" | sed 's/^/         /'
        FAIL=$((FAIL + 1)); FAILED_TESTS+=("$t")
    fi
done

echo "=== $PASS PASS / $FAIL FAIL ==="

if [ "$FAIL" -gt 0 ]; then
    echo "Failed tests:"
    for t in "${FAILED_TESTS[@]}"; do
        echo "  - $t"
    done
    exit 1
fi

exit 0
