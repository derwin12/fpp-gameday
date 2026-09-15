#!/bin/sh
# fpp-gameday preStart
#
# Deliberately does NOT build here -- a synchronous `make` would delay fppd
# startup on every boot. The build happens in fpp_install.sh (fresh install
# and plugin-only update) and in FPP's own core-upgrade path, which rebuilds
# every plugin with a root Makefile before restarting fppd.
#
# We only warn if the shared library is missing so the cause is obvious in
# the log rather than showing up as a silently absent plugin.
BASEDIR=$(dirname "$0")
if [ ! -f "${BASEDIR}/../libfpp-gameday.so" ]; then
    echo "fpp-gameday: libfpp-gameday.so not found - reinstall the plugin to rebuild it."
fi
