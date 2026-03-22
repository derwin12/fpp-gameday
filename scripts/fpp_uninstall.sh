#!/bin/bash
# fpp-gameday uninstall script

. ${FPPDIR}/scripts/common
${FPPDIR}/scripts/ManageApacheContentPolicy.sh remove img-src https://a.espncdn.com
${FPPDIR}/scripts/ManageApacheContentPolicy.sh remove connect-src https://site.api.espn.com
