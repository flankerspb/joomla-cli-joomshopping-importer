<?php

use JoomShoppingImporter\Manager;

define('_JEXEC', 1);
define('FL_JSHOP_IMPORTER', 1);

$self     = basename(__FILE__);

require __DIR__ . '/defines.php';
require __DIR__ . '/src/Manager.php';
require __DIR__ . '/src/ImporterInterface.php';
require __DIR__ . '/src/AbstractImporter.php';
require __DIR__ . '/src/ImporterPortobello.php';
require __DIR__ . '/src/ImporterProject111.php';
require __DIR__ . '/src/Logger.php';

session_start();

$result = [];

if (isset($_SESSION['importer.config.params.result']))
{
	$result = $_SESSION['importer.config.params.result'];

	unset($_SESSION['importer.config.params.result']);
}

if (isset($_GET['action']) && isset($_POST['params']))
{
	switch ($_GET['action'])
	{
		case 'save':
			file_put_contents(IMPORTER_CONFIG_FILE, json_encode($_POST['params'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

			$_SESSION['importer.config.params.result'] = [
					'status'  => 'success',
					'message' => 'Params was saved!',
				];

			break;

		case 'reset':
			unlink(IMPORTER_CONFIG_FILE);

			$_SESSION['importer.config.params.result'] = [
					'status'  => 'warning',
					'message' => 'Params was reset!',
				];

			break;
	}

	header('Location: ' . $self);
}

$params = prepareParams(Manager::prepareConfig());

function prepareParams($array, $root = [])
{
	static $_result = [];

	foreach ($array as $key => $value)
	{
		$path   = $root;
		$path[] = $key;

		$tmp = [
				'level' => count($root),
				'label' => ucfirst(str_replace('_', ' ', $key)),
				'path'  => $path,
			];

		if (is_array($value))
		{
			$_result[] = $tmp;

			prepareParams($value, $path);
		}
		else
		{
			$tmp['value'] = $value;
			$_result[]    = $tmp;
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
	<style>.table {
			width: auto;
		}</style>
</head>
<body>
<center>
	<?php if ($result) : ?>
		<div>
			<div class="alert alert-<?= $result['status']; ?>"><?= $result['message']; ?></div>
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
			<?php foreach ($params as $param) :

				$id = implode('_', $param['path']);
				$name = '[' . implode('][', $param['path']) . ']';
				?>
				<tr>
					<td>
						<?= str_repeat('&mdash;&nbsp;', $param['level']); ?>
						<label for="<?= $id ?>"><?= $param['label']; ?></label>
					</td>

					<td>
						<?php if (array_key_exists('value', $param)) : ?>
							<input type="text" id="<?= $id ?>" name="params<?= $name; ?>" value="<?= $param['value']; ?>">
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<?php if (file_exists(IMPORTER_CONFIG_FILE)) : ?>
				<input class="btn" type="submit" value="Сбросить" formmethod="post"
				       formaction="<?= $self; ?>?action=reset"/>
			<?php endif; ?>
			<input class="btn btn-primary" type="submit" value="Сохранить" formmethod="post"
			       formaction="<?= $self; ?>?action=save"/>
		</p>
	</form>
</center>
<!-- <script type="text/javascript">$('tbody').sortable();</script> -->
</body>
</html>
