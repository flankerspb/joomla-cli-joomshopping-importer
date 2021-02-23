<?php

namespace JoomShoppingImporter;

use JFactory;
use JFilterOutput;

class ImporterPortobello extends AbstractImporter
{
	const DEFAULTS = [
		'vendor_id' => null,
	];

	const PRODUCTS_FILE = 'new-products';
	const STOCK_FILE    = 'new-products-quantity';


	protected function getSrcPath() : string
	{
		return 'https://portobello.ru/export/';
	}


	public function getCategories($parent_id = '')
	{
		static $xml;
		static $json = [];

		static $level = 0;
		static $result = [];

		if (!$xml)
		{
			$xml = $this->loadXML(self::PRODUCTS_FILE);

			if (file_exists(IMPORTER_CATEGORIES_FILE))
			{
				$json = json_decode(file_get_contents(IMPORTER_CATEGORIES_FILE), true)[$this->config['vendor_id']];
			}
		}

		foreach ($xml->xpath("//category[@parentId='{$parent_id}']") as $item)
		{
			$id    = (string) $item->attributes()['id'];
			$title = (string) $item->attributes()['title'];

			$result[$id] = [
				'id'        => $id,
				'parent_id' => $parent_id ?: '0',
				'title'     => $title,
				'level'     => $level,
				'is_new'    => '1',
				'new_title' => '',
				'action'    => '',
				'value'     => '',
			];

			if (is_array($json) && array_key_exists($id, $json))
			{
				$result[$id]['is_new']    = '0';
				$result[$id]['new_title'] = $json[$id]['new_title'];
				$result[$id]['action']    = $json[$id]['action'];
				$result[$id]['value']     = $json[$id]['value'];
			}

			$children = $xml->xpath("//category[@parentId='{$id}']");

			if (count($children))
			{
				$level++;
				$this->getCategories($id);
				$level--;
			}
		}

		return $result;
	}


	public function updateProducts()
	{
		$products = $this->loadXML(self::PRODUCTS_FILE);

		if ($products)
		{
			$this->updateCategories();
			$this->importManufacturers($products->xpath('products/product/brand'));
			$this->importPrints($products->xpath('products/product/personalization_list/personalization'));
			$this->importProducts($products->xpath('products/product'));

			$this->clearProducts();

			$this->updateStock();
		}
	}


	public function updateStock()
	{
		$stock = $this->loadXML(self::STOCK_FILE);

		if ($stock)
		{
			$this->importStock($stock);

			return true;
		}

		return false;
	}


	protected function importManufacturers($xml)
	{
		$this->logger->MethodStart();

		static $model;

		if (!$model)
		{
			$model = $this->getModel('manufacturers');
		}

		$brands = [];

		foreach ($xml as $item)
		{
			$brand = trim((string) $item);

			if ($brand)
			{
				$brands[$brand] += 1;
			}
		}

		foreach ($brands as $brand => $value)
		{
			$alias = JFilterOutput::stringURLSafe($brand, 'ru-RU');

			$code = $this->id . '_' . $alias;

			$item =
				[
					'fl_code'              => $this->id . '_' . $alias,
					'fl_source'            => $this->id,
					'manufacturer_publish' => 1,
					'name_ru-RU'           => $brand,
					'alias_ru-RU'          => $alias,
				];

			$tmp = $this->getItem($code, 'manufacturers');

			if ($tmp)
			{
				$item['manufacturer_id'] = $tmp['manufacturer_id'];
				$item['ordering']        = $tmp['ordering'];

				$this->report[5]['manufacturers updated'] += 1;
			}
			else
			{
				$item['ordering'] = ++$this->counter['manufacturers'];

				$this->report[5]['manufacturers imported'] += 1;
			}

			$model->save($item);
		}

		$this->report();

		$this->logger->MethodComplete();
	}


	protected function importProducts($xml)
	{
		$this->logger->MethodStart();

		$this->setState(0, 'products', 'vendor_id=' . $this->id);

		$i = 0;

		foreach ($xml as $item)
		{
			$i++;

			if (!$this->config['full_import'] && ($i % 200) != 0)
				continue;

			$this->importProduct($item);
		}

		$this->report();

		$this->logger->MethodComplete();
	}


