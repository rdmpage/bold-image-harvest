#!/bin/bash
#
# Entry point for the com.rdmpage.bold-thumbnails LaunchAgent.
#
# The agent restarts this whenever the LaCie is mounted (KeepAlive/PathState),
# which is what makes the harvest survive a reboot, a login, or the drive being
# plugged back in. This wrapper exists because launchd can only start or not
# start a job -- it can't decide *whether* it should, so the guards live here.
#
# Not for interactive use: run ./resume.sh or ./travel.sh by hand.

cd "$(dirname "$0")" || exit 1

MIRROR="${THUMB_MIRROR:-/Volumes/LaCie/bold-thumbnails}"

stamp() { date '+%Y-%m-%d %H:%M:%S'; }

# The harvest is finished (or deliberately stopped). Exit successfully and
# cheaply -- KeepAlive will still re-run us every ThrottleInterval, and this
# path is a couple of file checks, no PHP, no database, no B2.
if [ -f STOP_HARVEST ]; then
	exit 0
fi

# PathState should mean the drive is here, but there is a race between the
# volume disappearing and launchd noticing. harvest.php would catch it too;
# catching it here keeps a pointless pass out of the log.
if [ ! -d "$MIRROR" ]; then
	echo "$(stamp) launchd: mirror $MIRROR not mounted; not starting."
	exit 0
fi

# Never race a hand-started harvest: two of them would select the same rows
# (the batch SELECT takes no claim or lease) and re-fetch the same images.
if pgrep -f 'harvest.php thumbnails' >/dev/null \
	|| pgrep -f 'run_harvest.sh thumbnails' >/dev/null; then
	echo "$(stamp) launchd: harvest already running; leaving it alone."
	exit 0
fi

echo "$(stamp) launchd: starting harvest (mirror $MIRROR)"

# Run the wrapper as a child we can signal. When launchd stops the job -- the
# drive is unplugged, or you log out -- it SIGTERMs us, and without this the
# php child would be orphaned and keep writing to a vanishing mirror.
cleanup() {
	if [ -n "$WRAPPER_PID" ]; then
		kill "$WRAPPER_PID" 2>/dev/null
		pkill -P "$WRAPPER_PID" 2>/dev/null
	fi
	pkill -f 'harvest.php thumbnails' 2>/dev/null
	echo "$(stamp) launchd: stopped."
	exit 0
}
trap cleanup TERM INT

./run_harvest.sh thumbnails &
WRAPPER_PID=$!
wait "$WRAPPER_PID"

# run_harvest.sh exits either because the harvest is genuinely complete or
# because it gave up. Only the first should stop the agent from restarting it,
# so translate a completed harvest into the flag the rest of the project
# already understands (resume.sh clears it for you).
if tail -5 thumbnails_wrapper.log 2>/dev/null | grep -q 'harvest finished'; then
	echo "$(stamp) launchd: harvest complete; setting STOP_HARVEST."
	touch STOP_HARVEST
fi
