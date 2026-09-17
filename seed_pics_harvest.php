<?php
//
// Seed the harvest queue with the legacy BOLD "pics" images that the CAOS API
// never listed.
//
// Why this exists: the normal harvest is driven by BOLD's CAOS API, so it can
// only ever see images CAOS knows about. Roughly 495k rows in the Hetzner
// `boldimage` table have a perfectly good pics URL but no CAOS object, so they
// were never harvested, have no sha1, and boldview therefore falls back to
// image_proxy.php -- which BOLD now answers with 403 for the server's IP. The
// result is a permanently broken tile. Harvesting the bytes ourselves is the
// only way those images can ever display.
//
// This script does NOT download anything. It inserts placeholder rows into
// boldcaosimage so the existing `thumbnails` mode picks them up unchanged:
//
//   php seed_pics_harvest.php          # insert the queue rows
//   ./run_harvest.sh thumbnails        # harvest them (existing code)
//   php sync_postgres_sha1.php         # push the new sha1s back to Postgres
//
// Usage:
//   php seed_pics_harvest.php --dry-run    # report what would be inserted
//   php seed_pics_harvest.php --limit=20   # seed a handful, for a spot-check
//   php seed_pics_harvest.php              # insert
//
// Requires bold-postgres-upload/env.php for the Postgres credentials.

error_reporting(E_ALL);

define('PICS_PREFIX', 'http://www.boldsystems.org/pics/');
define('INSERT_CHUNK', 5000);   // rows per SQLite transaction

$dry_run = in_array('--dry-run', $argv);

// Cap for spot-checks: seed a few rows, harvest them with
// `php harvest.php thumbnails N`, and confirm the round trip before
// committing to the full half-million.
$limit = 0;
foreach ($argv as $a)
{
	if (preg_match('/^--limit=(\d+)$/', $a, $m)) { $limit = (int)$m[1]; }
}

$sqlite_path = dirname(__FILE__) . '/boldcaosimage.db';
$pg_env      = '/Users/rpage/Development/bold-postgres-upload/env.php';

if (!file_exists($sqlite_path)) { fwrite(STDERR, "no $sqlite_path\n"); exit(1); }
if (!file_exists($pg_env))      { fwrite(STDERR, "no $pg_env\n");      exit(1); }

require_once($pg_env);

function stamp() { return date('Y-m-d H:i:s'); }
function say($m) { echo stamp() . "  $m\n"; flush(); }

// ---------------------------------------------------------------- connections

$lite = new PDO('sqlite:' . $sqlite_path, null, null,
	array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));

// WAL + the same busy timeout the harvest uses: this can be run while a
// thumbnails pass is in flight, and SQLite has a single writer.
$lite->exec('PRAGMA journal_mode = WAL');
$lite->exec('PRAGMA busy_timeout = 30000');

$have = $lite->query("SELECT count(*) FROM sqlite_master"
                   . " WHERE type='table' AND name='boldcaosimage'")->fetchColumn();
if (!$have)
{
	fwrite(STDERR, "no boldcaosimage table in $sqlite_path\n");
	exit(1);
}

// The harvest's own columns are added lazily by thumb_migrate(); this script can
// run before the first thumbnails pass, so make sure they exist.
$cols = array();
foreach ($lite->query('PRAGMA table_info(boldcaosimage)') as $c) { $cols[$c['name']] = true; }
foreach (array('sha1' => 'TEXT', 'size' => 'INTEGER',
               'fetched_at' => 'INTEGER', 'fetch_error' => 'TEXT') as $col => $type)
{
	if (!isset($cols[$col])) { $lite->exec("ALTER TABLE boldcaosimage ADD COLUMN $col $type"); }
}

$pg = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s',
		getenv('POSTGRES_HOST'), getenv('POSTGRES_PORT'), getenv('POSTGRES_DATABASE')),
	getenv('POSTGRES_USERNAME'), getenv('POSTGRES_PASSWORD'),
	array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));

// ------------------------------------------------------------------- the work

