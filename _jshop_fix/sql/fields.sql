ALTER TABLE `#__jshopping_attr_values`
  ADD `fl_code` varchar(255) NOT NULL

ALTER TABLE `#__jshopping_categories`
  ADD `fl_code` varchar(255) NOT NULL,
  ADD `fl_source` int(11) NOT NULL,
  ADD `fl_update_date` datetime DEFAULT NULL

ALTER TABLE `#__jshopping_manufacturers`
  ADD `fl_code` varchar(255) NOT NULL,
  ADD `fl_source` int(11) NOT NULL,

ALTER TABLE `#__jshopping_products_attr`
  ADD `fl_code` varchar(255) NOT NULL,
  ADD `fl_source` int(11) NOT NULL,

ALTER TABLE `#__jshopping_products_extra_fields`
  ADD `fl_code` varchar(255) NOT NULL,
  ADD `fl_source` int(11) NOT NULL,
  ADD `fl_state` tinyint(1) UNSIGNED NOT NULL DEFAULT '1'

ALTER TABLE `#__jshopping_products_extra_fields`
  ADD `fl_code` varchar(255) NOT NULL,
  ADD `fl_source` int(11) NOT NULL,
  ADD `fl_state` tinyint(1) UNSIGNED NOT NULL DEFAULT '1'
