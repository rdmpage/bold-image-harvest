<?php
//
// Push harvested SHA1s into the Hetzner Postgres `boldimage` table, so
// boldview can serve images from https://content.bionames.org/sha1/<sha1>
// instead of hitting BOLD.
//
// The join key is the legacy BOLD "pics" URL, which is exactly the file_name
// we already store:
//
//   boldimage.url = 'http://www.boldsystems.org/pics/' || boldcaosimage.file_name
//
// NOT image_url/thumbnail_url -- those are the newer CAOS URLs
// (caos.boldsystems.org/api/objects/<object_id>?subunit=320.jpg).
//
// Idempotent by design: only rows whose sha1 actually changes are written, so
// this can be re-run as the harvest progresses without churning the table.
// Run it again whenever you want boldview to pick up newly harvested images.
//
// Usage:
//   php sync_postgres_sha1.php --dry-run     # report what would change
//   php sync_postgres_sha1.php               # apply
//
// Requires bold-postgres-upload/env.php for the Postgres credentials.

error_reporting(E_ALL);

define('PICS_PREFIX', 'http://www.boldsystems.org/pics/');
define('COPY_CHUNK',   50000);   // rows per COPY into the staging table
define('UPDATE_CHUNK', 500000);  // staging rows per UPDATE statement

$dry_run = in_array('--dry-run', $argv);

$sqlite_path = dirname(__FILE__) . '/boldcaosimage.db';
$pg_env      = '/Users/rpage/Development/bold-postgres-upload/env.php';

if (!file_exists($sqlite_path)) { fwrite(STDERR, "no $sqlite_path\n"); exit(1); }
if (!file_exists($pg_env))      { fwrite(STDERR, "no $pg_env\n");      exit(1); }

require_once($pg_env);

function stamp() { return date('Y-m-d H:i:s'); }
function say($m) { echo stamp() . "  $m\n"; flush(); }

// ---------------------------------------------------------------- connections

// Plain path, no "?mode=ro": PDO's sqlite driver takes the whole DSN tail as a
// filename, so a query string silently creates an empty database instead of
// opening this one read-only. We simply never issue anything but SELECTs; WAL
// lets us read while the harvest keeps writing.
$lite = new PDO('sqlite:' . $sqlite_path, null, null,
	array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));

// Fail loudly rather than staging zero rows and reporting a cheerful success.
$have = $lite->query("SELECT count(*) FROM sqlite_master"
                   . " WHERE type='table' AND name='boldcaosimage'")->fetchColumn();
if (!$have)
{
	fwrite(STDERR, "no boldcaosimage table in $sqlite_path\n");
	exit(1);
}

$pg = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s',
		getenv('POSTGRES_HOST'), getenv('POSTGRES_PORT'), getenv('POSTGRES_DATABASE')),
	getenv('POSTGRES_USERNAME'), getenv('POSTGRES_PASSWORD'),
	array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));

// The column is expected to exist already; create it if someone runs this on a
// fresh database. Nullable with no default, so this is instant on PG 11+.
$pg->exec('ALTER TABLE boldimage ADD COLUMN IF NOT EXISTS sha1 text');

// ------------------------------------------------------------------- staging

// A real (not TEMP) table: the load and the batched UPDATEs are separate
// statements and this is far easier to inspect if something looks wrong.
say('building staging table');
$pg->exec('DROP TABLE IF EXISTS boldimage_sha1_stage');
$pg->exec('CREATE TABLE boldimage_sha1_stage (id bigserial, file_name text, sha1 text)');

$sel = $lite->query('SELECT file_name, sha1 FROM boldcaosimage'
                  . ' WHERE sha1 IS NOT NULL AND file_name IS NOT NULL');

$buf = array(); $loaded = 0;
while ($r = $sel->fetch(PDO::FETCH_NUM))
{
	// COPY text format: the payload is machine-generated (a BOLD file_name and
	// a hex sha1) but escape anyway so a stray tab or backslash can't shift
	// every following column silently.
	$fn = str_replace(array("\\", "\t", "\n", "\r"),
	                  array("\\\\", "\\t", "\\n", "\\r"), $r[0]);
	$buf[] = $fn . "\t" . $r[1];

	if (count($buf) >= COPY_CHUNK)
	{
		$pg->pgsqlCopyFromArray('boldimage_sha1_stage', $buf, "\t", "\\\\",
		                        'file_name,sha1');
		$loaded += count($buf);
		$buf = array();
		if ($loaded % 1000000 == 0) { say("  .. staged $loaded"); }
	}
}
if (count($buf))
{
	$pg->pgsqlCopyFromArray('boldimage_sha1_stage', $buf, "\t", "\\\\", 'file_name,sha1');
	$loaded += count($buf);
}
say("staged $loaded harvested sha1s");

