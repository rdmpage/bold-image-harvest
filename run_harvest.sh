#!/bin/bash
#
# Auto-resume wrapper for any harvest mode.
#
# `php harvest.php <mode> [file]` stops cleanly whenever BOLD throttles us
# (HTTP 403) or the network drops (HTTP 0) -- the circuit breaker exits after 5
# consecutive failures. All modes are resumable (they skip ids already searched),
# so this wrapper just keeps restarting through those stops until a full pass
# completes ("Done."). Between runs it cools down, then probes the API and only
# resumes once it returns 200.
#
# Usage:
#   nohup ./run_harvest.sh bins bioscan_new_bins.csv  >/dev/null 2>&1 &
#   nohup ./run_harvest.sh processid bioscan_todo.csv >/dev/null 2>&1 &
#   tail -f <mode>_wrapper.log     # progress
#   touch STOP_HARVEST             # stop cleanly after the current pass

cd "$(dirname "$0")" || exit 1

MODE="${1:?usage: run_harvest.sh <mode> [file]}"
FILE="$2"
LOG="${MODE}.log"
WLOG="${MODE}_wrapper.log"
UA='bold-image-harvest/1.0 (mailto:rdmpage@gmail.com)'
PROBE='https://portal.boldsystems.org/api/query?query=ids%3Aprocessid%3AAANIC080-10'

COOLDOWN=300        # seconds to wait after a stop before probing
PROBE_WAIT=120      # seconds between API probes while still blocked
MAX_PROBES=60       # give up probing after this many (then resume anyway)

stamp() { date '+%Y-%m-%d %H:%M:%S'; }

echo "$(stamp) wrapper started (pid $$): mode=$MODE file=${FILE:-<default>}" >> "$WLOG"

pass=0
while true; do
	[ -f STOP_HARVEST ] && { echo "$(stamp) STOP_HARVEST present; exiting." >> "$WLOG"; break; }

	pass=$((pass + 1))
	echo "$(stamp) === pass $pass: php harvest.php $MODE ${FILE} ===" >> "$WLOG"
	php harvest.php "$MODE" ${FILE:+"$FILE"} >> "$LOG" 2>&1

	last=$(tail -1 "$LOG")
	echo "$(stamp) pass $pass ended: $last" >> "$WLOG"

	if echo "$last" | grep -q '^Done\.'; then
		echo "$(stamp) full pass completed; harvest finished." >> "$WLOG"
		break
	fi

	[ -f STOP_HARVEST ] && { echo "$(stamp) STOP_HARVEST present; exiting." >> "$WLOG"; break; }

	echo "$(stamp) stopped early; cooling down ${COOLDOWN}s" >> "$WLOG"
	sleep "$COOLDOWN"

	n=0
	while [ "$n" -lt "$MAX_PROBES" ]; do
		code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 \
			-H "Accept: application/json" -A "$UA" "$PROBE")
		if [ "$code" = "200" ]; then
			echo "$(stamp) API 200; resuming" >> "$WLOG"
			break
		fi
		n=$((n + 1))
		echo "$(stamp) API $code (probe $n/$MAX_PROBES); waiting ${PROBE_WAIT}s" >> "$WLOG"
		sleep "$PROBE_WAIT"
	done
done

echo "$(stamp) wrapper exiting after $pass pass(es)" >> "$WLOG"
