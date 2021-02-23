<?php


namespace JoomShoppingImporter;


use JFactory;
use JModelLegacy;
use JSFactory;
use JTable;

class Manager
{
	private static $importers;

	private $config;

	private $joomlaRoot;

	// private static $logLevel = Logger::LEVEL_INFO;
	// private static $logType = Logger::TYPE_PRINT;
	private static $logFile = null;


	public function __construct(int $userId, string $langCode, ?string $appPath = null, ?Logger $logger = null)
	{
		$this->joomlaRoot = $this->findJoomlaRoot($appPath ?: __DIR__);
		$this->initApplicarion($userId, $langCode);

		$this->logger = $logger;

		$this->config = self::prepareConfig(true);
	}

	public function getImporter(string $vendor) : ImporterInterface
	{
		$class = __NAMESPACE__ . '\Importer' . ucfirst($vendor);

		$config = array_replace_recursive($this->config['common'], $this->config[$vendor]);

		return new $class($config, $this->logger);
	}


	public static function getListImporters()
	{
		if(self::$importers !== null)
		{
			return self::$importers;
		}

		self::$importers = [];

		$files = glob(__DIR__ . '/Importer*.php', GLOB_BRACE);

		foreach ($files as $file)
		{
			$class = pathinfo($file)['filename'];

			if($class == 'ImporterInterface')
			{
				continue;
			}

			$vendor = strtolower(str_ireplace('Importer', '', $class));

			self::$importers[$vendor] = __NAMESPACE__ .'\\'. $class;
		}

		return self::$importers;
	}


	public static function prepareConfig(bool $configRequired = false)
	{
		$defaults = [
			'common' => AbstractImporter::DEFAULTS,
		];

		foreach (self::getListImporters() as $name => $class)
		{
			$defaults[$name] = $class::DEFAULTS;
		}

		$config = [];

		if(is_file(IMPORTER_CONFIG_FILE))
		{
			$config = json_decode(file_get_contents(IMPORTER_CONFIG_FILE), true);
		}
		elseif($configRequired)
		{
			throw new \Exception('ERROR! Config file does not exist!');
		}

		return $config ? array_replace_recursive($defaults, $config) : $defaults;
	}


	public static function prepareCategories(bool $configRequired = false)
	{
		$defaults = [
			'common' => AbstractImporter::DEFAULTS,
		];

		foreach (self::getListImporters() as $name => $class)
		{
			$defaults[$name] = $class::DEFAULTS;
		}

		$config = [];

		if(is_file(IMPORTER_CONFIG_FILE))
		{
			$config = json_decode(file_get_contents(IMPORTER_CONFIG_FILE), true);
		}
		elseif($configRequired)
		{
			throw new \Exception('ERROR! Config file does not exist!');
		}

		return $config ? array_replace_recursive($defaults, $config) : $defaults;
	}


	public function getConfig()
	{
		return $this->config;
	}


	private function initApplicarion(int $userId, string $langCode) : void
	{
		// Load system defines
		if (file_exists($this->joomlaRoot . '/administrator/defines.php'))
		{
			require_once $this->joomlaRoot . '/administrator/defines.php';
		}

		if (!defined('_JDEFINES'))
		{
			define('JPATH_BASE', $this->joomlaRoot . DIRECTORY_SEPARATOR . 'administrator');
			require_once JPATH_BASE . '/includes/defines.php';
		}

		require_once JPATH_BASE . '/includes/framework.php';
		require_once JPATH_BASE . '/includes/helper.php';

		// Instantiate the application.
		JFactory::getApplication('administrator');

		$lang = JFactory::getLanguage();
		$lang->setDefault($langCode);
		$lang->setLanguage($langCode);

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

		JSFactory::setLoadUserId($userId);

		// fix redirect then product save (non autorized user)
		JSFactory::getConfig()->admin_show_vendors = 0;
	}


	public function getJoomlaRoot() : string
	{
		return $this->joomlaRoot;
	}


	private function findJoomlaRoot(string $dir, string $prev_dir = '') : string
	{
		static $needle = ['includes', 'layouts', 'libraries', 'media', 'plugins'];

		if ($prev_dir == $dir)
		{
			throw new \Exception('ERROR! Joomla not found!');
		}

		$curr_dirs = scandir($dir);

		if (array_intersect($needle, $curr_dirs) == $needle)
		{
			return $dir;
		}
		else
		{
			return $this->findJoomlaRoot(dirname($dir), $dir);
		}
	}
}
