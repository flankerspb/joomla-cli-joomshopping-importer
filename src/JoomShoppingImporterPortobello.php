<?php

defined('FL_JSHOP_IMPORTER') or die();

class JoomShoppingImporterPortobello extends JoomShoppingImporter
{
	const NAME= 'Portobello';
	
	static $params;
	static $src_path;
	
	const DEFAULTS = [
		'vendor_id' => null, // ID поставщика в базе
	];
	
	
	public static function getCategories($parent_id = 0)
	{
		static $xml;
		static $json = [];
		
		static $level = 0;
		static $result = [];
		
		if(!$xml)
		{
			$xml = self::loadXML('products');
			
			
			
			if(!$xml)
				return null;
			
			if(file_exists(self::CFG_CATEGORIES))
			{
				$json = json_decode(file_get_contents(self::CFG_CATEGORIES), true)[self::$params['vendor_id']];
			}
		}
		
		foreach($xml->xpath("//category[@parent_id='{$parent_id}']") as $item)
		{
			$id = (string)$item->attributes()['id'];
			$title = (string)$item->attributes()['title'];
			
			$result[$id] = [
				'id' => $id,
				'parent_id' => $parent_id,
				'title' => $title,
				'level' => $level,
				'is_new' => '1',
				'new_title' => '',
				'action' => '',
				'value' => '',
			];
			
			if(is_array($json) && array_key_exists($id, $json))
			{
				$result[$id]['is_new'] = '0';
				$result[$id]['new_title'] = $json[$id]['new_title'];
				$result[$id]['action'] = $json[$id]['action'];
				$result[$id]['value'] = $json[$id]['value'];
			}
			
			$children = $xml->xpath("//category[@parent_id='{$id}']");
			
			if(count($children))
			{
				$level++;
				self::getCategories($id);
				$level--;
			}
		}
		
		return $result;
	}
	
	
	function updateProducts()
	{
		$products = $this->loadXML('products');
		
		if($products)
		{
			$this->updateCategories();
			$this->importManufacturers($products->xpath('products/product/brand'));
			$this->importProducts($products->xpath('products/product'));
			
			$this->clearProducts();
		}
	}
	
	
	function updateStock()
	{
		$stock = $this->loadXML('products-quantity');
		
		if($stock)
		{
			$this->importStock($stock);
			
			return true;
		}
		
		return false;
	}
	
	
	protected function importManufacturers($xml)
	{
		self::logMethodStart(__FUNCTION__);
		
		static $model;
		
		if(!$model)
		{
			$model = $this->getModel('manufacturers');
		}
		
		$brands = [];
		
		foreach($xml as $item)
		{
			$brand = trim((string)$item);
			
			if($brand)
			{
				$brands[$brand] += 1;
			}
		}
		
		foreach($brands as $brand => $value)
		{
			$alias = JFilterOutput::stringURLSafe($brand, 'ru-RU');
			
			$code = self::$params['vendor_id'] . '_' . $alias;
			
			$item =
			[
				'fl_code' => self::$params['vendor_id'] . '_' . $alias,
				'fl_source' => self::$params['vendor_id'],
				'manufacturer_publish' => 1,
				'name_ru-RU' => $brand,
				'alias_ru-RU' => $alias,
			];
			
			$tmp = $this->getItem($code, 'manufacturers');
			
			if($tmp)
			{
				$item['manufacturer_id'] = $tmp['manufacturer_id'];
				$item['ordering'] = $tmp['ordering'];
				
				$this->report[5]['manufacturers updated'] += 1;
			}
			else
			{
				$item['ordering'] = ++self::$counter['manufacturers'];
				
				$this->report[5]['manufacturers imported'] += 1;
			}
			
			$model->save($item);
		}
		
		$this->report();
		
		self::logMethodComplete(__FUNCTION__);
	}
	
	
	protected function importProducts($xml)
	{
		self::logMethodStart(__FUNCTION__);
		
		$this->setState(0, 'products', 'vendor_id=' . self::$params['vendor_id']);
		
		$i = 0;
		
		foreach($xml as $item)
		{
			$i++;
			
			if(!self::$params['full_import'] && ($i % 100) != 0)
				continue;
			
			$this->importProduct($item);
		}
		
		$this->report();
		
		self::logMethodComplete(__FUNCTION__);
	}
	
	
	protected function importProduct($xml)
	{
		$label = 0;
		
		static $init = false;
		static $model;
		static $products;
		static $categories;
		static $manufacturers;
		
		if(!$init)
		{
			$model = $this->getModel('products');
			
			$products = $this->getList('products', 'product_ean', 'product_id');
			$categories = $this->getList('categories', 'fl_code', 'category_id');
			$manufacturers = $this->getList('manufacturers', 'name_ru-RU', 'manufacturer_id');
			
			$init = true;
		}
		
		$category = $this->getProductCategory((string)$xml->category_id, self::$params['vendor_id']);
		
		if(!$category)
		{
			$this->report[3]['Products category not found'] += 1;
			$this->report[5]['Category not found for product ' . trim((string)$xml->title)] = (string)$xml->articul;
			
			return;
		}
		
		$name = trim((string)$xml->title);
		$brand = $manufacturers[trim((string)$xml->brand)];
		
		$code = self::$params['vendor_id'] . '_' . (string)$xml->articul;
		
		$item =
		[
			'parent_id' => 0,
			
			'product_ean' => $code,
			'manufacturer_code' => (string)$xml->articul,
			
			'product_quantity' => 0,
			'product_availability' => 0,
			
			'date_modify' => JFactory::getDate('now', self::$timeZone)->toSql(true),
			
			'product_publish' => 1,
			'product_tax_id' => self::$params['tax_id'],
			'currency_id' => self::$params['currency_id'],
			
			// 'product_weight' => (string)$xml->weight,
			
			'product_manufacturer_id' => $brand ? $brand : '0',
			
			'label_id' => $label,
			
			'vendor_id' => self::$params['vendor_id'],
			
			'name_ru-RU' => $name,
			'alias_ru-RU' => JFilterOutput::stringURLSafe($name, 'ru-RU'),
			'description_ru-RU' => self::prepareContent((string)$xml->description),
		];
		
		$item['category_id'] = [$category['category_id']];
		
		
		if($products[$code])
		{
			$item['product_id'] = $products[$code];
			
			$this->report[5]['products updated'] += 1;
		}
		else
		{
			$item['product_old_price'] = '0';
			$item['product_buy_price'] = '0';
			
			$this->report[5]['products imported'] += 1;
		}
		
		if($xml->collection)
		{
			$item['extra_field_' . self::$params['fields']['group']] = (string)$xml->category_id .'-'. (string)$xml->brand .'-'. (string)$xml->collection;
		}
		
		if($xml->size)
		{
			$size = explode('/', (string)$xml->size);
			
			if($size[0])
				$item['extra_field_' . self::$params['fields']['sizex']] = $size[0];
			
			if($size[1])
				$item['extra_field_' . self::$params['fields']['sizey']] = $size[1];
			
			if($size[2])
				$item['extra_field_' . self::$params['fields']['sizez']] = $size[2];
		}
		
		$price = $xml->xpath("price/name[.='End-User']/parent::*/value");
		
		if($xml->price)
		{
			$item['product_price'] = (string)$xml->price;
		}
		
		$fields = [];
		
		if(count($fields))
		{
			$item['productfields'] = $fields;
		}
		
		$images = [];
		
		if($xml->big_image)
		{
			$images[] = (string)$xml->big_image;
		}
		
		if($xml->add_images)
		{
			foreach($xml->add_images->image as $image)
			{
				$images[] = (string)$image;
			}
		}
		
		$item = array_merge($item, self::setImages($images, $item['product_id'], self::$params['vendor_id'], ''));
		
		$model->save($item);
	}
	
	
	protected function importStock($xml)
	{
		self::logMethodStart(__FUNCTION__);
		
		$products = $this->getList('products', 'product_ean', 'product_id');
		
		foreach($xml->children() as $name => $item)
		{
			if($name == 'product')
			{
				$code = self::$params['vendor_id'] . '_' . (string)$item->articul;
				
				if(array_key_exists($code, $products))
				{
					$this->setValues('products', 'product_id=' . $products[$code], 'product_quantity=' . (float)$item->quantity_free);
					
					$this->report[5]['Products quantity updated'] += 1;
				}
				else
				{
					$this->report[5]['Products quantity skipped'] += 1;
				}
			}
		}
		
		$this->report();
		
		self::logMethodComplete(__FUNCTION__);
	}
	
	
	static function getSrcPath($debug)
	{
		if($debug)
		{
			return parent::getSrcPath($debug).'portobello/';
		}
		
		return 'http://ebazaar.ru/export/';
	}
}