// file_name is not unique (~107 names map to >1 distinct sha1 out of 7.2M), so
// collapse to one row per name before joining -- otherwise the UPDATE picks an
// arbitrary winner and this script stops being deterministic. MIN(sha1) is an
// arbitrary but STABLE choice, which is the property that matters for re-runs.
say('deduplicating staging by file_name');
$pg->exec('CREATE TABLE boldimage_sha1_stage_u AS'
        . ' SELECT DISTINCT ON (file_name) id, file_name, sha1'
        . ' FROM boldimage_sha1_stage ORDER BY file_name, sha1');
$pg->exec('DROP TABLE boldimage_sha1_stage');
$pg->exec('ALTER TABLE boldimage_sha1_stage_u RENAME TO boldimage_sha1_stage');
$pg->exec('CREATE INDEX boldimage_sha1_stage_fn_idx ON boldimage_sha1_stage(file_name)');
$pg->exec('CREATE INDEX boldimage_sha1_stage_id_idx ON boldimage_sha1_stage(id)');
$pg->exec('ANALYZE boldimage_sha1_stage');

$uniq = $pg->query('SELECT count(*) FROM boldimage_sha1_stage')->fetchColumn();
say("$uniq distinct file_names after dedup");

// --------------------------------------------------------------------- apply

// `IS DISTINCT FROM` is what makes this idempotent: a re-run touches only rows
// whose sha1 actually changed, instead of rewriting all 7M every time.
$match_sql = 'FROM boldimage_sha1_stage s'
           . ' WHERE b.url = ' . $pg->quote(PICS_PREFIX) . ' || s.file_name'
           . '   AND b.sha1 IS DISTINCT FROM s.sha1';

if ($dry_run)
{
	say('dry run: counting rows that would change');
	$n = $pg->query('SELECT count(*) FROM boldimage b WHERE EXISTS ('
	              . ' SELECT 1 FROM boldimage_sha1_stage s'
	              . ' WHERE b.url = ' . $pg->quote(PICS_PREFIX) . ' || s.file_name'
	              . '   AND b.sha1 IS DISTINCT FROM s.sha1)')->fetchColumn();
	say("would update $n rows");
	$pg->exec('DROP TABLE IF EXISTS boldimage_sha1_stage');
	say('dry run complete, staging dropped');
	exit(0);
}

// Batched rather than one 7M-row UPDATE: keeps each transaction short so the
// live site never waits on a long lock, and lets autovacuum reclaim dead
// tuples as we go instead of all at once at the end.
$max_id = (int)$pg->query('SELECT COALESCE(MAX(id),0) FROM boldimage_sha1_stage')->fetchColumn();
$updated = 0;

for ($lo = 0; $lo <= $max_id; $lo += UPDATE_CHUNK)
{
	$hi = $lo + UPDATE_CHUNK;
	$sql = 'UPDATE boldimage b SET sha1 = s.sha1'
	     . ' FROM boldimage_sha1_stage s'
	     . ' WHERE b.url = ' . $pg->quote(PICS_PREFIX) . ' || s.file_name'
	     . '   AND b.sha1 IS DISTINCT FROM s.sha1'
	     . '   AND s.id > ' . $lo . ' AND s.id <= ' . $hi;
	$n = $pg->exec($sql);
	$updated += $n;
	say("  .. ids $lo-$hi: $n rows updated (running total $updated)");
}

say("updated $updated rows");

// ------------------------------------------------------------------- summary

$pg->exec('DROP TABLE IF EXISTS boldimage_sha1_stage');

$tot  = $pg->query('SELECT count(*) FROM boldimage')->fetchColumn();
$with = $pg->query('SELECT count(*) FROM boldimage WHERE sha1 IS NOT NULL')->fetchColumn();
printf("%s  boldimage: %s / %s rows have a sha1 (%.1f%%)\n",
	stamp(), number_format($with), number_format($tot), $tot ? 100 * $with / $tot : 0);

?>