// Only rows Postgres still lacks a sha1 for, and only ones whose url actually
// carries the pics prefix we can turn back into a fetchable URL.
say('reading un-harvested pics rows from Postgres');
$q = $pg->query("SELECT url, processid, title, view, license, clean_license"
              . " FROM boldimage"
              . " WHERE sha1 IS NULL"
              . "   AND url LIKE " . $pg->quote(PICS_PREFIX . '%'));

// INSERT OR IGNORE, keyed on the synthetic object_id below, is what makes this
// safe to re-run: a second pass after a partial run (or after more of the
// harvest has landed) inserts only what is genuinely new.
$ins = $lite->prepare(
	'INSERT OR IGNORE INTO boldcaosimage'
	. ' (object_id, image_url, thumbnail_url, file_name, processid,'
	. '  copyright_license, meta)'
	. ' VALUES (?, ?, ?, ?, ?, ?, ?)');

$seen = 0; $queued = 0; $skipped = 0; $pending = array();

$flush = function() use ($lite, $ins, &$pending, &$queued, $dry_run)
{
	if (!count($pending)) { return; }
	if (!$dry_run)
	{
		$lite->beginTransaction();
		foreach ($pending as $p)
		{
			$ins->execute($p);
			$queued += $ins->rowCount();   // 0 when OR IGNORE skipped an existing row
		}
		$lite->commit();
	}
	else
	{
		$queued += count($pending);
	}
	$pending = array();
};

while ($r = $q->fetch(PDO::FETCH_ASSOC))
{
	$seen++;

	// The pics path is stored percent-encoded ALREADY (8k of these carry a %23
	// for a '#' in the museum catalogue number). Slice it out and keep it
	// byte-for-byte: re-encoding it turns a working image into a 404, and it is
	// also the exact string sync_postgres_sha1.php joins back on
	// (PICS_PREFIX || file_name = boldimage.url).
	$file_name = substr($r['url'], strlen(PICS_PREFIX));
	if ($file_name === '' || $file_name === false) { $skipped++; continue; }

	// 'pics:' keeps these out of the CAOS object_id namespace, so a row seeded
	// here can never collide with (or overwrite) a real CAOS object.
	$object_id = 'pics:' . $file_name;

	// thumbnail_url is what run_thumbnails() fetches and what its work query
	// filters on -- a row without it is invisible to the harvest. There is only
	// one size of a pics image, so the original is both the image and the
	// "thumbnail"; at a 22 KB median that is a reasonable thing to store.
	$url = $r['url'];

	// title/view have no column of their own; keep them rather than lose them,
	// since the _info.json sidecar is the provenance record for the bucket.
	$meta = array();
	if ($r['title'] !== null && $r['title'] !== '') { $meta['title'] = $r['title']; }
	if ($r['view']  !== null && $r['view']  !== '') { $meta['view']  = $r['view']; }
	$meta_json = count($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

	// clean_license ('CC-BY') is the tidied form; fall back to the raw string.
	$license = ($r['clean_license'] !== null && $r['clean_license'] !== '')
	         ? $r['clean_license'] : $r['license'];

	$pending[] = array($object_id, $url, $url, $file_name,
	                   $r['processid'], $license, $meta_json);

	if (count($pending) >= INSERT_CHUNK)
	{
		$flush();
		if ($seen % 100000 == 0) { say("  .. read $seen, queued $queued"); }
	}

	if ($limit > 0 && $seen >= $limit) { break; }
}
$flush();

say("read $seen pics rows from Postgres"
  . ($skipped ? ", skipped $skipped with an unusable url" : ""));

if ($dry_run)
{
	say("dry run: $queued rows would be queued (nothing written)");
	exit(0);
}

say("queued $queued new rows for harvest");

// ------------------------------------------------------------------- summary

$todo = $lite->query('SELECT count(*) FROM boldcaosimage'
                   . ' WHERE sha1 IS NULL AND fetch_error IS NULL'
                   . '   AND thumbnail_url IS NOT NULL')->fetchColumn();
say("harvest queue now holds " . number_format($todo) . " images");
say("next: ./run_harvest.sh thumbnails    then    php sync_postgres_sha1.php");

?>
