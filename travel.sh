#!/bin/bash
#
# Run the thumbnail harvest while the LaCie is unplugged, then merge back.
#
# B2 is the canonical store and the database lives on the boot volume, so the
# only things that need the external drive are the local mirror and the
# append-only manifest. This stages both on the boot volume, so a day away from
# the drive harvests normally instead of losing ~16k images an hour.
#
# Usage:
#   ./travel.sh start     # harvest into the staging area (drive NOT needed)
#   ./travel.sh merge     # fold staging into the LaCie (drive REQUIRED)
#   ./travel.sh status    # how much is staged, and how much room is left
#
# The mirror is sha1-sharded and content-addressed, so a staged file and its
# counterpart on the drive are byte-identical by construction -- merging is a
# plain copy with no conflict to resolve. The manifest is append-only JSONL, so
# merging it is an append. Nothing is deleted from staging until the merge has
# been verified.

cd "$(dirname "$0")" || exit 1

STAGE="${THUMB_STAGE:-$PWD/staging}"
STAGE_MIRROR="$STAGE/bold-thumbnails"
STAGE_MANIFEST="$STAGE/thumbnails_manifest.jsonl"

LIVE_MIRROR="${THUMB_MIRROR:-/Volumes/LaCie/bold-thumbnails}"
LIVE_MANIFEST="${THUMB_LIVE_MANIFEST:-/Volumes/LaCie/bold-harvest/thumbnails_manifest.jsonl}"

# Staging sits on the boot volume, which runs close to full. ~16k images/hr at
# ~15KB per image (jpeg + sidecar) is roughly 0.25GB/hr, so refuse to start
# without room for a long day rather than wedge the machine.
MIN_FREE_GB=8

free_gb() { df -g "$1" | awk 'NR==2 {print $4}'; }

harvest_running() {
	pgrep -f 'run_harvest.sh thumbnails' >/dev/null \
		|| pgrep -f 'harvest.php thumbnails' >/dev/null
}

case "${1:-start}" in

start)
	if harvest_running; then
		echo "Harvest already running. Run ./pause.sh thumbnails first."
		exit 1
	fi

	free=$(free_gb "$PWD")
	if [ "$free" -lt "$MIN_FREE_GB" ]; then
		echo "Only ${free}GB free on the boot volume (need ${MIN_FREE_GB}GB)."
		echo "Plug the drive in and run ./travel.sh merge to reclaim staged files."
		exit 1
	fi

	if [ -d "$LIVE_MIRROR" ]; then
		echo "Note: the drive is attached -- you probably want a normal run instead:"
		echo "  THUMB_TIMING=1 THUMB_MIRROR=$LIVE_MIRROR nohup ./run_harvest.sh thumbnails >/dev/null 2>&1 &"
		echo "Staging anyway."
	fi

	mkdir -p "$STAGE_MIRROR" || exit 1

	if [ -e "$STAGE_MANIFEST" ]; then
		echo "Note: staging already holds $(wc -l < "$STAGE_MANIFEST" | tr -d ' ') manifest lines"
		echo "from an earlier trip; this run appends to them. Merge when you can."
	fi

	echo "Staging mirror:   $STAGE_MIRROR"
	echo "Staging manifest: $STAGE_MANIFEST"
	echo "Free on boot:     ${free}GB"

	THUMB_TIMING=1 \
	THUMB_MIRROR="$STAGE_MIRROR" \
	THUMB_MANIFEST="$STAGE_MANIFEST" \
	nohup ./run_harvest.sh thumbnails >/dev/null 2>&1 &
	disown
	echo "Launched (pid $!). Watch: tail -f thumbnails_wrapper.log"
	;;

merge)
	if harvest_running; then
		echo "Harvest is running -- stop it first so staging stops changing:"
		echo "  ./pause.sh thumbnails"
		exit 1
	fi

	if [ ! -d "$LIVE_MIRROR" ]; then
		echo "$LIVE_MIRROR not found. Plug the drive in first."
		exit 1
	fi
	if [ ! -d "$STAGE" ]; then
		echo "Nothing staged ($STAGE does not exist)."
		exit 0
	fi

	# Mirror first. --ignore-existing because identical paths are identical
	# bytes here; never overwrite what the drive already holds.
	if [ -d "$STAGE_MIRROR" ]; then
		echo "Merging mirror into $LIVE_MIRROR ..."
		rsync -a --ignore-existing "$STAGE_MIRROR/" "$LIVE_MIRROR/" || {
			echo "rsync failed; staging left untouched."
			exit 1
		}
		staged_files=$(find "$STAGE_MIRROR" -type f | wc -l | tr -d ' ')
		missing=0
		while IFS= read -r f; do
			rel="${f#$STAGE_MIRROR/}"
			[ -f "$LIVE_MIRROR/$rel" ] || missing=$((missing + 1))
		done < <(find "$STAGE_MIRROR" -type f)
		if [ "$missing" -ne 0 ]; then
			echo "$missing of $staged_files staged files are still missing on the drive."
			echo "Staging left untouched."
			exit 1
		fi
		echo "  $staged_files files present on the drive."
	fi

	# Manifest: append-only, so verify by line count before dropping staging.
	if [ -s "$STAGE_MANIFEST" ]; then
		mkdir -p "$(dirname "$LIVE_MANIFEST")" || exit 1
		if [ -f "$LIVE_MANIFEST" ]; then
			before=$(wc -l < "$LIVE_MANIFEST" | tr -d ' ')
		else
			before=0   # first merge onto a drive with no manifest yet
		fi
		add=$(wc -l < "$STAGE_MANIFEST" | tr -d ' ')
		echo "Appending $add manifest lines to $LIVE_MANIFEST (has $before) ..."
		cat "$STAGE_MANIFEST" >> "$LIVE_MANIFEST" || {
			echo "Append failed; staging left untouched."
			exit 1
		}
		after=$(wc -l < "$LIVE_MANIFEST" | tr -d ' ')
		if [ "$after" -ne $((before + add)) ]; then
			echo "Line count is $after, expected $((before + add)). Staging left untouched."
			exit 1
		fi
		echo "  manifest now $after lines."
	fi

	rm -rf "$STAGE"
	echo "Merged and staging cleared. Resume normally:"
	echo "  THUMB_TIMING=1 THUMB_MIRROR=$LIVE_MIRROR nohup ./run_harvest.sh thumbnails >/dev/null 2>&1 &"
	;;

status)
	if [ ! -d "$STAGE" ]; then
		echo "Nothing staged."
	else
		echo "Staged mirror:   $(find "$STAGE_MIRROR" -type f 2>/dev/null | wc -l | tr -d ' ') files, $(du -sh "$STAGE_MIRROR" 2>/dev/null | cut -f1)"
		if [ -f "$STAGE_MANIFEST" ]; then
			echo "Staged manifest: $(wc -l < "$STAGE_MANIFEST" | tr -d ' ') lines"
		else
			echo "Staged manifest: none"
		fi
	fi
	echo "Free on boot:    $(free_gb "$PWD")GB"
	[ -d "$LIVE_MIRROR" ] && echo "Drive:           attached" || echo "Drive:           NOT attached"
	harvest_running && echo "Harvest:         running" || echo "Harvest:         stopped"
	;;

*)
	echo "usage: ./travel.sh [start|merge|status]"
	exit 1
	;;
esac
