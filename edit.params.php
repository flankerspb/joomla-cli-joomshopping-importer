<?php
define('_JEXEC', 1);
define('FL_JSHOP_IMPORTER', 1);

require_once(__DIR__ . '/src/JoomShoppingImporter.php');
JoomShoppingImporter::init();

$self = basename(__FILE__);
$cfg_file = JoomShoppingImporter::CFG_PARAMS;

session_start();

$result = [];

if(isset($_SESSION['importer.config.params.result']))
{
	$result = $_SESSION['importer.config.params.result'];
	
	unset($_SESSION['importer.config.params.result']);
}

if(isset($_GET['action']) && isset($_POST['params']))
{
	switch($_GET['action'])
	{
		case 'save':
			file_put_contents($cfg_file, json_encode($_POST['params'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			
			$_SESSION['importer.config.params.result'] =
			[
				'status' => 'success',
				'message' => 'Params was saved!',
			];
			
			break;
			
		case 'reset':
			unlink($cfg_file);
			
			$_SESSION['importer.config.params.result'] =
			[
				'status' => 'warning',
				'message' => 'Params was reset!',
			];
			
			break;
	}
	
	header('Location: ' . $self);
}

$params = JoomShoppingImporter::$params;

$params = prepareParams($params);

function prepareParams($array, $root = [])
{
	static $_result = [];
	
	foreach($array as $key => $value)
	{
		$path = $root;
		$path[] = $key;
		
		$tmp =
		[
			'level'=> count($root),
			'label'=> ucfirst(str_replace('_', ' ', $key)),
			'path' => $path,
		];
		
		if(is_array($value))
		{
			$_result[] = $tmp;
			
			prepareParams($value, $path);
		}
		else
		{
			$tmp['value'] = $value;
			$_result[] = $tmp;
		}
	}
	
	return $_result;
}

?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
<title>Config</title>
<meta name="description" content="">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.11.4/jquery-ui.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.1.1/jquery.js"></script> 
<script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.11.4/jquery-ui.js"></script> 
<style>.table{width: auto;}</style>
</head>
<body>
	<center>
		<?php if($result) : ?>
		<div>
			<div class="alert alert-<?php echo $result['status']; ?>"><?php echo $result['message']; ?></div>
		</div>
		<?php endif; ?>
		<form>
			<table class="table table-striped table-hover table-condensed">
				<thead>
					<tr>
						<th>Key</th>
						<th>Value</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach($params as $param) :
					
					$path = '[' . implode('][', $param['path']). ']';
				?>
					<tr>
						<td>
							<?php echo str_repeat('&mdash;&nbsp;', $param['level']); ?>
							<label><?php echo $param['label']; ?></label>
						</td>
						
						<td>
							<?php if(array_key_exists('value', $param)) : ?>
							<input type="text" name="params<?php echo $path; ?>" value="<?php echo $param['value']; ?>">
							<?php endif; ?>
						</td>
					</tr>
			<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<?php if(file_exists($cfg_file)) : ?>
				<input class="btn" type="submit" value="Сбросить" formmethod="post" formaction="<?php echo $self; ?>?action=reset"/>
				<?php endif; ?>
				<input class="btn btn-primary" type="submit" value="Сохранить" formmethod="post" formaction="<?php echo $self; ?>?action=save"/>
			</p>
		</form>
	</center>
	<!-- <script type="text/javascript">$('tbody').sortable();</script> -->
</body>
</html>