#!/bin/bash
# docker/entrypoint.sh — wrapper around the OpenLiteSpeed image entrypoint.
#
# It exists for exactly one reason: to create the compatibility symlink
# /var/www/html -> /var/www/vhosts/localhost/html.
#
# Why: two different docroot paths are documented for this project and both
# must keep working.
#   - AGENTS.md and the dev-stack-ops skill use /var/www/vhosts/localhost/html
#     (the real vhost docroot of litespeedtech/openlitespeed:latest).
#   - .github/skills/dev-stack-ops/scripts/run-tests.sh defaults APP_ROOT to
#     /var/www/html.
#
# The symlink makes both paths address the same tree, so a test run from
# either path sees the same files.
#
# This script deliberately does NOT write config.php. config.php is written by
# install.php (the web installer) in development exactly as it is in
# production — the install gate is "does config.php exist", and an entrypoint
# that pre-creates it would silently bypass the gate.
#
# See docs/issues/issue-001.md.

set -euo pipefail

DOCROOT="/var/www/vhosts/localhost/html"
COMPAT="/var/www/html"

# --- 1. Compatibility symlink for the CLI test harness ---------------------
if [ ! -e "$COMPAT" ]; then
    ln -s "$DOCROOT" "$COMPAT"
    echo "[entrypoint] linked $COMPAT -> $DOCROOT"
elif [ ! -L "$COMPAT" ]; then
    echo "[entrypoint] WARNING: $COMPAT exists and is not a symlink; leaving it alone" >&2
fi

# --- 2. Make sure the docroot is readable by the PHP worker ----------------
# The worker runs as the container user (uid 1000), which matches the owner of
# the bind-mounted files on a typical Linux host, so install.php can write
# config.php without a chown. Only the top-level directory is touched: a
# recursive chown would rewrite the ownership of the whole repository on the
# host.
if [ -d "$DOCROOT" ]; then
    chmod u+rwx "$DOCROOT" 2>/dev/null || true
fi

# --- 3. Hand over to the image entrypoint ----------------------------------
# The image entrypoint seeds the (empty) conf volumes, starts LiteSpeed and
# then stays alive in a supervision loop.
exec /entrypoint.sh "$@"
