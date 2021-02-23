<?php

use JoomShoppingImporter\Manager;

define('_JEXEC', 1);
define('FL_JSHOP_IMPORTER', 1);

require __DIR__ . '/defines.php';
require __DIR__ . '/src/Manager.php';
require __DIR__ . '/src/ImporterInterface.php';
require __DIR__ . '/src/AbstractImporter.php';
require __DIR__ . '/src/ImporterPortobello.php';
require __DIR__ . '/src/ImporterProject111.php';
require __DIR__ . '/src/Logger.php';

$manager = new Manager(617, 'ru-RU');
$config = $manager->getConfig();
$importers = $manager::getListImporters();

session_start();

$result = [];

if (isset($_SESSION['importer.config.categories.result']))
{
	$result = $_SESSION['importer.config.categories.result'];

	unset($_SESSION['importer.config.categories.result']);
}

$vendor = null;
$cats     = [];

$self = basename(__FILE__);

if (isset($_GET['importer']) && array_key_exists($_GET['importer'], $importers))
{
	$vendor = $_GET['importer'];

	$cats = $manager->getImporter($vendor)->getCategories();

	$page = $self . '?importer=' . $vendor;
}


if (isset($_GET['action']) && isset($_POST['categories']))
{
	switch ($_GET['action'])
	{
		case 'save':

			// foreach($_POST['categories'] as $key => $value)
			// {
			// if(!$value['action'] && !$value['new_title'])
			// {
			// unset($_POST['categories'][$key]);
			// }
			// }

			if (file_exists(IMPORTER_CATEGORIES_FILE))
			{
				$categories = json_decode(file_get_contents(IMPORTER_CATEGORIES_FILE), true);
			}
			else
			{
				$categories = [];
			}

			$categories[$config[$vendor]['vendor_id']] = $_POST['categories'];

			file_put_contents(IMPORTER_CATEGORIES_FILE, json_encode($categories, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

			$_SESSION['importer.config.categories.result'] = [
					'status'  => 'success',
					'message' => 'categories was saved!',
				];

			break;

		case 'reset':
			unlink(IMPORTER_CATEGORIES_FILE);

			$_SESSION['importer.config.categories.result'] = [
					'status'  => 'warning',
					'message' => 'categories was reset!',
				];

			break;
	}

	header('Location: ' . $page);
}

function getImporterOptions($importer)
{
	$_result = [];

	foreach (Manager::getListImporters() as $key => $value)
	{
		$selected = ($importer == $key) ? ' selected' : '';

		$_result[] = '<option value="' . $key . '"' . $selected . '>' . $key . '</option>';
	}

	return implode('', $_result);
}

function getActionOptions($item)
{
	$values = [
		''              => '-No action-',
		'skip'          => 'Skip',
		'join'          => 'Join to Category',
		'move'          => 'Move to Category',
		'is_extrafield' => 'Is Extra Field'
	];

	$_result = [];

	foreach ($values as $key => $value)
	{
		$selected = ($key == $item['action']) ? ' selected' : '';

		$_result[] = '<option value="' . $key . '"' . $selected . '>' . $value . '</option>';
	}

	return implode('', $_result);
}

?>
<!doctype html>
<html lang="ru">
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
	<?php if ($vendor) : ?>
		<title>Categories - <?php echo $vendor; ?></title>
	<?php else : ?>
		<title>Categories - Select importer</title>
	<?php endif; ?>
	<meta name="description" content="">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.11.4/jquery-ui.css" rel="stylesheet">
	<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.1.1/jquery.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.11.4/jquery-ui.js"></script>
	<style>
		.table {
			width: auto;
		}
		.del {
			text-decoration-line: line-through;
		}
	</style>
</head>
<body>
<center>
	<?php if ($result) : ?>
		<div>
			<div class="alert alert-<?php echo $result['status']; ?>"><?php echo $result['message']; ?></div>
		</div>
	<?php endif; ?>
	<form>
		<select name="importer">
			<?php echo getImporterOptions($vendor); ?>
		</select>
		<input class="btn btn-primary" type="submit" value="Выбрать" formmethod="get"
		       formaction="<?php echo $self; ?>"/>
	</form>
	<?php if ($vendor) : ?>
		<form>
			<table class="table table-striped table-hover table-condensed">
				<thead>
				<tr>
					<th>ID</th>
					<th>Title</th>
					<th>New title</th>
					<th>Action</th>
					<th>Value</th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ($cats as $id => $cat) :

					// text-muted
					// text-primary
					// text-success
					// text-info
					// text-warning
					// text-danger

					switch ($cat['action'])
					{
						case 'skip':
							$tr_class    = 'text-danger';
							$label_class = 'del';
							break;
						case 'join':
							$tr_class    = 'text-success';
							$label_class = 'del';
							break;
						case 'move':
							$tr_class    = 'text-info';
							$label_class = '';
							break;
						case 'is_extrafield':
							$tr_class    = 'text-muted';
							$label_class = 'del';
							break;
						default:
							$tr_class    = '';
							$label_class = '';
							break;
					}

					$tr_class .= $cat['is_new'] ? ' success' : '';

					$title = $cat['new_title'] ? $cat['new_title'] : $cat['title'];
					?>
					<tr class="<?php echo $tr_class; ?>">
						<td>
							<span><?php echo $id; ?></span>
							<input type="hidden" name="categories[<?php echo $id; ?>][id]"
							       value="<?php echo $cat['id']; ?>">
						</td>
						<td>
							<?php echo str_repeat('&mdash;&nbsp;&nbsp;', $cat['level']); ?>
							<label class="<?php echo $label_class; ?>"><?php echo $title; ?></label>
							<?php if ($cat['new_title']) : ?>
								(<span class="text-muted"><?php echo $cat['title']; ?></span>)
							<?php endif; ?>
						</td>
						<td>
							<input type="text" name="categories[<?php echo $id; ?>][new_title]"
							       value="<?php echo $cat['new_title']; ?>">
						</td>
						<td>
							<select name="categories[<?php echo $id; ?>][action]">
								<?php echo getActionOptions($cat); ?>
							</select>
						</td>
						<td>
							<input type="text" name="categories[<?php echo $id; ?>][value]"
							       value="<?php echo $cat['value']; ?>">
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<input class="btn btn-primary" type="submit" value="Сохранить" formmethod="post"
				       formaction="<?php echo $page; ?>&action=save"/>
			</p>
		</form>
	<?php endif; ?>
</center>
<!-- <script type="text/javascript">$('tbody').sortable();</script> -->
</body>
</html>
