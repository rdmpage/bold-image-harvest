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

// The 'bins' done-set is an in-memory hash of every searched id (470k+); give PHP
// room for it well beyond the stock 128M.
ini_set('memory_limit', '1024M');

//----------------------------------------------------------------------------------------
// Tunables
define('BATCH_SIZE',  10);        // max terms per request (also bounded by MAX_QUERY_CHARS)
define('MAX_QUERY_CHARS', 240);   // BOLD caps the query string at 250 chars; stay under it.
                                  // processid triplets are longer, so they batch smaller.
define('MAX_IMAGES',  1000000);   // as large as useful; /api/images hard-caps the
                                  // response at 500 images per query regardless.
define('REPAIR_THRESHOLD', 10);   // 'repair' mode re-fetches (one BIN per request, so
                                  // no batch dilution) every BIN with at least this many
                                  // stored images. Lower = catches more diluted BINs,
                                  // but more requests.
define('SLEEP_MIN_US', 1000000);  // polite delay between batches: 1s ..
define('SLEEP_MAX_US', 3000000);  // .. 3s
define('MAX_RETRIES',  4);        // per-batch retries on transport / HTTP errors

// 'thumbnails' mode: download the actual thumbnail bytes for stored image
// records, hash them, and upload to Backblaze B2 named by SHA1. Sequential
// fetching is hopeless at ~9M images (the image host 302-redirects to a signed
// URL, ~2s/image), so downloads run concurrently via curl_multi.
// Both network legs are latency-bound, not bandwidth-bound (~14KB/image), so
// this is the main throughput dial. Override per run: THUMB_CONCURRENCY=60.
// Measured 2026-08-14: 20 -> 2.97 img/s, 40 -> 3.85, 60 -> 3.81 (and 60 starts
// throwing 15s+ B2 upload stalls), so throughput plateaus around 40.
define('THUMB_CONCURRENCY',
	getenv('THUMB_CONCURRENCY') ? (int)getenv('THUMB_CONCURRENCY') : 40);
