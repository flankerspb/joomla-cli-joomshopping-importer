<?php
define('_JEXEC', 1);
define('FL_JSHOP_IMPORTER', 1);

require_once(__DIR__ . '/src/JoomShoppingImporter.php');

$options = [
		// 'debug' => 0,
		// 'log_type' => '',
	];

JoomShoppingImporter::init($options);

$params = JoomShoppingImporter::$params;
$cfg_categories = JoomShoppingImporter::CFG_CATEGORIES;

session_start();

$result = [];

if(isset($_SESSION['importer.config.categories.result']))
{
	$result = $_SESSION['importer.config.categories.result'];
	
	unset($_SESSION['importer.config.categories.result']);
}

$importer = null;
$cats = [];

$self = basename(__FILE__);

if(isset($_GET['importer']) && array_key_exists($_GET['importer'], JoomShoppingImporter::$children))
{
	$importer = $_GET['importer'];
	
	$cats = JoomShoppingImporter::getCategories($importer);
	
	$page = $self . '?importer=' . $importer;
}


if(isset($_GET['action']) && isset($_POST['categories']))
{
	switch($_GET['action'])
	{
		case 'save':
			
			// foreach($_POST['categories'] as $key => $value)
			// {
				// if(!$value['action'] && !$value['new_title'])
				// {
					// unset($_POST['categories'][$key]);
				// }
			// }
			
			if(file_exists($cfg_categories))
			{
				$categories = json_decode(file_get_contents($cfg_categories), true);
			}
			else
			{
				$categories = [];
			}
			
			$categories[$params[$importer]['vendor_id']] = $_POST['categories'];
			
			file_put_contents($cfg_categories, json_encode($categories, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			
			$_SESSION['importer.config.categories.result'] =
			[
				'status' => 'success',
				'message' => 'categories was saved!',
			];
			
			break;
			
		case 'reset':
			unlink($cfg_categories);
			
			$_SESSION['importer.config.categories.result'] =
			[
				'status' => 'warning',
				'message' => 'categories was reset!',
			];
			
			break;
	}
	
	header('Location: ' . $page);
}

function getImporterOptions($importer)
{
	$_result = [];
	
	foreach(JoomShoppingImporter::$children as $key => $value)
	{
		$selected = ($importer == $key) ? ' selected' : '';
		
		$_result[] = '<option value="'.$key.'"'.$selected.'>'.$key.'</option>';
	}
	
	return implode('', $_result);
}

function getActionOptions($item)
{
	$values = [
		'' => '-No action-',
		'skip' => 'Skip',
		'join' => 'Join to Category',
		'move' => 'Move to Category',
		'is_extrafield' => 'Is Extra Field'
	];
	
	$_result = [];
	
	foreach($values as $key => $value)
	{
		$selected = ($key == $item['action']) ? ' selected' : '';
		
		$_result[] = '<option value="'.$key.'"'.$selected.'>'.$value.'</option>';
	}
	
	return implode('', $_result);
}

?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
<?php if($importer) : ?>
<title>Categories - <?php echo $importer; ?></title>
<?php else : ?>
<title>Categories - Select importer</title>
<?php endif; ?>
<meta name="description" content="">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.11.4/jquery-ui.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.1.1/jquery.js"></script> 
<script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.11.4/jquery-ui.js"></script> 
<style>.table{width: auto;}.del{text-decoration-line: line-through;}</style>
</head>
<body>
	<center>
		<?php if($result) : ?>
		<div>
			<div class="alert alert-<?php echo $result['status']; ?>"><?php echo $result['message']; ?></div>
		</div>
		<?php endif; ?>
		<form>
			<select name="importer">
				<?php echo getImporterOptions($importer); ?>
			</select>
			<input class="btn btn-primary" type="submit" value="Выбрать" formmethod="get" formaction="<?php echo $self; ?>"/>
		</form>
		<?php if($importer) : ?>
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
					<?php foreach($cats as $id => $cat) : 
						
						// text-muted
						// text-primary
						// text-success
						// text-info
						// text-warning
						// text-danger
						
						switch ($cat['action'])
						{
							case 'skip':
								$tr_class = 'text-danger';
								$label_class = 'del';
								break;
							case 'join':
								$tr_class = 'text-success';
								$label_class = 'del';
								break;
							case 'move':
								$tr_class = 'text-info';
								$label_class = '';
								break;
							case 'is_extrafield':
								$tr_class = 'text-muted';
								$label_class = 'del';
								break;
							default:
								$tr_class = '';
								$label_class = '';
								break;
						}
						
						$tr_class .= $cat['is_new'] ? ' success' : '';
						
						$title = $cat['new_title'] ? $cat['new_title']: $cat['title'];
					?>
						<tr class="<?php echo $tr_class; ?>">
							<td>
								<span><?php echo $id; ?></span>
								<input type="hidden" name="categories[<?php echo $id; ?>][id]" value="<?php echo $cat['id']; ?>">
							</td>
							<td>
								<?php echo str_repeat('&mdash;&nbsp;&nbsp;', $cat['level']); ?>
								<label class="<?php echo $label_class; ?>"><?php echo $title; ?></label>
								<?php if($cat['new_title']) : ?>
								(<span class="text-muted"><?php echo $cat['title']; ?></span>)
								<?php endif; ?>
							</td>
							<td>
								<input type="text" name="categories[<?php echo $id; ?>][new_title]" value="<?php echo $cat['new_title']; ?>">
							</td>
							<td>
								<select name="categories[<?php echo $id; ?>][action]">
									<?php echo getActionOptions($cat); ?>
								</select>
							</td>
							<td>
								<input type="text" name="categories[<?php echo $id; ?>][value]" value="<?php echo $cat['value']; ?>">
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<input class="btn btn-primary" type="submit" value="Сохранить" formmethod="post" formaction="<?php echo $page; ?>&action=save"/>
			</p>
		</form>
		<?php endif; ?>
	</center>
	<!-- <script type="text/javascript">$('tbody').sortable();</script> -->
</body>
</html>