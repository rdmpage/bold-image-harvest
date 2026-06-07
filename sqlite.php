<?php

$config['pdo'] = new PDO('sqlite:boldcaosimage.db');

// Wait up to 30s for a lock rather than failing instantly, so multiple harvest
// processes (e.g. a 'bins' and a 'processid' run) can share the database.
$config['pdo']->exec('PRAGMA busy_timeout = 30000');

//----------------------------------------------------------------------------------------
// retrieve data from database
function db_get($sql)
{
	global $config;
	
	$pdo = $config['pdo'];
	
	$stmt = $pdo->query($sql);

	$data = array();

	while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

		$item = new stdclass;
		
		$keys = array_keys($row);
	
		foreach ($keys as $k)
		{
			if ($row[$k] != '')
			{
				$item->{$k} = $row[$k];
			}
		}
	
		$data[] = $item;
	}	
	return $data;	
}

//----------------------------------------------------------------------------------------
function db_put($sql)
{
	global $config;
	
	$pdo = $config['pdo'];
	
	$stmt = $pdo->prepare($sql);
	
	if (!$stmt)
	{
		echo "\nPDO::errorInfo():\n";
		print_r($pdo->errorInfo());
	}	
	
	$stmt->execute();
	
	if (!$stmt)
	{
		echo "\nPDO::errorInfo():\n";
		print_r($pdo->errorInfo());
	}	
	
}

//----------------------------------------------------------------------------------------
function obj_to_sql($obj, $table_name = 'table')
{
	// to $sql
	$keys = array();
	$values = array();
	
	foreach ($obj as $k => $v)
	{
		$keys[] = '"' . $k . '"'; // must be double quotes
	
		if (is_array($v))
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
	
	//$sql = 'INSERT OR IGNORE INTO `' . $table_name . '` (' . join(",", $keys) . ') VALUES (' . join(",", $values) . ') ON CONFLICT DO NOTHING;';					
	$sql = 'REPLACE INTO `' . $table_name . '` (' . join(",", $keys) . ') VALUES (' . join(",", $values) . ');';					

	return $sql;
}

?>
