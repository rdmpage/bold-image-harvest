<?php

// BOLD image harvester
//
// Strategy (see notes at end of file):
//   1. Batch several BINs into one query via semicolon-delimited triplets, turning
//      ~2 requests per BIN into ~2 requests per BATCH_SIZE BINs. Each image record
//      carries its own bin_uri, so attribution within a batch is preserved.
//   2. Pass a large max_images: max_images=-1 actually caps results at IMG_LIMIT
//      (50) and returns a random sample, silently truncating image-rich BINs.

require_once (dirname(__FILE__) . '/sqlite.php');

//----------------------------------------------------------------------------------------
// Tunables
define('BATCH_SIZE',  10);        // max terms per request (also bounded by MAX_QUERY_CHARS)
define('MAX_QUERY_CHARS', 240);   // BOLD caps the query string at 250 chars; stay under it.
                                  // processid triplets are longer, so they batch smaller.
define('MAX_IMAGES',  1000000);   // effectively "all" (defeats the IMG_LIMIT=50 cap)
define('SLEEP_MIN_US', 1000000);  // polite delay between batches: 1s ..
define('SLEEP_MAX_US', 3000000);  // .. 3s
define('MAX_RETRIES',  4);        // per-batch retries on transport / HTTP errors

//----------------------------------------------------------------------------------------
// HTTP GET. Returns [body, http_code]; body is null on a transport-level failure.
function get($url)
{
	$opts = array(
		CURLOPT_URL            => $url,
		CURLOPT_FOLLOWLOCATION => TRUE,
		CURLOPT_RETURNTRANSFER => TRUE,
		CURLOPT_HEADER         => FALSE,
		CURLOPT_SSL_VERIFYHOST => FALSE,
		CURLOPT_SSL_VERIFYPEER => FALSE,
		CURLOPT_CONNECTTIMEOUT => 30,
		CURLOPT_TIMEOUT        => 300,
		CURLOPT_HTTPHEADER     => array(
			"Accept: application/json",
		),
		// Identify the harvester so BOLD can contact us rather than just block.
		CURLOPT_USERAGENT      => 'bold-image-harvest/1.0 (mailto:rdmpage@gmail.com)',
	);

	$ch = curl_init();
	curl_setopt_array($ch, $opts);
	$data = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if ($data === false)
	{
		return array(null, 0);
	}

	return array($data, $code);
}

//----------------------------------------------------------------------------------------
// GET a URL and json_decode it, with retry + exponential backoff on transport,
// HTTP, or JSON errors. Returns the decoded object, or null after MAX_RETRIES.
function get_json($url, $what)
{
	for ($attempt = 1; $attempt <= MAX_RETRIES; $attempt++)
	{
		list($body, $code) = get($url);

		if ($body !== null && $code == 200)
		{
			$obj = json_decode($body);
			if ($obj !== null)
			{
				return $obj;
			}
		}

		$backoff = min(60, pow(2, $attempt)) * 1000000; // 2s, 4s, 8s, 16s ...
		echo "  -- $what failed (http $code, attempt $attempt/" . MAX_RETRIES . "), backing off "
		   . round($backoff / 1000000, 1) . "s\n";
		usleep($backoff);
	}

	return null;
}

//----------------------------------------------------------------------------------------
// Turn a single image record (stdClass from the API) into a flat record matching $keys.
function image_to_record($image)
{
	$record = new stdclass;

	foreach ($image as $k => $v)
	{
		if ($k == 'copyright')
		{
			foreach ($v as $ck => $cv)
			{
				$record->{'copyright_' . $ck} = $cv;
			}
		}
		else
		{
			$record->{$k} = $v;
		}
	}

	return $record;
}

//----------------------------------------------------------------------------------------
// Encode one record into a "(v1,v2,...)" VALUES tuple for the given column order.
function record_to_values($record, $keys)
{
	$values = array();

	foreach ($keys as $k)
	{
		$v = isset($record->{$k}) ? $record->{$k} : null;

		if ($v === null || $v === '')
		{
			$values[] = 'NULL';
		}
		elseif (is_array($v))
		{
			$values[] = "'" . str_replace("'", "''", json_encode(array_values($v))) . "'";
		}
		elseif (is_object($v))
		{
			$values[] = "'" . str_replace("'", "''", json_encode($v)) . "'";
		}
		elseif (preg_match('/^POINT/', $v))
		{
			$values[] = "ST_GeomFromText('" . $v . "', 4326)";
		}
		else
		{
			$values[] = "'" . str_replace("'", "''", $v) . "'";
		}
	}

	return '(' . join(',', $values) . ')';
}

