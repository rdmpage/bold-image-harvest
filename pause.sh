#!/bin/bash
#
# Pause the harvest.
#
# Kills the wrapper and worker. Safe to do any time: failed/in-flight batches
# are never marked done, so nothing is lost and ./resume.sh picks up cleanly.
# Does NOT leave a STOP_HARVEST flag behind, so resume stays clean.
#
# Usage:
#   ./pause.sh            # stops the processid harvest (the default)
#   ./pause.sh bins       # stops a different mode

cd "$(dirname "$0")" || exit 1

MODE="${1:-processid}"

pkill -f "run_harvest.sh $MODE" 2>/dev/null
pkill -f "harvest.php $MODE" 2>/dev/null
sleep 2

if pgrep -f "run_harvest.sh $MODE" >/dev/null || pgrep -f "harvest.php $MODE" >/dev/null; then
	echo "Still running -- retrying with SIGKILL."
	pkill -9 -f "run_harvest.sh $MODE" 2>/dev/null
	pkill -9 -f "harvest.php $MODE" 2>/dev/null
	sleep 1
fi

if pgrep -f "run_harvest.sh $MODE" >/dev/null || pgrep -f "harvest.php $MODE" >/dev/null; then
	echo "WARNING: processes still present:"
	ps aux | grep -iE "run_harvest.sh $MODE|harvest.php $MODE" | grep -v grep
	exit 1
fi

echo "Paused ($MODE). No STOP_HARVEST flag left -- ./resume.sh is clean."