	protected function importProduct($xml)
	{
		$label = 0;

		static $init = false;
		static $model;
		static $products;
		static $manufacturers;

		if (!$init)
		{
			$model = $this->getModel('products');

			$products      = $this->getList('products', 'product_ean', 'product_id');
			$prints        = $this->getList('productFieldValues', 'fl_code', 'id', ['field_id=' . $this->config['fields']['print'], 'fl_source=' . $this->id]);
			$manufacturers = $this->getList('manufacturers', 'name_ru-RU', 'manufacturer_id');

			$init = true;
		}

		$category = $this->getProductCategory((string) $xml->categoryId, $this->id);

		if (!$category)
		{
			$this->report[3]['Products category not found']                                  += 1;
			$this->report[5]['Category not found for product ' . trim((string) $xml->title)] = (string) $xml->articul;

			return;
		}

		$name  = trim((string) $xml->title);
		$brand = $manufacturers[trim((string) $xml->brand)];

		$code = $this->id . '_' . (string) $xml->articul;

		$item =
			[
				'parent_id' => 0,

				'fl_products_group' => ((string) $xml->model != (string) $xml->articul) ? (string) $xml->model : '',

				'product_ean'       => $code,
				'manufacturer_code' => (string) $xml->articul,

				'product_quantity'     => 0,
				'product_availability' => 0,

				'date_modify' => JFactory::getDate('now', $this->timeZone)->toSql(true),

				'product_publish' => 1,
				'product_tax_id'  => $this->config['tax_id'],
				'currency_id'     => $this->config['currency_id'],

				// 'product_weight' => (string)$xml->weight,

				'product_manufacturer_id' => $brand ? $brand : '0',

				'label_id' => $label,

				'vendor_id' => $this->id,

				'name_ru-RU'        => $name,
				'alias_ru-RU'       => JFilterOutput::stringURLSafe($name, 'ru-RU'),
				'description_ru-RU' => self::prepareContent((string) $xml->description),
			];

		$item['category_id'] = [$category['category_id']];


		if ($products[$code])
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

		if ($xml->collection)
		{
			$item['extra_field_' . $this->config['fields']['group']] = (string) $xml->category_id . '-' . (string) $xml->brand . '-' . (string) $xml->collection;
		}

		if ($xml->width)
		{
			$item['extra_field_' . $this->config['fields']['sizex']] = (string) $xml->width;
		}

		if ($xml->height)
		{
			$item['extra_field_' . $this->config['fields']['sizey']] = (string) $xml->height;
		}

		if ($xml->length)
		{
			$item['extra_field_' . $this->config['fields']['sizez']] = (string) $xml->length;
		}

		if ($xml->price)
		{
			$item['product_price'] = (string) $xml->price;
		}

		if((float)$xml->price_old && (float)$xml->price_old < (float)$xml->price)
		{
			$item['product_old_price'] = (string) $xml->price_old;
		}

		if ($xml->specials)
		{
			foreach ($xml->specials->value as $value)
			{
				$value = strtolower((string)$value);

				switch ($value) {
					case 'новинка':
						$item['label_id'] = $this->config['product_label_new'];
						break;
					default:
						continue 2;
						break;
				}
			}
		}


		if ($xml->personalization_list && $this->config['fields']['print'])
		{
			foreach ($xml->personalization_list as $print)
			{
				$print_code = $this->id . '_' . $this->config['fields']['print'] . '-' . (string) $print->code;

				if ($prints[$print_code])
				{
					$fields['extra_field_' . $this->config['fields']['print']][] = $prints[$print_code];
				}
			}

			$item['attrib_ind_id']        = [$this->config['attribs']['print'], $this->config['attribs']['print']];
			$item['attrib_ind_value_id']  = explode(',', $this->config['attribs_defaults']['print']);
			$item['attrib_ind_price_mod'] = ['+', '+'];
			$item['attrib_ind_price']     = ['0', '0'];
		}


		$fields = [];

		if (count($fields))
		{
			$item['productfields'] = $fields;
		}

		$images = [];

		if ($xml->big_image)
		{
			$images[] = (string) $xml->big_image;
		}

		if ($xml->add_images)
		{
			foreach ($xml->add_images->image as $image)
			{
				$images[] = (string) $image;
			}
		}

		$item = array_merge($item, self::setImages($images, $item['product_id'], $this->id, ''));

		$model->save($item);
	}


	protected function importStock($xml)
	{
		$this->logger->MethodStart();

		$products = $this->getList('products', 'product_ean', 'product_id');

		foreach ($xml->children() as $name => $item)
		{
			if ($name == 'product')
			{
				$code = $this->id . '_' . (string) $item->articul;

				if (array_key_exists($code, $products))
				{
					$this->setValues('products', 'product_id=' . $products[$code], 'product_quantity=' . (float) $item->quantity_free);

					$this->report[5]['Products quantity updated'] += 1;
				}
				else
				{
					$this->report[5]['Products quantity skipped'] += 1;
				}
			}
		}

		$this->report();

		$this->logger->MethodComplete();
	}


	protected function importPrints($xml)
	{
		$this->logger->MethodStart();

		$tmp = [];

		foreach ($xml as $key => $print)
		{
			$code = trim((string) $print->code);

			if (array_key_exists($code, $tmp))
			{
				unset($xml[$key]);
			}
			else
			{
				$tmp[$code] = '';
			}
		}

		$this->importFieldValues($xml, $this->config['fields']['print'], 'print');

		$this->logger->MethodComplete();
	}


	protected function importFieldValues($xml, $field_id, $type)
	{
		static $model;

		if (!$model)
		{
			$model = $this->getModel('productFieldValues');
			$this->setState(0, 'productFieldValues', 'fl_source=' . $this->id);
		}

		foreach ($xml as $value)
		{
			switch ($type)
			{
				case 'filter':
					$id   = $value->filterid;
					$name = trim((string) $value->filtername);
					break;
				case 'print':
					$id   = (string) $value->code;
					$name = (string) $value->name;
					break;
				default:

					return;
			}

			$code = $this->id . '_' . $field_id . '-' . $id;

			$item = array(
				'fl_code'    => $code,
				'fl_source'  => $this->id,
				'fl_state'   => 1,
				'field_id'   => $field_id,
				'name_ru-RU' => $name,
			);

			$tmp = $this->getItem($code, 'productFieldValues');

			if ($tmp)
			{
				$item['id']       = $tmp['id'];
				$item['ordering'] = $tmp['ordering'];

				$action = 'update';
			}
			else
			{
				$action = 'import';

				$item['ordering'] = ++$this->counter['productFieldValues'];
			}

			$model->save($item);
		}
	}
}