//----------------------------------------------------------------------------------------
// Fetch images for a batch of terms (BIN uris or processids) under a triplet
// prefix ('bin:uri:' or 'ids:processid:').
// Returns array('ok' => bool, 'images' => [stdClass, ...]).
//
// Two steps, because the /api/images endpoint only resolves a multi-term query
// after /api/query has materialised it server-side: a locally-built query_id
// works for a single term but 500s for multiple. So:
//   1. GET /api/query  -> server query_id (materialises the result set)
//   2. GET /api/images/{query_id}?max_images=...  -> image metadata (incl. bin_uri,
//      processid), so each image can be attributed back to its term.
// ok = false means a transport/HTTP/JSON failure: callers must NOT mark these terms
// as done, so they get retried on the next run.
function fetch_images_for_terms($prefix, $terms)
{
	$triplets = array();
	foreach ($terms as $t)
	{
		$triplets[] = $prefix . $t;
	}
	$query = join(';', $triplets);

	// Step 1: materialise and get the server's query_id.
	$qurl = 'https://portal.boldsystems.org/api/query?query=' . rawurlencode($query);
	$q = get_json($qurl, 'query');
	if ($q === null || !isset($q->query_id))
	{
		return array('ok' => false, 'images' => array());
	}

	// Step 2: pull the images for that query_id.
	$iurl = 'https://portal.boldsystems.org/api/images/' . $q->query_id
	      . '?max_images=' . MAX_IMAGES
	      . '&assorted_subtaxa=false';
	$response = get_json($iurl, 'images');
	if ($response === null || !isset($response->images))
	{
		return array('ok' => false, 'images' => array());
	}

	return array('ok' => true, 'images' => $response->images);
}

//----------------------------------------------------------------------------------------
// Persist a batch result. $bins were searched; $images are whatever came back
// (each tagged with its own bin_uri). Marks every BIN in the batch as done.
function store_batch($bins, $images, $keys, $tablename)
{
	global $config;
	$pdo = $config['pdo'];

	$pdo->beginTransaction();

	// Insert / update images. On conflict, backfill bin_uri onto rows that may
	// pre-date this column (the original harvest did not store it).
	if (count($images) > 0)
	{
		$tuples = array();
		foreach ($images as $image)
		{
			$tuples[] = record_to_values(image_to_record($image), $keys);
		}

		$columns = array();
		foreach ($keys as $k)
		{
			$columns[] = '"' . $k . '"';
		}

		$sql  = 'INSERT INTO ' . $tablename . ' (' . join(',', $columns) . ') VALUES' . "\n";
		$sql .= join(",\n", $tuples);
		$sql .= "\nON CONFLICT(object_id) DO UPDATE SET bin_uri = excluded.bin_uri;";
		$pdo->exec($sql);
	}

	// Mark every BIN in the batch as searched (even those with no images).
	$stmt = $pdo->prepare('INSERT OR IGNORE INTO query(id) VALUES(?)');
	foreach ($bins as $b)
	{
		$stmt->execute(array($b));
	}

	$pdo->commit();
}

//----------------------------------------------------------------------------------------
// Load the set of already-searched ids into memory once (PK lookups in a tight
// 470k-iteration loop are slow; an in-memory hash is instant).
function load_done_set()
{
	$done = array();
	foreach (db_get('SELECT id FROM query') as $row)
	{
		$done[$row->id] = true;
	}
	return $done;
}

