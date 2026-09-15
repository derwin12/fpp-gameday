#!/bin/bash
# fpp-gameday uninstall script
set -euo pipefail

FPPDIR="${FPPDIR:-/opt/fpp}"
if [ -f "${FPPDIR}/scripts/ManageApacheContentPolicy.sh" ]; then
    "${FPPDIR}/scripts/ManageApacheContentPolicy.sh" remove img-src https://a.espncdn.com
    "${FPPDIR}/scripts/ManageApacheContentPolicy.sh" remove connect-src https://site.api.espn.com
fi

# Ask FPP to restart fppd so the plugin is unloaded. Required for FPP majors
# before 10, which have no plugin hot-load/unload feature at all.
( set +u; source "${FPPDIR}/scripts/common" && setSetting restartFlag 1 ) || true
