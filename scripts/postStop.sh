#!/bin/sh
# fpp-gameday postStop
#
# Nothing to do: fpp-gameday is a C++ plugin loaded into fppd as a shared
# library (see callbacks.sh), so it has no separate process of its own. Its
# polling threads are stopped by the plugin destructor when fppd shuts down.
