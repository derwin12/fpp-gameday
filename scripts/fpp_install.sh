#!/bin/bash
# fpp-gameday install script

BASEDIR=$(dirname $0)
cd $BASEDIR
cd ..
make "SRCDIR=${SRCDIR}"

. ${FPPDIR}/scripts/common
setSetting restartFlag 1

${FPPDIR}/scripts/ManageApacheContentPolicy.sh add img-src https://a.espncdn.com
${FPPDIR}/scripts/ManageApacheContentPolicy.sh add connect-src https://site.api.espn.com
