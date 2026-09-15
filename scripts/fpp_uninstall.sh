#!/bin/bash
# fpp-gameday uninstall script
set -euo pipefail

FPPDIR="${FPPDIR:-/opt/fpp}"

# Ask FPP to restart fppd so the plugin is unloaded. Required for FPP majors
# before 10, which have no plugin hot-load/unload feature at all. Do this
# first and unconditionally, so a failure below (e.g. CSP entry already
# removed) can never suppress the restart request.
( set +u; source "${FPPDIR}/scripts/common" && setSetting restartFlag 1 ) || true

# Best-effort CSP cleanup -- don't let either call abort the script under
# set -e, since ManageApacheContentPolicy.sh isn't guaranteed idempotent.
if [ -f "${FPPDIR}/scripts/ManageApacheContentPolicy.sh" ]; then
    "${FPPDIR}/scripts/ManageApacheContentPolicy.sh" remove img-src https://a.espncdn.com || true
    "${FPPDIR}/scripts/ManageApacheContentPolicy.sh" remove connect-src https://site.api.espn.com || true
fi
