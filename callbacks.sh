#!/bin/bash
#############################################################################
# fpp-gameday callbacks.sh
# Declares this as a C++ plugin so FPP loads libfpp-gameday.so directly.
#############################################################################

while [ -n "$1" ]; do
    case $1 in
        -l|--list)
            echo "c++"
            ;;
    esac
    shift
done
