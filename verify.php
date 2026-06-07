<?php

// Completeness check for the BOLD image harvest.
//
// Takes a random sample of already-searched BINs, re-fetches their image counts
// live from BOLD, and compares against what's stored locally. A BIN that has
// FEWER images stored than the API reports was truncated (old max_images=-1 cap)
// or lost to process-id sampling and should be re-fetched.
//
// Usage:  php verify.php [sample_size]      (default 100)

putenv('HARVEST_NO_RUN=1');
require 'harvest.php';   // reuse get(), fetch_images_for_terms()

$sample_size = isset($argv[1]) ? (int)$argv[1] : 100;

// Random sample of BINs we've recorded as searched.
$bins = array();
foreach (db_get("SELECT id FROM query WHERE id LIKE 'BOLD:%' ORDER BY random() LIMIT " . $sample_size) as $row)
{
	$bins[] = $row->id;
}

echo "-- verifying " . count($bins) . " randomly-sampled BINs\n";

$short    = array();
$checked  = 0;

foreach ($bins as $bin)
{
	// What we have stored.
	$rows = db_get("SELECT COUNT(*) AS n FROM boldcaosimage WHERE bin_uri = '" . str_replace("'", "''", $bin) . "'");
	$stored = (int)$rows[0]->n;

	// What the API reports right now (one BIN per request for an exact per-BIN count).
	$r = fetch_images_for_terms('bin:uri:', array($bin));
	if (!$r['ok'])
	{
		echo "  ? $bin  (fetch failed, skipped)\n";
		continue;
	}
	$live = count($r['images']);
	$checked++;

	$flag = '';
	if ($live > $stored)
	{
		$short[$bin] = array('stored' => $stored, 'live' => $live);
		$flag = "  <-- SHORT (missing " . ($live - $stored) . ")";
	}
	echo sprintf("  %-16s stored=%-5d live=%-5d%s\n", $bin, $stored, $live, $flag);

	usleep(rand(800000, 1500000)); // be polite
}

echo "\n-- checked $checked BINs; " . count($short) . " short\n";

if (count($short) > 0)
{
	echo "-- short BINs (need re-fetch):\n";
	foreach ($short as $bin => $d)
	{
		echo "     $bin  stored={$d['stored']} live={$d['live']}\n";
	}
	echo "\n-- a non-zero count here for the OLD data is expected before you run `php harvest.php rerun`.\n";
}
else
{
	echo "-- all sampled BINs are complete.\n";
}
?>
