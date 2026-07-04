#!/bin/bash
#
# Resume the harvest.
#
# Probes the BOLD API first and only launches once it returns 200, so you
# don't kick off a pass straight into a 403 block. The underlying wrapper
# (run_harvest.sh) is resumable -- it skips processids already captured.
#
# Usage:
#   ./resume.sh                       # processid mode, todo.csv (the default)
#   ./resume.sh bins bioscan_new_bins.csv
#   ./resume.sh --force               # launch even if the API probe isn't 200

cd "$(dirname "$0")" || exit 1

FORCE=0
if [ "$1" = "--force" ]; then FORCE=1; shift; fi

MODE="${1:-processid}"
FILE="${2:-todo.csv}"
UA='bold-image-harvest/1.0 (mailto:rdmpage@gmail.com)'
PROBE='https://portal.boldsystems.org/api/query?query=ids%3Aprocessid%3AAANIC080-10'

# Already running?
if pgrep -f "run_harvest.sh $MODE" >/dev/null || pgrep -f "harvest.php $MODE" >/dev/null; then
	echo "Harvest already running ($MODE). Run ./pause.sh first if you want to restart."
	exit 1
fi

if [ -f STOP_HARVEST ]; then
	echo "Removing stale STOP_HARVEST flag."
	rm -f STOP_HARVEST
fi

code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 \
	-H "Accept: application/json" -A "$UA" "$PROBE")
echo "BOLD API probe HTTP $code"

if [ "$code" != "200" ] && [ "$FORCE" -ne 1 ]; then
	echo "API not ready (got $code). Not launching. Use ./resume.sh --force to override."
	exit 1
fi

nohup ./run_harvest.sh "$MODE" "$FILE" >/dev/null 2>&1 &
echo "Launched wrapper (pid $!): mode=$MODE file=$FILE"
echo "Watch: tail -f ${MODE}_wrapper.log"
