<?php

namespace JoomShoppingImporter;

use JFactory;
use JFilterOutput;
use JModelLegacy;
use JSFactory;
use JTable;
use phpthumb;

abstract class AbstractImporter implements ImporterInterface
{
	const IMPORT_TIMEOUT = 0;

	const DEFAULTS = [
		// joomshopping vendor ID for concat values
		'primary_vendor_id' => 0,

		'currency_id'       => 0,        // joomshopping currency ID
		'tax_id'            => 0,        // joomshopping tax ID
		'product_label_new' => 0,        // joomshopping label new ID
		'filters_group'     => 0,        // joomshopping ID filters group

		// joomshopping extra fields IDs
		'fields' => [
			'matherial' => 0,
			'size'      => 0,
			'group'     => 0,
			'amount'    => 0,
			'weight'    => 0,
			'volume'    => 0,
			'sizex'     => 0,
			'sizey'     => 0,
			'sizez'     => 0,
			'print'     => 0,
			'dated'     => 0,
			'color'     => 0,
			'cover'     => 0,
		],

		// joomshopping attributes IDs
		'attribs' => [
			'size'  => 0,
			'print' => 0,
		],

		// joomshopping attribut values default IDs
		'attribs_defaults' => [
			'size'  => '',
			'print' => '',
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

	protected $counter = [
		'categories'         => 0,
		'manufacturers'      => 0,
		'productFields'      => 0,
		'productFieldValues' => 0,
		'attrValues'         => 0,
	];

	protected $id;

	protected $name;

	protected $srcPath;

	protected $timeZone;

	protected $config;

	protected $logger;

	protected $full_import = true;

	protected $report = [];


	abstract protected function getSrcPath() : string;


	final public function __construct(array $config, ?Logger $logger = null)
	{
		$this->config = $config;

		if(!array_key_exists('vendor_id', $config))
		{
			throw new \Exception('ERROR! Importer ' . $this->getName() . ' not configured!');
		}

		$this->id = $config['vendor_id'];

		if($logger)
		{
			$this->logger = $logger;
		}
		else
		{
			$this->logger = new Logger();
			$this->logger->off();
		}

		if(defined('IMPORTER_DEBUG_SRC_PATH'))
		{
			$this->srcPath = IMPORTER_DEBUG_SRC_PATH . '/' . $this->getName() . '/';
		}
		else
		{
			$this->srcPath = $this->getSrcPath();
		}

		if(defined('IMPORTER_FULL_IMPORT'))
		{
			$this->full_import = IMPORTER_FULL_IMPORT;
		}

		$this->timeZone = JFactory::getConfig()->get('offset', null);

		foreach ($this->counter as $key => $value)
		{
			$this->counter[$key] = $this->countItems($key);
		}
	}


	// @TODO refactor
	public function updateCategories() : bool
	{
		$this->logger->methodStart();

		$categories = $this->getCategories();

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

			$this->logger->MethodComplete();

			return true;
		}

		$this->logger->MethodComplete();

		return false;
	}


	protected function importCategory(array $cat) : void
	{
		static $model;

		if (!$model)
		{
			$model = $this->getModel('categories');
			// $this->setState(0, 'categories', 'fl_source=' . $this->id);
		}

		$code = $this->id . '_' . $cat['id'];
		$name = $cat['new_title'] ?: $cat['title'];

		if ($cat['action'] == 'move')
		{
			$val = explode(',', $cat['value']);

			$pid = $val[0];
			$vid = $val[1] ? $val[1] : $this->id;
		}
		else
		{
			$pid = $cat['parent_id'];
			$vid = $this->id;
		}

		$parent    = $this->getCategory($pid, $vid);
		$parent_id = $parent['category_id'];

		$item = array(
			'fl_code'            => $code,
			'fl_source'          => $this->id,
			'fl_update_date'     => JFactory::getDate('now', $this->timeZone)->toSql(true),
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
			$item['ordering'] = ++$this->counter['categories'];

			$this->report[3]['Categories imported']         += 1;
			$this->report[5]['Imported category: ' . $name] = 0;
		}

		$model->save($item);
	}


	protected static function prepareContent(string $string) : string
	{
		return preg_replace('~<a\b[^>]*+>|</a\b[^>]*+>~', '', $string);
	}


	protected static function getTable(string $type, string $prefix = 'jshop', array $config = []) : ?JTable
	{
		return JTable::getInstance($type, $prefix, $config);
	}


	protected static function getModel(string $type, string $prefix = 'JshoppingModel', array $config = []) : ?JModelLegacy
	{
		return JModelLegacy::getInstance($type, $prefix, $config);
	}


	protected static function countItems($type, $where = []) : int
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


	protected function getItems($type, $column = 'fl_code', $where = '') : array
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
			$vendor_id = $value[1] ? $value[1] : $this->id;

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
					$vendor_id = $value[1] ? $value[1] : $this->id;

					return $this->getProductCategory($cat_id, $vendor_id);

					break;
				case 'is_extrafield' :
					$category = static::getCategories()[$cat_id];

					$cat_id    = $category['parent_id'];
					$vendor_id = $this->id;

					return $this->getProductCategory($cat_id, $vendor_id);

					break;
				case 'skip' :
					return null;

					break;
			}
		}

		return $this->getItem($vendor_id . '_' . $cat_id, 'categories');
	}


	protected function setState($flag, $type, $conditions) : void
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


	protected function setValues($type, $conditions, $fields) : void
	{
		$db = JFactory::getDbo();

		$query = $db->getQuery(true);

		$query->update(self::TABLES[$type]);
		$query->where($conditions);
		$query->set($fields);

		$db->setQuery($query)->execute();
	}


	protected function deleteItems($type, $key, $where = []) : void
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


	protected function setImages(array $images, int $id, string $prefix, string $src) :array
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
		$main_image = null;

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


	protected function resizeImage($src, $width, $height, $dest) : void
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


	public function clearProducts() : void
	{
		$this->logger->methodStart();

		$this->deleteItems('products', 'product_id', ['product_publish=0', 'vendor_id=' . $this->id]);

		$this->logger->methodComplete();
	}


	public function fixCategories() : void
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
				$children = $category->getChildCategories();

				if ($allCatCountProducts[$id] || count($children))
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


	protected function loadXML($name) : ?\SimpleXMLElement
	{
		$file = $this->srcPath . $name . '.xml';

		$headers = get_headers($file);

		if (in_array('Content-Type: application/xml', $headers))
		{
			$this->logger->info('File loaded: ' . $file);

			return simplexml_load_file($file);
		}
		else
		{
			$this->logger->error('Failed load file: ' . $file);

			return null;
		}
	}

	public static function getName() : string
	{
		static $name = null;

		if(!$name)
		{
			$parts = explode('\\', static::class);
			$name = strtolower(str_ireplace('Importer', '', end($parts)));
		}

		return $name;
	}


	protected function report() : void
	{
		foreach ($this->report as $level => $data)
		{
			foreach ($data as $key => $value)
			{
				$this->logger->log($key . ': ' . $value, $level);
			}
		}

		$this->report = [];
	}
}
