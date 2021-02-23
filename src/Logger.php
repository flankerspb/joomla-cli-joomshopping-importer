<?php


namespace JoomShoppingImporter;


class Logger
{
	const LEVEL_ERROR = 1;
	const LEVEL_WARNING = 2;
	const LEVEL_NOTICE = 4;
	const LEVEL_INFO = 8;

	const LOG_LEVEL = [
		0 => false,           // Not log
		1 => 'Error',         // Errors only
		//1 => 'Important',   // + Important
		2 => 'Warning',       // + Warnings
		3 => 'Attention',     // + Attentions
		4 => 'Done',          // + Done
		5 => 'Info',          // + Info
	];

	const TYPE_PRINT = 0;
	const TYPE_FILE = 1;

	private $on = true;
	private $logType = self::TYPE_PRINT;
	private $logFile = null;

	private $logLevel;

	public function __construct(int $logLevel = self::LEVEL_INFO, ?string $logFile = null)
	{
		$this->logLevel = $logLevel;

		if(!$logLevel)
		{
			$this->on = false;
		}

		if($logFile)
		{
			if(touch($logFile) && is_writable($logFile))
			{
				$this->logType = 'file';
				$this->logFile = $logFile;
			}
			else
			{
				$this->log('No access to log file: ' . $logFile, self::LEVEL_ERROR);
				$this->on = false;
			}
		}
	}


	private static function getCaller()
	{
		foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4) as $info)
		{
			if($info['class'] != __CLASS__)
			{
				return $info;
			}
		}

		return [];
	}


	public function on() : void
	{
		$this->on = true;
	}


	public function off() : void
	{
		$this->on = false;
	}


	public function setLogLevel(int $level) : void
	{
		$this->logLevel = $level;
	}


	public function MethodStart()
	{
		$this->log($this->getCaller()['function'] . ' start', self::LEVEL_INFO);
	}


	public function MethodComplete()
	{
		$this->log($this->getCaller()['function'] . ' complete', self::LEVEL_INFO);
	}


	public function error($string)
	{
		$this->log($string, self::LEVEL_ERROR);
	}


	public function warning($string)
	{
		$this->log($string, self::LEVEL_WARNING);
	}


	public function notice($string)
	{
		$this->log($string, self::LEVEL_NOTICE);
	}


	public function info($string)
	{
		$this->log($string, self::LEVEL_INFO);
	}


	public function log($string, $level)
	{
		if(!$this->on || $level > $this->logLevel)
		{
			return;
		}

		switch ($this->logType)
		{
			case self::TYPE_PRINT :
				if (PHP_SAPI === 'cli')
				{
					echo $string . PHP_EOL;
				}
				else
				{
					var_dump($string);
				}
				break;
			case self::TYPE_FILE :
				$handle = fopen($this->logFile, 'a');
				fwrite($handle, date('Y-m-d H:i:s') . ' - ' . $string . PHP_EOL);
				fclose($handle);
				break;
		}
	}
}
