#!/bin/bash
#
# Auto-resume wrapper for the orphan-processid harvest.
#
# `php harvest.php processid` stops cleanly whenever BOLD throttles us (HTTP 403)
# or the network drops (HTTP 0) -- the circuit breaker exits after 5 consecutive
# failures. It is resumable: each run re-scans processid.csv but skips processids
# already searched, so it makes forward progress. This wrapper just keeps
# restarting it through those stops until a full pass completes ("Done.").
#
# Between runs it waits a cooldown and then probes the API, only resuming once it
# returns 200, so we don't hammer BOLD while still blocked.
#
# Usage:  nohup ./run_processid.sh >/dev/null 2>&1 &
#         tail -f processid_wrapper.log   # progress
#         touch STOP_HARVEST              # asks the wrapper to stop after the current pass

cd "$(dirname "$0")" || exit 1

LOG=processid.log
WLOG=processid_wrapper.log
UA='bold-image-harvest/1.0 (mailto:rdmpage@gmail.com)'
PROBE='https://portal.boldsystems.org/api/query?query=ids%3Aprocessid%3AAANIC080-10'

COOLDOWN=300        # seconds to wait after a stop before probing
PROBE_WAIT=120      # seconds between API probes while still blocked
MAX_PROBES=60       # give up probing after this many (then resume anyway)

stamp() { date '+%Y-%m-%d %H:%M:%S'; }

echo "$(stamp) wrapper started (pid $$)" >> "$WLOG"

pass=0
while true; do
	if [ -f STOP_HARVEST ]; then
		echo "$(stamp) STOP_HARVEST present; exiting before pass" >> "$WLOG"
		break
	fi

	pass=$((pass + 1))
	echo "$(stamp) === pass $pass: php harvest.php processid ===" >> "$WLOG"
	php harvest.php processid >> "$LOG" 2>&1

	last=$(tail -1 "$LOG")
	echo "$(stamp) pass $pass ended: $last" >> "$WLOG"

	# A completed full scan prints "Done. Processed ..." -> we're finished.
	if echo "$last" | grep -q '^Done\.'; then
		echo "$(stamp) full pass completed; harvest finished." >> "$WLOG"
		break
	fi

	[ -f STOP_HARVEST ] && { echo "$(stamp) STOP_HARVEST present; exiting." >> "$WLOG"; break; }

	# Stopped early (block or network). Cool down, then wait for the API.
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
