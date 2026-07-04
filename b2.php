<?php

// Minimal Backblaze B2 native-API client for the thumbnails upload mode.
//
// Self-contained (reads B2_APPLICATIONKEYID / B2_APPLICATIONKEY from the
// environment -- load env.php first) so the harvester doesn't have to pull in
// the content-store-cloud config, which would open a second PDO and clobber
// $config['pdo']. Uploads straight from an in-memory string: thumbnails are
// ~8KB, so there's no point writing a temp file just to re-read it.
//
// The app key is expected to be BUCKET-SCOPED: b2_get_upload_url takes the
// bucketId straight from the authorization response, so no bucket name is
// needed here.

class B2
{
	private $apiUrl;
	private $accountAuthToken;
	private $bucketId;
	public  $downloadUrl;

	private $uploadUrl;
	private $uploadAuthToken;

	function __construct()
	{
		$this->authorize();
		$this->refreshUploadUrl();
	}

	// b2_authorize_account: exchange the app key for an account auth token +
	// the api/download base URLs. Valid ~24h; re-called on a 401.
	private function authorize()
	{
		$keyId = getenv('B2_APPLICATIONKEYID');
		$key   = getenv('B2_APPLICATIONKEY');
		if (!$keyId || !$key)
		{
			echo "B2 credentials missing (set B2_APPLICATIONKEYID / B2_APPLICATIONKEY in env.php)\n";
			exit(1);
		}

		$ch = curl_init('https://api.backblazeb2.com/b2api/v2/b2_authorize_account');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: Basic ' . base64_encode($keyId . ':' . $key),
		));
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$r = json_decode($body, true);
		if ($code != 200 || !isset($r['authorizationToken']))
		{
			echo "B2 authorize failed (http $code): " . substr((string)$body, 0, 300) . "\n";
			exit(1);
		}

		$this->apiUrl           = $r['apiUrl'];
		$this->accountAuthToken = $r['authorizationToken'];
		$this->downloadUrl      = $r['downloadUrl'];
		$this->bucketId         = $r['allowed']['bucketId'];

		if (!$this->bucketId)
		{
			echo "B2 app key is not bucket-scoped (no allowed.bucketId); cannot derive bucket\n";
			exit(1);
		}
	}

	// b2_get_upload_url: a single-use-ish endpoint + token for uploads. B2 hands
	// out a specific machine; on 401/503 you must fetch a fresh one, which is
	// what uploadBytes() does on failure.
	private function refreshUploadUrl()
	{
		$ch = curl_init($this->apiUrl . '/b2api/v2/b2_get_upload_url');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('bucketId' => $this->bucketId)));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: ' . $this->accountAuthToken,
		));
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$r = json_decode($body, true);
		if ($code != 200 || !isset($r['uploadUrl']))
		{
			return false;
		}

		$this->uploadUrl       = $r['uploadUrl'];
		$this->uploadAuthToken = $r['authorizationToken'];
		return true;
	}

	// One raw upload attempt. Returns the HTTP code (0 on transport failure).
	private function tryUpload($bytes, $b2_filename, $sha1, $mimetype)
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->uploadUrl);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $bytes);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 120);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: ' . $this->uploadAuthToken,
			'X-Bz-File-Name: ' . rawurlencode($b2_filename),
			'Content-Type: ' . $mimetype,
			'Content-Length: ' . strlen($bytes),
			'X-Bz-Content-Sha1: ' . $sha1,
		));
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		// B2 verifies X-Bz-Content-Sha1 server-side, so a 200 means the stored
		// bytes hash to $sha1 -- our content-addressing is confirmed end to end.
		return array($code, $body);
	}

	// List file versions under a prefix. Returns array of {fileName, fileId, ...}.
	// Used by cleanup to find the fileId that b2_delete_file_version requires.
	function listVersions($prefix, $max = 100)
	{
		$ch = curl_init($this->apiUrl . '/b2api/v2/b2_list_file_versions');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
			'bucketId'      => $this->bucketId,
			'startFileName' => $prefix,
			'prefix'        => $prefix,
			'maxFileCount'  => $max,
		)));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: ' . $this->accountAuthToken));
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$r = json_decode($body, true);
		return ($code == 200 && isset($r['files'])) ? $r['files'] : array();
	}

	// Permanently delete one specific file version. Returns true on success.
	function deleteVersion($fileName, $fileId)
	{
		$ch = curl_init($this->apiUrl . '/b2api/v2/b2_delete_file_version');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
			'fileName' => $fileName,
			'fileId'   => $fileId,
		)));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: ' . $this->accountAuthToken));
		curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		return $code == 200;
	}

	// Upload $bytes as $b2_filename (already sha1). Retries with a fresh upload
	// URL, re-authorizing on a 401, up to a few times. Returns true on success.
	function uploadBytes($bytes, $b2_filename, $sha1, $mimetype = 'image/jpeg')
	{
		for ($attempt = 1; $attempt <= 4; $attempt++)
		{
			list($code, $body) = $this->tryUpload($bytes, $b2_filename, $sha1, $mimetype);

			if ($code == 200)
			{
				return true;
			}

			// 401 -> account/upload token expired: re-authorize fully.
			// Anything else (408/429/503/transport 0) -> get a fresh upload URL.
			if ($code == 401)
			{
				$this->authorize();
			}
			$this->refreshUploadUrl();

			usleep(min(8, pow(2, $attempt)) * 1000000); // 2s, 4s, 8s, 8s
		}

		return false;
	}
}

?>
