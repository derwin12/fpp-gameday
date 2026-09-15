#!/bin/bash
# fpp-gameday install script
set -euo pipefail

BASEDIR=$(dirname "$0")
cd "${BASEDIR}/.."

make "SRCDIR=${SRCDIR:-/opt/fpp/src}"

# Ask FPP to restart fppd so the newly built .so is loaded. Required for FPP
# majors before 10, which have no plugin hot-load/unload feature at all.
( set +u; source "${FPPDIR:-/opt/fpp}/scripts/common" && setSetting restartFlag 1 ) || true

FPPDIR="${FPPDIR:-/opt/fpp}"
if [ -f "${FPPDIR}/scripts/ManageApacheContentPolicy.sh" ]; then
    "${FPPDIR}/scripts/ManageApacheContentPolicy.sh" add img-src https://a.espncdn.com
    "${FPPDIR}/scripts/ManageApacheContentPolicy.sh" add connect-src https://site.api.espn.com
fi
