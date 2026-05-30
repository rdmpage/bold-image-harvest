<?php

// BOLD API

require_once (dirname(__FILE__) . '/sqlite.php');

//----------------------------------------------------------------------------------------
// Simple HTTP get
function get($url)
{	
	$data = null;

	$opts = array(
	  CURLOPT_URL =>$url,
	  CURLOPT_FOLLOWLOCATION => TRUE,
	  CURLOPT_RETURNTRANSFER => TRUE,
	  
	  CURLOPT_HEADER 		=> FALSE,
	  
	  CURLOPT_SSL_VERIFYHOST=> FALSE,
	  CURLOPT_SSL_VERIFYPEER=> FALSE,
	  
	);

	$opts[CURLOPT_HTTPHEADER] = array(
		"Accept: application/json",
	);
	
	$ch = curl_init();
	curl_setopt_array($ch, $opts);
	$data = curl_exec($ch);
	$info = curl_getinfo($ch); 
	curl_close($ch);
	
	return $data;
}

//----------------------------------------------------------------------------------------
// Convert API result to SQL
function api_to_sql($term, $keys)
{
	$batch = array();

	$url = 'https://portal.boldsystems.org/api/query?query=' . urlencode($term);
	
	$json = get($url);
	
	echo $json . "\n";
	
	$obj = json_decode($json);
	if (!$obj)
	{
		echo "Problem with service\n";
		exit();
	}
	else
	{
		// Step 2:		
		$url = 'https://portal.boldsystems.org/api/images/' . $obj->query_id;
		
		$url .= '?max_images=-1';
		$url .= '&assorted_subtaxa=false';
		
		$json = get($url);
		
		// echo $json;
		
		$response = json_decode($json);
		if ($response && isset($response->images))
		{
			//print_r($response);
			
			// to SQL
			foreach ($response->images as $image)
			{
				$record = new stdclass;
				
				foreach ($image as $k => $v)
				{
					switch ($k)
					{
						case 'copyright':
							foreach ($v as $ck => $cv)
							{
								$copyright_key = 'copyright_' . $ck;
								$record->{$copyright_key} = $cv;
							}
							break;
							
						default:
							$record->{$k} = $v;
							break;
					}
				}
				
				//print_r($record);
				
				// SQL
				$values = array();

				foreach ($keys as $k)
				{
					if (isset($record->{$k}))
					{
						$v = $record->{$k};
					}
					else
					{
						$v = null;
					}
					
					if (!$v)
					{
						$values[] = 'NULL';
					}
					elseif (is_array($v))
					{
						$values[] = "'" . str_replace("'", "''", json_encode(array_values($v))) . "'";
					}
					elseif(is_object($v))
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
				
				$batch[] = '(' . join(",", $values) . ')';
			
			}
		}		
	}

	return $batch;

}

//----------------------------------------------------------------------------------------
function fetch_for_bin($bin_uri, $keys)
{
	$sql = 'REPLACE INTO query(id) VALUES("' . $bin_uri . '")';
	db_put($sql);
	
	return api_to_sql('bin:uri:' . $bin_uri, $keys);
}

//----------------------------------------------------------------------------------------
function fetch_for_processid($processid, $keys)
{
	$sql = 'REPLACE INTO query(id) VALUES("' . $processid . '")';
	db_put($sql);

	return api_to_sql('ids:processid:' . $processid, $keys);
}

//----------------------------------------------------------------------------------------
function have_processid($processid)
{
	// did we look for this directly?
	$sql = 'SELECT * FROM query WHERE id="' . $processid . '" LIMIT 1';
	$data = db_get($sql);
	
	if (count($data) > 0)
	{
		return true;
	}
	
	// did we get it via a bin search?
	$sql = 'SELECT * FROM boldcaosimage WHERE processid="' . $processid . '" LIMIT 1';
	$data = db_get($sql);
	
	if (count($data) > 0)
	{
		return true;
	}	
	
	return false;
}

//----------------------------------------------------------------------------------------
function have_bin($bin_uri)
{
	$sql = 'SELECT * FROM query WHERE id="' . $bin_uri . '" LIMIT 1';	
	$data = db_get($sql);
	
	return count($data) > 0;
}

//----------------------------------------------------------------------------------------
// database
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
	"photographer"
);

$force = false;

$count = 1;

$mode = 'bins';
$mode = 'processid';

if ($mode == 'processid')
{
	$filename = '../bold-image-harvest-o/processid.csv';
	$filename = 'processid.tsv';
}

if ($mode == 'bins')
{
	$filename = '../bold-image-harvest-o/bins.csv';
}

$file_handle = fopen($filename, "r");
while (!feof($file_handle)) 
{
	$id = trim(fgets($file_handle));
	$id = str_replace('"', '', $id);
	
	// empty of a row heading
	if ($id == "" || $id == "bin_uri" || $id == "processid")
	{
		continue;
	}
	
	$batch = [];

	if ($mode == 'processid')
	{
		if (have_processid($id) && !$force)
		{
			echo "Have $id already\n";
			continue;
		}
		else
		{
			$batch = fetch_for_processid($id, $keys);	
			
			if (($count++ % 2) == 0)
			{
				$rand = rand(1000000, 3000000);
				echo "\n-- ...sleeping for " . round(($rand / 1000000),2) . ' seconds' . "\n\n";
				usleep($rand);
			}	
				
		}
	}
	
	if ($mode == 'bins')
	{
		if (have_bin($id) && !$force)
		{
			echo "Have $id already\n";
			continue;
		}
		else
		{
			$batch = fetch_for_bin($id, $keys);	
			
			if (($count++ % 2) == 0)
			{
				$rand = rand(1000000, 3000000);
				echo "\n-- ...sleeping for " . round(($rand / 1000000),2) . ' seconds' . "\n\n";
				usleep($rand);
			}	
				
		}
	}
	
	
	if (count($batch) == 0)
	{
		echo "-- no images found for $id\n";
	}
	else	
	{
		$columns = array();
		foreach ($keys as $k)
		{
			$columns[]  = '"' . $k . '"';
		}

		$sql = 'INSERT INTO ' . $tablename . ' (' . join(',', $columns) . ') VALUES' . "\n";
		$sql .= join(",\n", $batch);
		$sql .= " ON CONFLICT DO NOTHING;";
			
		//echo $sql . "\n";
		
		db_put($sql);
			
	}	
	
}


/*
$bin_uri = 'BOLD:ACY1505';
$batch = fetch_for_bin($bin_uri, $keys);

print_r($batch);
*/
	
/*		
			if (count($batch) == 0)
			{
				echo "-- no images found for $id\n";
			}
			else	
			{
				$columns = array();
				foreach ($keys as $k)
				{
					$columns[]  = '"' . $k . '"';
				}
		
				$sql = 'INSERT INTO ' . $tablename . ' (' . join(',', $columns) . ') VALUES' . "\n";
				$sql .= join(",\n", $batch);
				$sql .= " ON CONFLICT DO NOTHING;";
					
				//echo $sql . "\n";
				
				db_put($sql);
					
				if (($count++ % 10) == 0)
				{
					$rand = rand(1000000, 3000000);
					echo "\n-- ...sleeping for " . round(($rand / 1000000),2) . ' seconds' . "\n\n";
					usleep($rand);
				}	
			}	

*/

?>
