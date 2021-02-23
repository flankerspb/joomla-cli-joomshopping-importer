<?php

// TODO: Refactor

defined('FL_JSHOP_IMPORTER') or die();

abstract class JoomShoppingImporter
{
	const NAME = 'Importer';

	const CFG_PARAMS = __DIR__ . '/../config/params.json';
	const CFG_CATEGORIES = __DIR__ . '/../config/categories.json';

	const IMPORT_TIMEOUT = 0;

	const LOG_LEVEL = [
		0 => false,           // Not log
		1 => 'Error',         // Errors only
		//1 => 'Important',   // + Important
		2 => 'Warning',       // + Warnings
		3 => 'Attention',     // + Attentions
		4 => 'Done',          // + Done
		5 => 'Info',          // + Info
	];

	const DEFAULTS = [
		'debug'             => 0,
		'debug_path'        => 'http://localhost/test_files/',
		'full_import'       => 0,
		'log_level'         => 5,
		'log_type'          => 'print',  // [NULL|print|file]
		'log_file'          => '',       // in sec
		'lang_code'         => 'ru-RU',
		'user_id'           => 617,      // ID joomla пользователя импортера
		'primary_vendor_id' => 2,        // ID основного поставщика
		'currency_id'       => 1,        // ID валюты joomshopping
		'tax_id'            => 1,        // ID налога joomshopping
		'product_label_new' => 1,        // ID метки "НОВИКА" joomshopping
		'filters_group'     => 2,        // ID группы фильтров joomshopping
		'fields'            => [         // joomshopping extra fields ID
			'matherial' => 1,
			'size'      => 2,
			'group'     => 3,
			'amount'    => 4,
			'weight'    => 5,
			'volume'    => 6,
			'sizex'     => 7,
			'sizey'     => 8,
			'sizez'     => 9,
			'print'     => 10,
		],
		'attribs'           => [         // ID joomshopping attributes
			'size'  => 1,
			'print' => 2,
		],
		'attribs_defaults'  => [         // ID joomshopping attribut values
			'size'  => '',
			'print' => '70,71',
		]
	];

	const TABLES = [
		'categories'         => '#__jshopping_categories',
		'products'           => '#__jshopping_products',
		'productFields'      => '#__jshopping_products_extra_fields',
		'productFieldValues' => '#__jshopping_products_extra_field_values',
		'manufacturers'      => '#__jshopping_manufacturers',
		'attrValues'         => '#__jshopping_attr_values',
		'productsAttr'       => '#__jshopping_products_attr',
		'productImages'      => '#__jshopping_products_images',
	];

	protected static $params;
	protected static $children = [];

	protected static $timeZone;

	protected static $jshopConfig;

	protected static $counter = [
		'categories'         => 0,
		'manufacturers'      => 0,
		'productFields'      => 0,
		'productFieldValues' => 0,
		'attrValues'         => 0,
	];

	protected $report = [];


	final protected function __construct()
	{
		//Get Site root dir
		$root_dir = self::getSiteRoot();

		// Load system defines
		if (file_exists($root_dir . '/administrator/defines.php'))
		{
			$root_dir . '/administrator/defines.php';
		}

		if (!defined('_JDEFINES'))
		{
			define('JPATH_BASE', $root_dir . DIRECTORY_SEPARATOR . 'administrator');
			require_once JPATH_BASE . '/includes/defines.php';
		}

		require_once JPATH_BASE . '/includes/framework.php';
		require_once JPATH_BASE . '/includes/helper.php';

		// Instantiate the application.
		JFactory::getApplication('administrator');
		$lang = JFactory::getLanguage();
		$lang->setDefault(self::$params['global']['lang_code']);
		$lang->setLanguage(self::$params['global']['lang_code']);

		//Set timeZone
		self::$timeZone = JFactory::getConfig()->get('offset', null);

		// Instantiate jshopping.
		define('JPATH_COMPONENT', JPATH_ADMINISTRATOR . '/components/com_jshopping');
		define('JPATH_COMPONENT_SITE', JPATH_SITE . '/components/com_jshopping');
		define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_COMPONENT);

		JTable::addIncludePath(JPATH_COMPONENT_SITE . '/tables');
		require_once(JPATH_COMPONENT_SITE . '/lib/factory.php');
		require_once(JPATH_COMPONENT_ADMINISTRATOR . '/functions.php');
		require_once(JPATH_COMPONENT_ADMINISTRATOR . '/controllers/baseadmin.php');
		require_once(JPATH_COMPONENT_SITE . '/lib/image.lib.php');

