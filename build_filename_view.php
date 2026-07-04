<?php
//
// Build a browsable file_name/collection view over the SHA1 content store.
//
// The canonical local mirror is sha1-sharded (matching B2): a plain
// `rclone sync` / `b2 sync` of the bucket gives you
//   <store>/ab/cd/ef/<sha1>.jpeg
// which dedupes identical images and is integrity-verifiable, but opaque.
//
// This script layers the human-friendly BOLD file_names on top as SYMLINKS, so
// colleagues get a tree organised by collection code with no extra bytes:
//   <view>/AANIC/ANICGenNo.004208+1282715118.jpg -> <store>/06/6d/7a/<sha1>.jpeg
//
// file_name is not unique (~40k collisions, some images shared across hundreds
// of records), so: identical target -> one link (idempotent); a different sha1
// under the same name -> the link is disambiguated with a short sha1 prefix
// rather than silently overwritten.
//
// Usage:
//   php build_filename_view.php <store_root> <view_root>
//
// Symlinks don't survive zip/rsync-without-dereference, so for a portable
// handoff ship the sha1 <store> plus this script and let each machine rebuild
// its own view against its own store path.

putenv('HARVEST_NO_RUN=1');
require_once (dirname(__FILE__) . '/harvest.php');   // $config (PDO), thumb_b2_base()

if ($argc < 3)
{
	echo "usage: php build_filename_view.php <store_root> <view_root>\n";
	exit(1);
}
$store = rtrim($argv[1], '/');
$view  = rtrim($argv[2], '/');

global $config;
$pdo = $config['pdo'];

$made = 0; $collisions = 0; $unchanged = 0; $seen_dirs = array();

$stmt = $pdo->query('SELECT sha1, file_name FROM boldcaosimage'
                  . ' WHERE sha1 IS NOT NULL AND file_name IS NOT NULL');

while ($r = $stmt->fetch(PDO::FETCH_ASSOC))
{
	$target = $store . '/' . thumb_b2_base($r['sha1']) . '.jpeg';
	$link   = $view . '/' . $r['file_name'];

	$dir = dirname($link);
	if (!isset($seen_dirs[$dir]))
	{
		if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
		$seen_dirs[$dir] = true;
	}

	if (is_link($link))
	{
		if (readlink($link) === $target) { $unchanged++; continue; }   // same image
		// Same file_name, different content: disambiguate, don't clobber.
		$link = preg_replace('/(\.[^.\/]+)$/', '.' . substr($r['sha1'], 0, 8) . '$1', $link, 1);
		$collisions++;
	}

	if (@symlink($target, $link)) { $made++; }

	if (($made + $unchanged) % 100000 == 0)
	{
		echo "  .. $made links, $unchanged unchanged, $collisions disambiguated\n";
	}
}

echo "done: $made links created, $unchanged unchanged, $collisions collisions disambiguated\n";