//----------------------------------------------------------------------------------------
// Given a window of candidate processids, return only the "orphans": those NOT
// already captured via a BIN search (absent from boldcaosimage) and NOT already
// searched directly (absent from query). Two indexed IN-queries per window keeps
// memory flat over the 3.4M-row processid list.
function filter_orphan_processids($pids)
{
	if (count($pids) == 0)
	{
		return array();
	}

	$quoted = array();
	foreach ($pids as $p)
	{
		$quoted[] = "'" . str_replace("'", "''", $p) . "'";
	}
	$in = join(',', $quoted);

	$have = array();
	foreach (db_get('SELECT DISTINCT processid FROM boldcaosimage WHERE processid IN (' . $in . ')') as $row)
	{
		$have[$row->processid] = true;
	}
	foreach (db_get('SELECT id FROM query WHERE id IN (' . $in . ')') as $row)
	{
		$have[$row->id] = true;
	}

	$orphans = array();
	foreach ($pids as $p)
	{
		if (!isset($have[$p]))
		{
			$orphans[] = $p;
		}
	}
	return $orphans;
}

//----------------------------------------------------------------------------------------
// Stream terms from a single-column CSV, trimming quotes/whitespace, skipping the
// header row and any term in $skip.
function csv_term_stream($filename, $header, $skip)
{
	$fh = fopen($filename, 'r');
	if ($fh === false)
	{
		echo "cannot open $filename\n";
		exit(1);
	}
	while (!feof($fh))
	{
		$id = str_replace('"', '', trim(fgets($fh)));
		if ($id === '' || $id === $header)
		{
			continue;
		}
		if (isset($skip[$id]))
		{
			continue;
		}
		yield $id;
	}
	fclose($fh);
}

//----------------------------------------------------------------------------------------
// Stream orphan processids from a CSV: reads in windows and yields only those not
// already captured via a BIN or previously searched (see filter_orphan_processids).
function orphan_processid_stream($filename, $window = 500)
{
	$fh = fopen($filename, 'r');
	if ($fh === false)
	{
		echo "cannot open $filename\n";
		exit(1);
	}
	$buf = array();
	while (!feof($fh))
	{
		$id = str_replace('"', '', trim(fgets($fh)));
		if ($id === '' || $id === 'processid')
		{
			continue;
		}
		$buf[] = $id;
		if (count($buf) >= $window)
		{
			foreach (filter_orphan_processids($buf) as $o)
			{
				yield $o;
			}
			$buf = array();
		}
	}
	if (count($buf) > 0)
	{
		foreach (filter_orphan_processids($buf) as $o)
		{
			yield $o;
		}
	}
	fclose($fh);
}

//----------------------------------------------------------------------------------------
// database / columns
$tablename = 'boldcaosimage';

$keys = array(
	"object_id",
	"image_url",
	"thumbnail_url",
	"batch",
	"file_name",
	"processid",
	"sampleid",
	"taxon",
	"meta",
	"copyright_holder",
	"copyright_year",
	"copyright_license",
	"copyright_institution",
	"photographer",
	"bin_uri",          // NEW: attribution, available straight from each image record
);

//----------------------------------------------------------------------------------------
// Allow the file to be included (e.g. for testing the functions above) without
// kicking off the full harvest.
if (getenv('HARVEST_NO_RUN'))
{
	return;
}

//----------------------------------------------------------------------------------------
// Run mode. The plan is BINs first (dense batch fetching), then mop up any
// PROCESSIDs whose images weren't already captured via a BIN ("orphans").
//
//   'bins'      - read BIN uris from all_bins.csv, skipping ones already searched
//   'rerun'     - re-fetch every BIN already in the query table (fixes the old
//                 max_images=-1 truncation and backfills bin_uri)
//   'processid' - read processids from processid.csv, skip orphans already captured
//                 via a BIN or already searched, fetch the rest
$mode = isset($argv[1]) ? $argv[1] : 'bins';

$prefix = 'bin:uri:';   // triplet scope for the current mode
$label  = 'BINs';
$terms  = null;          // an iterable (generator/array) of terms to process