		JModelLegacy::addIncludePath(JPATH_COMPONENT . '/models');
		JModelLegacy::addIncludePath(JPATH_COMPONENT_SITE . '/models');

//		$adminlang = $lang;
		JSFactory::setLoadUserId(self::$params['global']['user_id']);
		self::$jshopConfig = JSFactory::getConfig();

		//fix redirect then product save (non autorized user)
		self::$jshopConfig->admin_show_vendors = 0;

		foreach (self::$counter as $key => $value)
		{
			self::$counter[$key] = self::countItems($key);
		}
	}


	final public static function getInstance($instance)
	{
		self::init();

		$class = __CLASS__ . ucfirst($instance);

		return new $class();
	}


	final public static function init($options = [])
	{
		static $init = false;

		if ($init)
		{
			return;
		}

		$init = true;

		$defaults           = [];
		$defaults['global'] = self::DEFAULTS;

		$files = array_map('basename', glob(__DIR__ . '/' . __CLASS__ . '?*.php', GLOB_BRACE));

		foreach ($files as $file)
		{
			include($file);

			$class = explode('.', $file)[0];

			self::$children[$class::NAME] = $class;

			$defaults[$class::NAME] = $class::DEFAULTS;
		}

		$params = [];

		if (file_exists(self::CFG_PARAMS))
		{
			$params = json_decode(file_get_contents(self::CFG_PARAMS), true);
		}

		if ($params)
		{
			$params = self::mergeParams($defaults, $params);
		}
		else
		{
			$params = $defaults;
		}

		if ($options)
		{
			$params = self::mergeParams($params, ['global' => $options]);
		}

		self::$params = $params;

		foreach (self::$children as $child => $class)
		{
			$class::$params = self::mergeParams($params['global'], $params[$child]);

			$class::$src_path = $class::getSrcPath($params['global']['debug']);
		}
	}


	protected static function getSiteRoot($dir = __DIR__, $prev_dir = '')
	{
		static $root_dir = null;

		if ($root_dir)
		{
			return $root_dir;
		}

		$needle = ['includes', 'layouts', 'libraries', 'media', 'plugins'];

		if ($prev_dir == $dir)
		{
			return null;
		}

		$curr_dirs = scandir($dir);

		if (array_intersect($needle, $curr_dirs) == $needle)
		{
			$root_dir = $dir;

			return $root_dir;
		}
		else
		{
			$prev_dir = $dir;

			return self::getSiteRoot(dirname($dir), $prev_dir);
		}
	}


	//refactor
	protected static function getSrcPath($debug)
	{
		return static::$params['debug_path'];
	}


	static function mergeParams(array $array1, array $array2)
	{
		$merged = $array1;

		foreach ($array2 as $key => & $value)
		{
			if (is_array($value) && isset($merged[$key]) && is_array($merged[$key]))
			{
				$merged[$key] = self::mergeParams($merged[$key], $value);
			}
			elseif (is_numeric($key))
			{
				if (!in_array($value, $merged))
				{
					$merged[] = $value;
				}
			}
			else
			{
				$merged[$key] = $value;
			}
		}

		return $merged;
	}


	//refactor
	public static function getCategories($child)
	{
		$class = __CLASS__ . $child;

		return $class::getCategories();
	}


	//refactor
	public function updateCategories()
	{
		self::logMethodStart(__FUNCTION__);

		$categories = static::getCategories();

		if (count($categories))
		{
			foreach ($categories as $item)
			{
				switch ($item['action'])
				{
					case '' :
					case 'move' :
						$this->importCategory($item);
						break;
					case 'skip' :
					case 'join' :
					case 'is_extrafield' :
						$this->report[5]['categories ' . $item['action']] += 1;
						break;
				}
			}

			$this->report();

			self::logMethodComplete(__FUNCTION__);

			return true;
		}

		self::logMethodComplete(__FUNCTION__);

		return false;
	}


	protected function importCategory($cat)
	{
		static $model;

		if (!$model)
		{
			$model = $this->getModel('categories');
			// $this->setState(0, 'categories', 'fl_source=' . static::$params['vendor_id']);
		}

		$code = static::$params['vendor_id'] . '_' . $cat['id'];
		$name = $cat['new_title'] ? $cat['new_title'] : $cat['title'];

		if ($cat['action'] == 'move')
		{
			$val = explode(',', $cat['value']);

			$pid = $val[0];
			$vid = $val[1] ? $val[1] : static::$params['vendor_id'];
		}
		else
		{
			$pid = $cat['parent_id'];
			$vid = static::$params['vendor_id'];
		}

		$parent    = $this->getCategory($pid, $vid);
		$parent_id = $parent['category_id'];

		$item = array(
			'fl_code'            => $code,
			'fl_source'          => static::$params['vendor_id'],
			'fl_update_date'     => JFactory::getDate('now', self::$timeZone)->toSql(true),
			'category_parent_id' => ($parent_id == null ? 0 : $parent_id),
			'category_publish'   => 1,
			'name_ru-RU'         => $name,
			'alias_ru-RU'        => JFilterOutput::stringURLSafe($name, 'ru-RU'),
		);

		$tmp = $this->getItem($item['fl_code'], 'categories');

		if ($tmp)
		{
			$item['category_id'] = $tmp['category_id'];
			$item['ordering']    = $tmp['ordering'];

			$this->report[5]['categories updated'] += 1;
		}
		else
		{
			$item['ordering'] = ++self::$counter['categories'];

			$this->report[3]['Categories imported']         += 1;
			$this->report[5]['Imported category: ' . $name] = 0;
		}

		$model->save($item);
	}


	protected static function prepareContent($string)
	{
		return preg_replace('~<a\b[^>]*+>|</a\b[^>]*+>~', '', $string);
	}

	protected static function getTable($type, $prefix = 'jshop', $config = [])
	{
		return JTable::getInstance($type, $prefix, $config);
	}


	protected static function getModel($type, $prefix = 'JshoppingModel', $config = [])
	{
		return JModelLegacy::getInstance($type, $prefix, $config);
	}


	protected function countItems($type, $where = [])
	{
		$db = JFactory::getDbo();

		$query = $db->getQuery(true);
		$query->select('COUNT(*)');
		$query->from(self::TABLES[$type]);

		if ($where)
		{
			$query->where($where);
		}

		return $db->setQuery($query)->loadResult();
	}


	protected function getList($type, $key, $value, $where = array())
	{
		$db = JFactory::getDbo();

		$query = $db->getQuery(true);
		$query->select([$db->quoteName($key), $db->quoteName($value)]);
		$query->from(self::TABLES[$type]);

		if ($where)
		{
			$query->where($where);
		}

		return $db->setQuery($query)->loadAssocList($key, $value);
	}


	protected function getItem($code, $type, $column = 'fl_code')
	{
		static $result = array();

		if (!array_key_exists($type, $result))
		{
			$result[$type] = array();
		}

		if (array_key_exists($code, $result[$type]))
		{
			return $result[$type][$code];
		}

		$result[$type] = $this->getItems($type, $column);

		return $result[$type][$code];
	}


	protected function getItems($type, $column = 'fl_code', $where = '')
	{
		$db = JFactory::getDbo();

		$query = $db->getQuery(true);
		$query->select('*');
		$query->from(self::TABLES[$type]);

		if ($where)
		{
			$query->where($where);
		}

		$result = $db->setQuery($query)->loadAssocList($column);

		$query->clear();

		return $result;
	}


	protected function getCategory($cat_id, $vendor_id)
	{
		$categories = json_decode(file_get_contents(self::CFG_CATEGORIES), true);

		if (array_key_exists($cat_id, $categories[$vendor_id]) && $categories[$vendor_id][$cat_id]['action'] == 'join')
		{
			$value = explode(',', $categories[$vendor_id][$cat_id]['value']);

			$cat_id    = $value[0];
			$vendor_id = $value[1] ? $value[1] : static::$params['vendor_id'];

			return $this->getCategory($cat_id, $vendor_id);
		}

		return $this->getItem($vendor_id . '_' . $cat_id, 'categories');
	}

	protected function getProductCategory($cat_id, $vendor_id)
	{
		$categories = json_decode(file_get_contents(self::CFG_CATEGORIES), true);

		if (array_key_exists($cat_id, $categories[$vendor_id]))
		{
			switch ($categories[$vendor_id][$cat_id]['action'])
			{
				case 'join' :
					$value = explode(',', $categories[$vendor_id][$cat_id]['value']);

					$cat_id    = $value[0];
					$vendor_id = $value[1] ? $value[1] : static::$params['vendor_id'];

					return $this->getProductCategory($cat_id, $vendor_id);

					break;
				case 'is_extrafield' :
					$category = static::getCategories()[$cat_id];

					$cat_id    = $category['parent_id'];
					$vendor_id = static::$params['vendor_id'];

					return $this->getProductCategory($cat_id, $vendor_id);

					break;
				case 'skip' :
					return null;

					break;
			}
		}

		return $this->getItem($vendor_id . '_' . $cat_id, 'categories');
	}


	protected function setState($flag, $type, $conditions)
	{
		switch ($type)
		{
			case 'products':
				$state = '`product_publish` = ' . $flag;
				break;
			case 'categories':
				$state = 'category_publish = ' . $flag;
				break;
			case 'manufacturers':
				$state = '`manufacturer_publish` = ' . $flag;
				break;
			case 'productFields':
			case 'productFieldValues':
				$state = 'fl_state = ' . $flag;
				break;
			default:
				$state = 'publish = ' . $flag;
				break;
		}

		$db = JFactory::getDbo();

		$query = $db->getQuery(true);

		$query->update(self::TABLES[$type]);
		$query->where($conditions);
		$query->set($state);

		$db->setQuery($query)->execute();
	}


	protected function setValues($type, $conditions, $fields)
	{
		$db = JFactory::getDbo();

		$query = $db->getQuery(true);

		$query->update(self::TABLES[$type]);
		$query->where($conditions);
		$query->set($fields);

		$db->setQuery($query)->execute();
	}


	protected function deleteItems($type, $key, $where = [])
	{
		$to_delete = $this->getList($type, $key, $key, $where);

		$model = $this->getModel($type);

		switch ($type)
		{
			case 'categories':
				$method = 'deleteCategory';
				break;
			default:
				$method = 'delete';
				break;
		}

		foreach ($to_delete as $id)
		{
			$model->$method($id);
		}

		$this->report[5][$type . ' deleted'] = count($to_delete);

		$this->report();
	}


	protected function setImages($images, $id, $prefix, $src)
	{
		static $lastTime = 0;

		if (!count($images))
		{
			$this->report[5]['Products without_images'] += 1;

			return [];
		}

		$model = self::getModel('products');

		$path = self::$jshopConfig->image_product_path . '/';

		$images   = array_values(array_unique($images));
		$result   = [];
		$source   = [];
		$ordering = [];
		$main_image;

		$remove = $id ? self::getList('productImages', 'image_id', 'image_name', ['product_id=' . $id]) : [];

		foreach ($remove as $key => $file)
		{
			if (!file_exists($path . $file))
			{
				$model->deleteImage($key);
				unset($remove[$key]);

				$this->report[3]['Lost images']           += 1;
				$this->report[3]['Lost image ID ' . $key] = $file;
			}
		}

		foreach ($images as $key => $image)
		{
			$name = $prefix . '_' . basename($image);

			$image_id = array_search($name, $remove);

			if ($image_id)
			{
				$ordering[$image_id] = ($key + 1);

				unset($remove[$image_id]);

				if ($key == 0)
				{
					$main_image = $image_id;
				}
			}
			else
			{
				$result['product_folder_image_' . $key] = $name;
				$source['product_folder_image_' . $key] = $image;
			}
		}

		foreach ($remove as $key => $value)
		{
			$model->deleteImage($key);

			$this->report[4]['Images removed']         += 1;
			$this->report[5]['Image removed: ' . $key] = $file;
		}

		$orig_w  = self::$jshopConfig->image_product_original_width;
		$orig_h  = self::$jshopConfig->image_product_original_height;
		$full_w  = self::$jshopConfig->image_product_full_width;
		$full_h  = self::$jshopConfig->image_product_full_height;
		$thumb_w = self::$jshopConfig->image_product_width;
		$thumb_h = self::$jshopConfig->image_product_height;

		foreach ($result as $key => $file)
		{
			if (file_exists($path . $file))
			{
				unset($result[$key]);

				$this->report[3]['Images exists']          += 1;
				$this->report[3]['Image exists: ' . $file] = $source[$key];
			}
			else
			{
				$tmp = JPATH_ROOT . '/tmp/' . $file;

				if (static::IMPORT_TIMEOUT)
				{
					$wait = microtime(true) - $lastTime;

					if ($wait < static::IMPORT_TIMEOUT)
					{
						usleep((static::IMPORT_TIMEOUT - $wait) * 1000000);
					}
				}

				if (@copy($src . $source[$key], $tmp))
				{
					$lastTime = microtime(true);

					$orig  = $path . $file;
					$full  = $path . 'full_' . $file;
					$thumb = $path . 'thumb_' . $file;

					//Orig
					if ($orig_w == 0 && $orig_h == 0)
					{
						@copy($tmp, $orig);
					}
					else
					{
						self::resizeImage($tmp, $orig_w, $orig_h, $orig);
					}
					//full
					if ($full_w == 0 && $full_h == 0)
					{
						@copy($tmp, $full);
					}
					else
					{
						self::resizeImage($tmp, $full_w, $full_h, $full);
					}
					//thumb
					if ($thumb_w == 0 && $thumb_h == 0)
					{
						@copy($tmp, $thumb);
					}
					else
					{
						self::resizeImage($tmp, $thumb_w, $thumb_h, $thumb);
					}

					unlink($tmp);

					$this->report[4]['Images uploaded']                  += 1;
					$this->report[5]['Image uploaded: ' . $source[$key]] = $file;
				}
				else
				{
					$lastTime = microtime(true);

					unset($result[$key]);

					$this->report[1]['Images failed']                  += 1;
					$this->report[1]['Image failed: ' . $source[$key]] = $file;
				}
			}
		}

		if ($main_image)
		{
			$result['set_main_image'] = $main_image;
		}

		if (count($ordering))
		{
			$result['old_image_ordering'] = $ordering;
			$result['old_image_descr']    = array_fill_keys(array_keys($ordering), '');
		}

		return $result;
	}


	protected function resizeImage($src, $width, $height, $dest)
	{
		require_once(JPATH_ROOT . '/libraries/vendor/phpthumb/phpthumb.class.php');

		$phpThumb = new phpThumb();

		$phpThumb->setSourceFilename($src);
		$phpThumb->setParameter('w', $width);
		$phpThumb->setParameter('h', $height);
		$phpThumb->setParameter('f', substr($src, -3, 3));
		//$phpThumb->setParameter('zc','C');
		$phpThumb->setParameter('far', 'C');
		$phpThumb->setParameter('bg', 'FFFFFF');
		// $phpThumb->setParameter('q',70);

		$phpThumb->GenerateThumbnail();
		$phpThumb->RenderToFile($dest);
	}


	public function clearProducts()
	{
		self::logMethodStart(__FUNCTION__);

		$this->deleteItems('products', 'product_id', ['product_publish=0', 'vendor_id=' . static::$params['vendor_id']]);

		self::logMethodComplete(__FUNCTION__);
	}


	public function fixCategories()
	{
		$model = $this->getModel('categories');

		$allCatCountProducts = $model->getAllCatCountProducts();

		$categories = $this->getList('categories', 'fl_code', 'category_id', 'category_publish=1');

		$i = 0;

		if ($categories)
		{
			$category = JSFactory::getTable('category', 'jshop');

			foreach ($categories as $id)
			{
				$category->load($id);
				$childs = $category->getChildCategories();

				if ($allCatCountProducts[$id] || count($childs))
				{
					continue;
				}

				$i++;

				$this->setState(0, 'categories', 'category_id=' . $id);
			}

			$this->report[5]['Categories unpublished'] += $i;
		}

		if ($i)
		{
			$this->fixCategories();
		}
		else
		{
			$this->report();

			return;
		}
	}


	protected static function loadXML($name)
	{
		$file = static::$src_path . $name . '.xml';

		$headers = get_headers($file);

		if (in_array('Content-Type: application/xml', $headers))
		{
			self::log('Info: File loaded: ' . $name, 5);

			return simplexml_load_file($file);
		}
		else
		{
			self::log('Error: Failed load file: ' . $name, 0);

			return null;
		}
	}


	protected function report()
	{
		foreach ($this->report as $level => $data)
		{
			foreach ($data as $key => $value)
			{
				$string = self::LOG_LEVEL[$level] . ': ' . $key . ': ' . $value;

				$this->log($string, $level);
			}
		}

		$this->report = [];
	}


	protected static function logMethodStart($string)
	{
		self::log($string . ' start', 5);
	}


	protected static function logMethodComplete($string)
	{
		self::log($string . ' complete', 4);
	}


	protected static function log($string, $level = 4)
	{
		if ($level > self::$params['global']['log_level'])
		{
			return;
		}

		$string = static::NAME . ': ' . $string;

		switch (self::$params['global']['log_type'])
		{
			case 'print' :
				if (PHP_SAPI === 'cli')
				{
					echo $string . PHP_EOL;
				}
				else
				{
					var_dump($string);
				}
				break;
			case 'file' :
				$log_file = (self::$params['global']['log_file']) ?: 'log.txt';

				$handle = fopen(dirname(__DIR__) . '/' . $log_file, 'a');

				static $isLogged = false;

				if ($handle)
				{
					fwrite($handle, date('Y.m.d H-i-s') . ': ' . $string . PHP_EOL);
					fclose($handle);
				}
				elseif (!$isLogged)
				{
					$isLogged = true;

					echo 'Can not creat LOG file!' . PHP_EOL;
				}
				break;
		}
	}


	protected static function formatByteSize($size)
	{
		$unit = array('b', 'kb', 'mb', 'gb', 'tb', 'pb');

		return @round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . ' ' . $unit[$i];
	}
}