define('THUMB_CHUNK',        500);      // rows pulled from the DB per iteration
define('THUMB_POLITENESS_US', 200000);  // pause between sub-batches (0.2s)
define('THUMB_FAIL_STREAK',  10);       // consecutive all-failed sub-batches -> stop
                                        // (lets run_harvest.sh cool down & resume)

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
// Fetch many URLs concurrently. $urls is key => url; returns key => [body, code],
// body null on transport failure. Same options as get() (follows the image
// host's 302 to its signed URL). Used by the 'thumbnails' mode; sequential
// fetching of ~9M images would take months.
function get_multi($urls)
{
	// One multi handle for the whole run. libcurl's connection cache hangs off
	// the multi handle, not the easy handles, so keeping it alive lets each
	// sub-batch reuse the sockets the last one opened. Rebuilding it per batch
	// cost a fresh DNS + TCP + TLS per image (~0.77s of a ~2.0s request), paid
	// twice over because the host 302-redirects to a signed URL on another host.
	static $mh = null;
	if ($mh === null)
	{
		$mh = curl_multi_init();
		// Room for both hops of every in-flight request, so a warm socket is
		// never evicted just because the batch is at full concurrency.
		curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, THUMB_CONCURRENCY * 4);
	}
	$handles = array();

	foreach ($urls as $key => $url)
	{
		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL            => $url,
			CURLOPT_FOLLOWLOCATION => TRUE,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_HEADER         => FALSE,
			CURLOPT_SSL_VERIFYHOST => FALSE,
			CURLOPT_SSL_VERIFYPEER => FALSE,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_USERAGENT      => 'bold-image-harvest/1.0 (mailto:rdmpage@gmail.com)',
		));
		curl_multi_add_handle($mh, $ch);
		$handles[$key] = $ch;
	}

	// Drive all transfers to completion.
	do {
		$status = curl_multi_exec($mh, $running);
		if ($running)
		{
			curl_multi_select($mh, 1.0);
		}
	} while ($running && $status == CURLM_OK);

	$results = array();
	foreach ($handles as $key => $ch)
	{
		$body = curl_multi_getcontent($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$results[$key] = array(($body === false || $body === '') ? ($code == 200 ? '' : null) : $body, $code);
		// Removing an easy handle leaves its connection in the multi handle's
		// cache, so closing the easy handle here doesn't drop the socket.
		curl_multi_remove_handle($mh, $ch);
		curl_close($ch);
	}

	return $results;
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

	// Roll back any transaction a previous batch left open (e.g. a silent
	// commit/exec failure under lock contention) so we never fatally hit
	// "there is already an active transaction" on the next begin.
	if ($pdo->inTransaction()) { $pdo->rollBack(); }
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
// 470k-iteration loop are slow; an in-memory hash is instant). Read the id column
// straight from the statement -- db_get() would build a stdClass per row, which
// blows the memory limit once the query table has hundreds of thousands of rows.
function load_done_set()
{
	global $config;
	$done = array();
	$stmt = $config['pdo']->query('SELECT id FROM query');
	while (($id = $stmt->fetchColumn(0)) !== false)
	{
		$done[$id] = true;
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
// 'thumbnails' mode support: download stored thumbnail_urls, hash, upload to B2.
//----------------------------------------------------------------------------------------

// Add the columns the thumbnails pass writes to, if they aren't there yet.
// Resumability hangs off sha1 IS NULL; fetch_error parks permanently-dead URLs
// (404/410) so they aren't retried forever.
function thumb_migrate($pdo)
{
	$cols = array();
	foreach ($pdo->query('PRAGMA table_info(boldcaosimage)') as $r)
	{
		$cols[$r['name']] = true;
	}
	if (!isset($cols['sha1']))        $pdo->exec('ALTER TABLE boldcaosimage ADD COLUMN sha1 TEXT');
	if (!isset($cols['size']))        $pdo->exec('ALTER TABLE boldcaosimage ADD COLUMN size INTEGER');
	if (!isset($cols['fetched_at']))  $pdo->exec('ALTER TABLE boldcaosimage ADD COLUMN fetched_at INTEGER');
	if (!isset($cols['fetch_error'])) $pdo->exec('ALTER TABLE boldcaosimage ADD COLUMN fetch_error TEXT');
	$pdo->exec('CREATE INDEX IF NOT EXISTS sha1_idx ON boldcaosimage(sha1)');
}

// SHA1 -> "ab/cd/ef/<sha1>", the content-store's sharded base path. The content
// store resolver (content.bionames.org) builds paths this way via
// create_path_from_hash(), then appends the extension and "_info.json".
function thumb_b2_base($sha1)
{
	return substr($sha1, 0, 2) . '/' . substr($sha1, 2, 2) . '/' . substr($sha1, 4, 2)
	     . '/' . $sha1;
}

// Write the thumbnail + its _info.json into a local sha1-sharded mirror (same
// layout as B2), so a single harvest pass builds both the bucket and the local
// copy from the bytes already in memory -- no separate B2->local sync (and no
// egress, and no need to filter our thumbnails out of a bucket shared with
// other content). Dedups by file existence. Returns false on a write failure.
function thumb_write_local($root, $sha1, $body, $json)
{
	$base = $root . '/' . thumb_b2_base($sha1);
	$dir  = dirname($base);
	if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir))
	{
		return false;
	}
	$ok = true;
	if (!file_exists($base . '.jpeg'))
	{
		$ok = (@file_put_contents($base . '.jpeg', $body) !== false) && $ok;
	}
	if (!file_exists($base . '_info.json'))
	{
		$ok = (@file_put_contents($base . '_info.json', $json) !== false) && $ok;
	}
	return $ok;
}

// Parse an EXIF rational ("28/10") or number to a float.
function thumb_rational($v)
{
	if ($v === null) { return null; }
	if (is_string($v) && strpos($v, '/') !== false)
	{
		list($n, $d) = explode('/', $v, 2);
		return ($d != 0) ? ($n / $d) : null;
	}
	return is_numeric($v) ? (float)$v : null;
}

// Trim trailing zeros: 3.70 -> "3.7", 2.80 -> "2.8", 5.00 -> "5".
function thumb_num($f)
{
	return rtrim(rtrim(sprintf('%.2f', $f), '0'), '.');
}

// Extract a CURATED, JSON-safe EXIF block from JPEG bytes, or null if the image
// carries no usable EXIF (many BOLD thumbnails are stripped; some keep the full
// camera block). We whitelist scalar tags only -- raw EXIF also holds binary
// maker-note blobs that would corrupt the JSON -- and add human-readable forms
// (aperture "f/2.8", shutter "1/125", focal length "3.7 mm") for a Flickr-style
// display, keeping the raw values alongside for provenance.
function thumb_extract_exif($body)
{
	if (!function_exists('exif_read_data'))
	{
		return null;
	}

	// exif_read_data accepts a stream (PHP >= 7.2), so no temp file on disk.
	$fp = fopen('php://temp', 'r+');
	fwrite($fp, $body);
	rewind($fp);
	$e = @exif_read_data($fp, null, true);
	fclose($fp);
	if (!$e)
	{
		return null;
	}

	$ifd0 = isset($e['IFD0']) ? $e['IFD0'] : array();
	$exif = isset($e['EXIF']) ? $e['EXIF'] : array();
	$gps  = isset($e['GPS'])  ? $e['GPS']  : array();
	$g = function($a, $k) { return isset($a[$k]) ? $a[$k] : null; };

	$out = array();

	// Camera make/model + a combined display string ("Nokia 5530").
	$make  = $g($ifd0, 'Make');
	$model = $g($ifd0, 'Model');
	if ($make)  { $out['make']  = trim($make); }
	if ($model) { $out['model'] = trim($model); }
	if ($make || $model)
	{
		$camera = trim(trim($make) . ' ' . trim($model));
		if ($make && $model && stripos(trim($model), trim($make)) === 0)
		{
			$camera = trim($model);   // model already includes make
		}
		$out['camera'] = $camera;
	}

	// Date taken -> "2010-06-11 14:48:15".
	$dt = $g($exif, 'DateTimeOriginal');
	if (!$dt) { $dt = $g($ifd0, 'DateTime'); }
	if ($dt && preg_match('/^(\d{4}):(\d{2}):(\d{2}) (.+)$/', $dt, $m))
	{
		$out['date_taken'] = "$m[1]-$m[2]-$m[3] $m[4]";
	}
	elseif ($dt)
	{
		$out['date_taken'] = $dt;
	}

	// Shutter speed -> "1/125" (or "1.3s" for long exposures).
	$et = $g($exif, 'ExposureTime');
	if ($et !== null)
	{
		$out['exposure_time'] = $et;   // raw "n/d"
		$f = thumb_rational($et);
		if ($f !== null && $f > 0)
		{
			$out['exposure'] = ($f < 1) ? ('1/' . round(1 / $f)) : (thumb_num($f) . 's');
		}
	}

	// Aperture -> "f/2.8".
	$fn = thumb_rational($g($exif, 'FNumber'));
	if ($fn !== null)
	{
		$out['fnumber']  = round($fn, 1);
		$out['aperture'] = 'f/' . thumb_num($fn);
	}

	// ISO.
	$iso = $g($exif, 'ISOSpeedRatings');
	if (is_array($iso)) { $iso = reset($iso); }
	if ($iso !== null) { $out['iso'] = (int)$iso; }

	// Focal length -> "3.7 mm".
	$fl = thumb_rational($g($exif, 'FocalLength'));
	if ($fl !== null)
	{
		$out['focal_length_mm'] = round($fl, 1);
		$out['focal_length']    = thumb_num($fl) . ' mm';
	}

	// Flash: keep the raw bitmask + a simple "did it fire" flag (bit 0).
	$flash = $g($exif, 'Flash');
	if ($flash !== null)
	{
		$out['flash']       = (int)$flash;
		$out['flash_fired'] = ((int)$flash & 1) === 1;
	}

	$or = $g($ifd0, 'Orientation');
	if ($or !== null) { $out['orientation'] = (int)$or; }

	$cs = $g($exif, 'ColorSpace');
	if ($cs !== null) { $out['color_space'] = ($cs == 1) ? 'sRGB' : (string)$cs; }

	// Original capture dimensions (the thumbnail is downscaled from these).
	$ow = $g($exif, 'ExifImageWidth');
	$oh = $g($exif, 'ExifImageLength');
	if ($ow) { $out['original_width']  = (int)$ow; }
	if ($oh) { $out['original_height'] = (int)$oh; }

	// GPS: DMS rationals + hemisphere ref -> signed decimal degrees.
	if ($gps)
	{
		$dms = function($a, $ref) use ($g)
		{
			if (!is_array($a) || count($a) < 3) { return null; }
			$deg = thumb_rational($a[0]);
			if ($deg === null) { return null; }
			$dec = $deg + thumb_rational($a[1]) / 60 + thumb_rational($a[2]) / 3600;
			if ($ref === 'S' || $ref === 'W') { $dec = -$dec; }
			return round($dec, 6);
		};
		$lat = $dms($g($gps, 'GPSLatitude'),  $g($gps, 'GPSLatitudeRef'));
		$lon = $dms($g($gps, 'GPSLongitude'), $g($gps, 'GPSLongitudeRef'));
		if ($lat !== null && $lon !== null)
		{
			$out['gps'] = array('latitude' => $lat, 'longitude' => $lon);
		}
	}

	return $out ? $out : null;
}

// Download stored thumbnails concurrently, hash, upload to B2 by SHA1, and record
// sha1/size back onto each row. Content-addressed: identical bytes dedupe to one
// object. A row is done once sha1 is set; interrupted runs resume cleanly, and
// run_harvest.sh restarts us after the circuit breaker trips on a run of failures.
function run_thumbnails($limit = 0)
{
	global $config;
	$pdo = $config['pdo'];

	require_once (dirname(__FILE__) . '/env.php');   // B2 credentials (gitignored)
	require_once (dirname(__FILE__) . '/b2.php');

	thumb_migrate($pdo);
	$b2 = new B2();

	// Optional local mirror: set THUMB_MIRROR to a directory (e.g. an external
	// drive or a NAS mount) and each thumbnail is written there too, in the same
	// sha1-sharded layout as B2, as it is uploaded. Configurable per run:
	//   THUMB_MIRROR=/Volumes/Acer/bold-thumbnails ./run_harvest.sh thumbnails
	$mirror = getenv('THUMB_MIRROR');
	$mirror = $mirror ? rtrim($mirror, '/') : null;
	if ($mirror && !is_dir($mirror))
	{
		echo "THUMB_MIRROR '$mirror' is not a directory (drive not mounted?)\n";
		exit(1);
	}

	// Append-only provenance log, so the bucket is reconstructable even if every
	// database is lost: one JSON line per stored thumbnail. The file lives on the
	// external drive and is reached through a symlink here, so bail out loudly if
	// it can't be opened -- a silent failure would lose the provenance for every
	// thumbnail this pass stores.
	// THUMB_MANIFEST overrides the location for a run whose mirror is staged
	// elsewhere (see travel.sh) -- the drive holding the real manifest isn't
	// attached, so the symlink would dangle. Only ever an override: the default
	// stays the symlink, so a normal launch can't silently start a second log.
	$manifest_path = getenv('THUMB_MANIFEST')
	               ? getenv('THUMB_MANIFEST')
	               : dirname(__FILE__) . '/thumbnails_manifest.jsonl';
	$manifest = @fopen($manifest_path, 'a');
	if ($manifest === false)
	{
		echo "Can't open manifest '$manifest_path' for append (drive not mounted?)\n";
		exit(1);
	}

	$have_sha1 = $pdo->prepare('SELECT 1 FROM boldcaosimage WHERE sha1 = ? LIMIT 1');
	$mark_ok   = $pdo->prepare('UPDATE boldcaosimage SET sha1 = ?, size = ?, fetched_at = ? WHERE object_id = ?');
	$mark_err  = $pdo->prepare('UPDATE boldcaosimage SET fetch_error = ?, fetched_at = ? WHERE object_id = ?');

	echo "-- mode=thumbnails, downloading thumbnail_urls (concurrency " . THUMB_CONCURRENCY . ") -> B2"
	   . ($mirror ? " + local mirror $mirror" : "") . "\n";

	$total_ok = 0; $total_dup = 0; $total_err = 0; $fail_streak = 0; $mirror_fail = 0;

	while (true)
	{
		if (file_exists(dirname(__FILE__) . '/STOP_HARVEST'))
		{
			echo "STOP_HARVEST present; stopping.\n";
			break;
		}

		$chunk = ($limit > 0) ? min($limit, THUMB_CHUNK) : THUMB_CHUNK;
		// Pull the whole specimen record: it all goes into the _info.json sidecar
		// so the bucket alone is enough to reconstruct provenance if the DBs die.
		$rows = db_get('SELECT object_id, image_url, thumbnail_url, file_name, processid,'
		             . ' sampleid, taxon, bin_uri, copyright_holder, copyright_year,'
		             . ' copyright_license, copyright_institution, photographer'
		             . ' FROM boldcaosimage'
		             . ' WHERE sha1 IS NULL AND fetch_error IS NULL AND thumbnail_url IS NOT NULL'
		             . ' LIMIT ' . $chunk);

		if (count($rows) == 0)
		{
			echo "\nDone. Uploaded $total_ok thumbnails ($total_dup dup), $total_err permanent errors"
			   . ($mirror ? ", $mirror_fail mirror write failures" : "") . ".\n";
			break;
		}

		foreach (array_chunk($rows, THUMB_CONCURRENCY) as $sub)
		{
			$urls = array();
			foreach ($sub as $i => $r)
			{
				$urls[$i] = $r->thumbnail_url;
			}
			// Per-phase wall clock, for diagnosing where a sub-batch's time goes.
			// Set THUMB_TIMING=1 to append the breakdown to the sub-batch line.
			$timing = getenv('THUMB_TIMING');
			$t_sub = microtime(true);
			$fetched = get_multi($urls);
			$t_download = microtime(true) - $t_sub;

			// Downloads + B2 uploads + local writes happen OUTSIDE any
			// transaction (slow, and would block the metadata DB). We prepare
			// per-row records, upload the whole sub-batch in parallel, then
			// apply the DB updates in one short transaction.
			$updates  = array();   // [object_id, sha1, size]
			$errors   = array();   // [object_id, message]
			$prepared = array();   // successful downloads awaiting upload
			$batch_transient = 0;

			// Phase 1: turn each download into a prepared record (no network).
			foreach ($sub as $i => $r)
			{
				list($body, $code) = $fetched[$i];

				if ($body !== null && $body !== '' && $code == 200)
				{
					$sha1 = sha1($body);
					$size = strlen($body);

					// Build the full provenance record once: it is both the
					// _info.json sidecar and the manifest line, so the two can
					// never diverge. Only columns actually present are included
					// (db_get drops empty ones), keeping the JSON clean.
					$cols = array(
						'thumbnail_url' => 'url',        // the bytes stored here
						'image_url'     => 'image_url',  // full-res original at BOLD
						'object_id'     => 'object_id',
						'file_name'     => 'file_name',
						'processid'     => 'processid',
						'sampleid'      => 'sampleid',
						'taxon'         => 'taxon',
						'bin_uri'       => 'bin_uri',
						'copyright_holder'      => 'copyright_holder',
						'copyright_year'        => 'copyright_year',
						'copyright_license'     => 'copyright_license',
						'copyright_institution' => 'copyright_institution',
						'photographer'          => 'photographer',
					);
					$source = array();
					foreach ($cols as $col => $key)
					{
						if (isset($r->{$col}))
						{
							$source[$key] = $r->{$col};
						}
					}
					// sha1/size/mimetype/md5 stay top-level; mimetype is the one
					// field the content-store resolver actually reads.
					$record = array(
						'sha1'     => $sha1,
						'size'     => $size,
						'md5'      => md5($body),
						'mimetype' => 'image/jpeg',
					);
					// Pixel dimensions (+ depth/channels) straight from the bytes
					// -- no temp file, no EXIF (which BOLD's thumbnails don't carry).
					$dim = @getimagesizefromstring($body);
					if ($dim !== false)
					{
						$record['width']  = $dim[0];
						$record['height'] = $dim[1];
						if (isset($dim['bits']))     { $record['bits']     = $dim['bits']; }
						if (isset($dim['channels'])) { $record['channels'] = $dim['channels']; }
					}
					// Camera EXIF (make/model, exposure, GPS, ...) when the
					// thumbnail carries it -- omitted entirely when it doesn't.
					$exif = thumb_extract_exif($body);
					if ($exif) { $record['exif'] = $exif; }

					$record['source']       = $source;
					$record['harvested_at'] = time();
					// SUBSTITUTE guards against a stray non-UTF-8 byte in an EXIF
					// string turning the whole encode into false.
					$json = json_encode($record, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);

					$have_sha1->execute(array($sha1));
					$dup = (bool)$have_sha1->fetchColumn(0);

					$prepared[] = array(
						'r' => $r, 'sha1' => $sha1, 'size' => $size,
						'body' => $body, 'json' => $json, 'dup' => $dup,
					);
				}
				elseif ($body !== null && $code >= 400 && $code < 500 && $code != 429)
				{
					$errors[] = array($r->object_id, 'http ' . $code);   // permanent: 404/410/etc
					$total_err++;
				}
				elseif ($code == 200 && $body === '')
				{
					// BOLD redirects to presigned object-storage URLs that can
					// resolve to a zero-byte object: a 200 carrying no bytes.
					// There is nothing to retry -- park it like a 404, otherwise
					// it stays in the queue forever and the pass never finishes.
					$errors[] = array($r->object_id, 'empty object (http 200, 0 bytes)');
					$total_err++;
				}
				else
				{
					$batch_transient++;   // transport 0, 5xx, 429: retry next pass
				}
			}

			// Phase 2: upload every non-dup object (jpeg + info.json) in PARALLEL
			// -- the single biggest speedup, since serial B2 PUT latency (~0.5s
			// each, 24 per sub-batch) dominated the wall-clock.
			$items = array(); $img_keys = array();
			foreach ($prepared as $pi => $p)
			{
				if ($p['dup']) { continue; }
				$base = thumb_b2_base($p['sha1']);
				$items['j' . $pi] = array('bytes' => $p['body'], 'name' => $base . '.jpeg',
				                          'sha1' => $p['sha1'], 'mimetype' => 'image/jpeg');
				$items['i' . $pi] = array('bytes' => $p['json'], 'name' => $base . '_info.json',
				                          'sha1' => sha1($p['json']), 'mimetype' => 'application/json');
				$img_keys[$pi] = array('j' . $pi, 'i' . $pi);
			}
			$t_mark = microtime(true);
			$t_prepare = $t_mark - $t_sub - $t_download;
			$upres = count($items) ? $b2->uploadBatch($items, THUMB_CONCURRENCY) : array();
			$t_upload = microtime(true) - $t_mark;
			$n_uploaded = count($items);

			// Phase 3: finalize -- mirror-write and record every image whose
			// uploads succeeded (dups need no upload); failed uploads retry.
			$t_finalize = microtime(true);
			foreach ($prepared as $pi => $p)
			{
				if ($p['dup'])
				{
					$total_dup++;
				}
				elseif (empty($upres[$img_keys[$pi][0]]) || empty($upres[$img_keys[$pi][1]]))
				{
					$batch_transient++;   // an upload failed: retry next pass
					continue;
				}

				// Write-through to the local mirror (independent of B2 dedup:
				// writes only if the file is missing there). If the whole mirror
				// dir has vanished mid-run (unmounted/full), stop rather than
				// silently harvest without mirroring.
				if ($mirror && !thumb_write_local($mirror, $p['sha1'], $p['body'], $p['json']))
				{
					if (!is_dir($mirror))
					{
						echo "Local mirror '$mirror' unavailable (unmounted or full?). Stopping.\n";
						exit(1);
					}
					$mirror_fail++;
				}

				$updates[] = array($p['r']->object_id, $p['sha1'], $p['size']);
				fwrite($manifest, $p['json'] . "\n");
				$total_ok++;
			}

			$t_mirror = microtime(true) - $t_finalize;
			$t_db = microtime(true);

			// Phase 4: record results. Retry the whole transaction on lock
			// contention -- a heavy concurrent metadata harvest (bins/processid)
			// can hold SQLite's single writer, and PDO is in SILENT mode, so we
			// check return values and back off rather than silently dropping the
			// updates (which stranded ~218k already-stored images once). Worst
			// case: warn and leave the rows for the next pass; the images are
			// already safe in B2 / the mirror / the manifest.
			$now = time();
			$committed = false;
			for ($attempt = 1; $attempt <= 8 && !$committed; $attempt++)
			{
				if ($pdo->inTransaction()) { $pdo->rollBack(); }
				$pdo->beginTransaction();
				$ok = true;
				foreach ($updates as $u)
				{
					$ok = $mark_ok->execute(array($u[1], $u[2], $now, $u[0])) && $ok;
				}
				foreach ($errors as $e)
				{
					$ok = $mark_err->execute(array($e[1], $now, $e[0])) && $ok;
				}
				$committed = $ok && $pdo->commit();
				if (!$committed)
				{
					if ($pdo->inTransaction()) { $pdo->rollBack(); }
					usleep(500000 * $attempt);   // 0.5s, 1s, 1.5s ... back off
				}
			}
			if (!$committed)
			{
				echo "-- WARN: DB commit failed after retries; " . count($updates)
				   . " rows retried next pass (images already stored)\n";
			}
			fflush($manifest);

			echo "-- sub-batch " . count($sub) . " -> ok $total_ok, dup $total_dup, err $total_err"
			   . ($batch_transient ? ", transient $batch_transient" : "")
			   . ($mirror_fail ? ", mirror-fail $mirror_fail" : "")
			   . ($timing ? sprintf(" | download %.2fs, prepare %.2fs, upload %.2fs (%d objs),"
			                        . " mirror %.2fs, db %.2fs, total %.2fs",
			                        $t_download, $t_prepare, $t_upload, $n_uploaded,
			                        $t_mirror, microtime(true) - $t_db,
			                        microtime(true) - $t_sub) : "")
			   . "\n";

			// Circuit breaker: a whole sub-batch failing transiently, repeatedly,
			// means the image host or B2 is blocking/down. Stop so the wrapper
			// cools down and resumes.
			if ($batch_transient == count($sub))
			{
				$fail_streak++;
				if ($fail_streak >= THUMB_FAIL_STREAK)
				{
					echo "Too many consecutive failures; the image host or B2 may be blocking or down. Stopping.\n";
					exit(1);
				}
			}
			else
			{
				$fail_streak = 0;
			}

			usleep(THUMB_POLITENESS_US);
		}

		// Spot-check mode: one chunk is enough.
		if ($limit > 0)
		{
			echo "\nLimit reached ($limit): stopping after test run. Uploaded $total_ok ($total_dup dup), $total_err errors.\n";
			break;
		}
	}

	fclose($manifest);
}

//----------------------------------------------------------------------------------------
// Allow the file to be included (e.g. for testing the functions above) without
// kicking off the full harvest.
if (getenv('HARVEST_NO_RUN'))
{
	return;
}

//----------------------------------------------------------------------------------------
// 'thumbnails' is a different job from the metadata modes below (it reads stored
// rows and fetches image bytes, rather than querying BOLD), so branch early.
if (isset($argv[1]) && ($argv[1] == 'thumbnails' || $argv[1] == 'download'))
{
	// Optional cap for spot-checks: `php harvest.php thumbnails 10` fetches ~10
	// and stops. 0/absent = run to completion.
	$limit = isset($argv[2]) ? (int)$argv[2] : 0;
	run_thumbnails($limit);
	exit(0);
}

//----------------------------------------------------------------------------------------
// Run mode. The plan is BINs first (dense batch fetching), then mop up any
// PROCESSIDs whose images weren't already captured via a BIN ("orphans").
//
//   'bins'      - read BIN uris from all_bins.csv, skipping ones already searched
//   'rerun'     - re-fetch every BIN already in the query table (fixes the old
//                 max_images=-1 truncation and backfills bin_uri)
//   'repair'    - re-fetch image-rich BINs ONE PER REQUEST so they aren't diluted by
//                 the per-query process-id sampling that affects batched fetches.
//                 Brings every <=500-image BIN to completeness (BOLD caps /api/images
//                 at 500 images/query, so bigger BINs top out at 500).
//   'processid' - read processids from processid.csv, skip orphans already captured
//                 via a BIN or already searched, fetch the rest
$mode = isset($argv[1]) ? $argv[1] : 'bins';

$prefix      = 'bin:uri:';   // triplet scope for the current mode
$label       = 'BINs';
$terms       = null;         // an iterable (generator/array) of terms to process
$batch_limit = BATCH_SIZE;   // terms per request (forced to 1 for 'repair')

if ($mode == 'bins')
{
	// Optional CLI override of the BIN list:
	//   php harvest.php bins bioscan_new_bins.csv
	$bins_file = isset($argv[2]) ? $argv[2] : '../bold-image-harvest-o/all_bins.csv';
	$done  = load_done_set();
	$terms = csv_term_stream($bins_file, 'bin_uri', $done);
	echo "-- mode=bins, source=$bins_file, " . count($done) . " ids already searched\n";
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
elseif ($mode == 'repair')
{
	// BINs with enough stored images to be plausibly diluted by batching.
	// Optional CLI override: `php harvest.php repair <threshold>`.
	// Resumable: a 'repaired' table records BINs already re-fetched so a run
	// interrupted by throttling picks up where it left off instead of restarting.
	// (To re-accumulate images for huge BINs, DELETE FROM repaired and run again.)
	$config['pdo']->exec('CREATE TABLE IF NOT EXISTS repaired (bin_uri TEXT PRIMARY KEY)');
	$threshold = isset($argv[2]) ? (int)$argv[2] : REPAIR_THRESHOLD;
	$rows = db_get('SELECT bin_uri FROM boldcaosimage WHERE bin_uri IS NOT NULL'
	             . ' GROUP BY bin_uri HAVING COUNT(*) >= ' . $threshold);
	$bins = array();
	foreach ($rows as $row)
	{
		$bins[] = $row->bin_uri;
	}
	$total_rich = count($bins);

	// Skip BINs already repaired in a previous run.
	$done_repair = array();
	$stmt = $config['pdo']->query('SELECT bin_uri FROM repaired');
	while (($b = $stmt->fetchColumn(0)) !== false)
	{
		$done_repair[$b] = true;
	}
	$bins = array_values(array_filter($bins, function($b) use ($done_repair) {
		return !isset($done_repair[$b]);
	}));

	$terms       = $bins;
	$batch_limit = 1; // one BIN per request: no batch dilution
	echo "-- mode=repair, " . $total_rich . " BINs >= " . $threshold . " images; "
	   . count($done_repair) . " already repaired, " . count($bins)
	   . " to go (one per request)\n";
}
elseif ($mode == 'processid')
{
	// Optional CLI override of the source list:
	//   php harvest.php processid bioscan_todo.csv
	// Default is the broad orphan list; bioscan_todo.csv is the high-yield
	// (known-has-image) subset derived from the BIOSCAN-5M dataset.
	$pid_file = isset($argv[2]) ? $argv[2] : '../bold-image-harvest-o/processid.csv';
	$prefix   = 'ids:processid:';
	$label    = 'processids';
	$terms    = orphan_processid_stream($pid_file);
	echo "-- mode=processid, source=$pid_file, fetching processids not already captured/searched\n";
}
else
{
	echo "unknown mode '$mode' (use 'bins', 'rerun', 'repair' or 'processid')\n";
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

$flush = function() use (&$batch, &$batch_chars, &$total_terms, &$total_images, &$fail_streak, $keys, $tablename, $prefix, $label, $mode, $config)
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

	// Record repaired BINs so an interrupted 'repair' run resumes forward.
	if ($mode == 'repair')
	{
		$rs = $config['pdo']->prepare('INSERT OR IGNORE INTO repaired(bin_uri) VALUES(?)');
		foreach ($batch as $b)
		{
			$rs->execute(array($b));
		}
	}

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
	if (count($batch) > 0 && (count($batch) >= $batch_limit || $batch_chars + $cost > MAX_QUERY_CHARS))
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

  - /api/images/{query_id}?max_images=N returns image metadata INCLUDING bin_uri
    and processid, so images can be attributed back to their term. Caps:
      * max_images < 0 means "use IMG_LIMIT" (=50): the OLD bug, silently truncating.
      * Even with a huge max_images, the response is HARD-CAPPED at 500 images/query.
      * Results are a RANDOM sample from a random subset of process ids, so when many
        BINs are batched the per-BIN share is "diluted" below its true count.
    Two consequences we exploit:
      * 'repair' mode re-fetches rich BINs ONE PER REQUEST -> no dilution, up to 500.
      * Because each call samples randomly, re-running 'repair' ACCUMULATES distinct
        images via the object_id upsert, climbing past 500 for very large BINs.

  - processid.csv is a largely DIFFERENT population from the BINs (~0.7% overlap):
    mostly BIN-less specimens. So the processid pass is its own job, not a way to
    backfill images missing from rich BINs - 'repair' handles those.

  - /api/counts?query=<triplets> returns {"records": N} (specimen records, not images).
  - /api/documents/{query_id}/download?format=tsv|json|dwc gives full BCDM specimen
    records (no image URLs) and ignores the extent. Useful for bulk record pulls.
*/
?>