if ($mode == 'bins')
{
	$done  = load_done_set();
	$terms = csv_term_stream('../bold-image-harvest-o/all_bins.csv', 'bin_uri', $done);
	echo "-- mode=bins, " . count($done) . " ids already searched\n";
}
elseif ($mode == 'rerun')
{
	$rows = db_get("SELECT id FROM query WHERE id LIKE 'BOLD:%'");
	$bins = array();
	foreach ($rows as $row)
	{
		$bins[] = $row->id;
	}
	$terms = $bins;
	echo "-- mode=rerun, re-fetching " . count($bins) . " previously-searched BINs\n";
}
elseif ($mode == 'processid')
{
	$prefix = 'ids:processid:';
	$label  = 'processids';
	$terms  = orphan_processid_stream('../bold-image-harvest-o/processid.csv');
	echo "-- mode=processid, fetching orphan processids (not already captured via a BIN)\n";
}
else
{
	echo "unknown mode '$mode' (use 'bins', 'rerun' or 'processid')\n";
	exit(1);
}

//----------------------------------------------------------------------------------------
// Accumulate terms into batches bounded by BATCH_SIZE and MAX_QUERY_CHARS, flushing
// each batch through the two-step fetch and into SQLite.
$batch        = array();
$batch_chars  = 0;
$total_terms  = 0;
$total_images = 0;
$fail_streak  = 0;

$flush = function() use (&$batch, &$batch_chars, &$total_terms, &$total_images, &$fail_streak, $keys, $tablename, $prefix, $label)
{
	if (count($batch) == 0)
	{
		return;
	}

	$result = fetch_images_for_terms($prefix, $batch);

	if (!$result['ok'])
	{
		// Leave these terms unmarked so they're retried next run.
		$fail_streak++;
		echo "-- batch FAILED (not marking " . count($batch) . " $label); fail streak $fail_streak\n";
		if ($fail_streak >= 5)
		{
			echo "Too many consecutive failures; the API may be blocking or down. Stopping.\n";
			exit(1);
		}
		$batch = array();
		$batch_chars = 0;
		return;
	}

	$fail_streak = 0;
	$n = count($result['images']);
	store_batch($batch, $result['images'], $keys, $tablename);

	$total_terms  += count($batch);
	$total_images += $n;
	echo "-- batch of " . count($batch) . " $label -> $n images"
	   . "  (running: $total_terms $label, $total_images images)\n";

	$batch = array();
	$batch_chars = 0;

	usleep(rand(SLEEP_MIN_US, SLEEP_MAX_US));
};

foreach ($terms as $term)
{
	$cost = strlen($prefix) + strlen($term) + 1; // +1 for the ';' separator

	// Flush before this term would overflow either the count or the char budget.
	if (count($batch) > 0 && (count($batch) >= BATCH_SIZE || $batch_chars + $cost > MAX_QUERY_CHARS))
	{
		$flush();
	}

	$batch[] = $term;
	$batch_chars += $cost;
}

// Flush the final partial batch.
$flush();

echo "\nDone. Processed $total_terms $label, stored/updated $total_images images.\n";

/*
Notes on the BOLD portal API (portal.boldsystems.org), reverse-engineered from
the undocumented /openapi.json:

  - /api/query?query=<triplets>&extent=<...> returns {"query_id": "..."} and
    materialises the matching record set server-side. query_id is essentially
    base64url(zlib("<triplets>;<extent>")), BUT a locally-built id only works for a
    single BIN: multi-BIN /api/images returns HTTP 500 unless /api/query has
    materialised that exact query first, so we always call /api/query per batch.

  - Triplets are semicolon-delimited: "bin:uri:BOLD:AAA0001;bin:uri:BOLD:AAA0002".
    Scopes: tax, geo, ids, bin, recordsetcode. The query string has a 250-char
    limit (=> ~11 BINs of the form bin:uri:BOLD:XXXXXXX); BATCH_SIZE stays under it.

  - /api/images/{query_id}?max_images=N returns image metadata INCLUDING bin_uri.
    WARNING: max_images < 0 means "use IMG_LIMIT" (=50) and the endpoint returns a
    RANDOM sample of up to that many images from a random sample of process ids.
    Pass a large max_images to get everything. Very image-rich BINs may still be
    limited by the process-id sampling cap; small batches keep us well clear.

  - /api/documents/{query_id}/download?format=tsv|json|dwc gives full BCDM specimen
    records (no image URLs) and ignores the extent. Useful for bulk record pulls.
*/
?>
