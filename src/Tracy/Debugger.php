<?php

/**
 * This file is part of the Tracy (https://tracy.nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Tracy;

use ErrorException;
use Tracy;


/**
 * Debugger: displays and logs errors.
 */
class Debugger
{
	const VERSION = '2.4.12';

	/** server modes for Debugger::enable() */
	const
		DEVELOPMENT = false,
		PRODUCTION = true,
		DETECT = null;

	const COOKIE_SECRET = 'tracy-debug';

	/** Standard Debugger utility classes in tracy namespace */
	const 
		BAR = 'Bar', 
		BLUESCREEN = 'BlueScreen',
		DEFAULTBARPANEL = 'DefaultBarPanel', 
		DUMPER = 'Dumper',
		FIRELOGGER = 'FireLogger',
		HELPERS = 'Helpers', 
		LOGGER = 'Logger';
	
	/** @var bool in production mode is suppressed any debugging output */
	public static $productionMode = self::DETECT;

	/** @var bool whether to display debug bar in development mode */
	public static $showBar = true;

	/** @var bool */
	private static $enabled = false;

	/** @var string reserved memory; also prevents double rendering */
	private static $reserved;

	/** @var int initial output buffer level */
	private static $obLevel;

	/********************* errors and exceptions reporting ****************d*g**/

	/** @var bool|int determines whether any error will cause immediate death in development mode; if integer that it's matched against error severity */
	public static $strictMode = false;

	/** @var bool disables the @ (shut-up) operator so that notices and warnings are no longer hidden */
	public static $scream = false;

	/** @var array of callables specifies the functions that are automatically called after fatal error */
	public static $onFatalError = [];

	/********************* Debugger::dump() ****************d*g**/

	/** @var int  how many nested levels of array/object properties display by dump() */
	public static $maxDepth = 3;

	/** @var int  how long strings display by dump() */
	public static $maxLength = 150;

	/** @var bool display location by dump()? */
	public static $showLocation = false;

	/** @deprecated */
	public static $maxLen = 150;

	/********************* logging ****************d*g**/

	/** @var string name of the directory where errors should be logged */
	public static $logDirectory;

	/** @var int  log bluescreen in production mode for this error severity */
	public static $logSeverity = 0;

	/** @var string|array email(s) to which send error notifications */
	public static $email;

	/** for Debugger::log() and Debugger::fireLog() */
	const
		DEBUG = ILogger::DEBUG,
		INFO = ILogger::INFO,
		WARNING = ILogger::WARNING,
		ERROR = ILogger::ERROR,
		EXCEPTION = ILogger::EXCEPTION,
		CRITICAL = ILogger::CRITICAL;

	/********************* misc ****************d*g**/

	/** @var int timestamp with microseconds of the start of the request */
	public static $time;

	/** @var string URI pattern mask to open editor */
	public static $editor = 'editor://open/?file=%file&line=%line';

	/** @var array replacements in path */
	public static $editorMapping = [];

	/** @var string command to open browser (use 'start ""' in Windows) */
	public static $browser;

	/** @var string custom static error template */
	public static $errorTemplate;

	/** @var string[] */
	public static $customCssFiles = [];

	/** @var string[] */
	public static $customJsFiles = [];

	/** @var array */
	protected static $cpuUsage;

	/********************* services ****************d*g**/

	/** @var BlueScreen */
	protected static $blueScreen;

	/** @var Bar */
	protected static $bar;

	/** @var ILogger */
	protected static $logger;

	/** @var ILogger */
	protected static $fireLogger;


	/**
	 * Static class - cannot be instantiated.
	 */
	final public function __construct()
	{
		throw new \LogicException;
	}


	/**
	 * Enables displaying or logging errors and exceptions.
	 * @param  mixed   production, development mode, autodetection or IP address(es) whitelist.
	 * @param  string  error log directory
	 * @param  string  administrator email; enables email sending in production mode
	 * @return void
	 */
	public static function enable($mode = null, $logDirectory = null, $email = null)
	{
		if ($mode !== null || static::$productionMode === null) {
			static::$productionMode = is_bool($mode) ? $mode : !static::detectDebugMode($mode);
		}

		static::$maxLen = &static::$maxLength;
		static::$reserved = str_repeat('t', 30000);
		static::$time = isset($_SERVER['REQUEST_TIME_FLOAT']) ? $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
		static::$obLevel = ob_get_level();
		static::$cpuUsage = !static::$productionMode && function_exists('getrusage') ? getrusage() : null;

		// logging configuration
		if ($email !== null) {
			static::$email = $email;
		}
		if ($logDirectory !== null) {
			static::$logDirectory = $logDirectory;
		}
		if (static::$logDirectory) {
			if (!preg_match('#([a-z]+:)?[/\\\\]#Ai', static::$logDirectory)) {
				static::exceptionHandler(new \RuntimeException('Logging directory must be absolute path.'));
				static::$logDirectory = null;
			} elseif (!is_dir(static::$logDirectory)) {
				static::exceptionHandler(new \RuntimeException("Logging directory '" . static::$logDirectory . "' is not found."));
				static::$logDirectory = null;
			}
		}

		// php configuration
		if (function_exists('ini_set')) {
			ini_set('display_errors', static::$productionMode ? '0' : '1'); // or 'stderr'
			ini_set('html_errors', '0');
			ini_set('log_errors', '0');

		} elseif (ini_get('display_errors') != !static::$productionMode // intentionally ==
			&& ini_get('display_errors') !== (static::$productionMode ? 'stderr' : 'stdout')
		) {
			static::exceptionHandler(new \RuntimeException("Unable to set 'display_errors' because function ini_set() is disabled."));
		}
		error_reporting(E_ALL);

		if (static::$enabled) {
			return;
		}

		register_shutdown_function([get_called_class(), 'shutdownHandler']);
		set_exception_handler([get_called_class(), 'exceptionHandler']);
		set_error_handler([get_called_class(), 'errorHandler']);

		array_map('class_exists', [
				__NAMESPACE__ . '\\' . static::BAR,
				__NAMESPACE__ . '\\' . static::BLUESCREEN,
				__NAMESPACE__ . '\\' . static::DEFAULTBARPANEL, 
				__NAMESPACE__ . '\\' . static::DUMPER,
				__NAMESPACE__ . '\\' . static::FIRELOGGER, 
				__NAMESPACE__ . '\\' . static::HELPERS, 
				__NAMESPACE__ . '\\' . static::LOGGER, ]);

		static::dispatch();
		static::$enabled = true;
	}


	/**
	 * @return void
	 */
	public static function dispatch()
	{
		if (static::$productionMode || PHP_SAPI === 'cli') {
			return;

		} elseif (headers_sent($file, $line) || ob_get_length()) {
			throw new \LogicException(
				__METHOD__ . '() called after some output has been sent. '
				. ($file ? "Output started at $file:$line." : 'Try Tracy\OutputDebugger to find where output started.')
			);

		} elseif (static::$enabled && session_status() !== PHP_SESSION_ACTIVE) {
			ini_set('session.use_cookies', '1');
			ini_set('session.use_only_cookies', '1');
			ini_set('session.use_trans_sid', '0');
			ini_set('session.cookie_path', '/');
			ini_set('session.cookie_httponly', '1');
			session_start();
		}

		if (static::getBar()->dispatchAssets()) {
			exit;
		}
	}


	/**
	 * Renders loading <script>
	 * @return void
	 */
	public static function renderLoader()
	{
		if (!static::$productionMode) {
			static::getBar()->renderLoader();
		}
	}


	/**
	 * @return bool
	 */
	public static function isEnabled()
	{
		return static::$enabled;
	}


	/**
	 * Shutdown handler to catch fatal errors and execute of the planned activities.
	 * @return void
	 * @internal
	 */
	public static function shutdownHandler()
	{
		if (!static::$reserved) {
			return;
		}
		static::$reserved = null;

		$error = error_get_last();
		if (in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_RECOVERABLE_ERROR, E_USER_ERROR], true)) {
			static::exceptionHandler(
				Helpers::fixStack(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line'])),
				false
			);

		} elseif (static::$showBar && !static::$productionMode) {
			static::removeOutputBuffers(false);
			static::getBar()->render();
		}
	}


	/**
	 * Handler to catch uncaught exception.
	 * @param  \Exception|\Throwable
	 * @return void
	 * @internal
	 */
	public static function exceptionHandler($exception, $exit = true)
	{
		if (!static::$reserved && $exit) {
			return;
		}
		static::$reserved = null;

		if (!headers_sent()) {
			http_response_code(isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE ') !== false ? 503 : 500);
			if (Helpers::isHtmlMode()) {
				header('Content-Type: text/html; charset=UTF-8');
			}
		}

		Helpers::improveException($exception);
		static::removeOutputBuffers(true);

		if (static::$productionMode) {
			try {
				static::log($exception, static::EXCEPTION);
			} catch (\Exception $e) {
			} catch (\Throwable $e) {
			}

			if (Helpers::isHtmlMode()) {
				$logged = empty($e);
				require static::$errorTemplate ?: __DIR__ . '/assets/Debugger/error.500.phtml';
			} elseif (PHP_SAPI === 'cli') {
				fwrite(STDERR, 'ERROR: application encountered an error and can not continue. '
					. (isset($e) ? "Unable to log error.\n" : "Error was logged.\n"));
			}

		} elseif (!connection_aborted() && (Helpers::isHtmlMode() || Helpers::isAjax())) {
			static::getBlueScreen()->render($exception);
			if (static::$showBar) {
				static::getBar()->render();
			}

		} else {
			static::fireLog($exception);
			$s = get_class($exception) . ($exception->getMessage() === '' ? '' : ': ' . $exception->getMessage())
				. ' in ' . $exception->getFile() . ':' . $exception->getLine()
				. "\nStack trace:\n" . $exception->getTraceAsString();
			try {
				$file = static::log($exception, static::EXCEPTION);
				if ($file && !headers_sent()) {
					header("X-Tracy-Error-Log: $file");
				}
				echo "$s\n" . ($file ? "(stored in $file)\n" : '');
				if ($file && static::$browser) {
					exec(static::$browser . ' ' . escapeshellarg($file));
				}
			} catch (\Exception $e) {
				echo "$s\nUnable to log error: {$e->getMessage()}\n";
			} catch (\Throwable $e) {
				echo "$s\nUnable to log error: {$e->getMessage()}\n";
			}
		}

		try {
			$e = null;
			foreach (static::$onFatalError as $handler) {
				call_user_func($handler, $exception);
			}
		} catch (\Exception $e) {
		} catch (\Throwable $e) {
		}
		if ($e) {
			try {
				static::log($e, static::EXCEPTION);
			} catch (\Exception $e) {
			} catch (\Throwable $e) {
			}
		}

		if ($exit) {
			exit(255);
		}
	}


	/**
	 * Handler to catch warnings and notices.
	 * @return bool   false to call normal error handler, null otherwise
	 * @throws ErrorException
	 * @internal
	 */
	public static function errorHandler($severity, $message, $file, $line, $context = [])
	{
		if (static::$scream) {
			error_reporting(E_ALL);
		}

		if ($severity === E_RECOVERABLE_ERROR || $severity === E_USER_ERROR) {
			if (Helpers::findTrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), '*::__toString')) {
				$previous = isset($context['e']) && ($context['e'] instanceof \Exception || $context['e'] instanceof \Throwable) ? $context['e'] : null;
				$e = new ErrorException($message, 0, $severity, $file, $line, $previous);
				$e->context = $context;
				static::exceptionHandler($e);
			}

			$e = new ErrorException($message, 0, $severity, $file, $line);
			$e->context = $context;
			throw $e;

		} elseif (($severity & error_reporting()) !== $severity) {
			return false; // calls normal error handler to fill-in error_get_last()

		} elseif (static::$productionMode && ($severity & static::$logSeverity) === $severity) {
			$e = new ErrorException($message, 0, $severity, $file, $line);
			$e->context = $context;
			Helpers::improveException($e);
			try {
				static::log($e, static::ERROR);
			} catch (\Exception $foo) {
			} catch (\Throwable $foo) {
			}
			return null;

		} elseif (!static::$productionMode && !isset($_GET['_tracy_skip_error'])
			&& (is_bool(static::$strictMode) ? static::$strictMode : ((static::$strictMode & $severity) === $severity))
		) {
			$e = new ErrorException($message, 0, $severity, $file, $line);
			$e->context = $context;
			$e->skippable = true;
			static::exceptionHandler($e);
		}

		$message = 'PHP ' . Helpers::errorTypeToString($severity) . ": $message";
		$count = &static::getBar()->getPanel('Tracy:errors')->data["$file|$line|$message"];

		if ($count++) { // repeated error
			return null;

		} elseif (static::$productionMode) {
			try {
				static::log("$message in $file:$line", static::ERROR);
			} catch (\Exception $foo) {
			} catch (\Throwable $foo) {
			}
			return null;

		} else {
			static::fireLog(new ErrorException($message, 0, $severity, $file, $line));
			return Helpers::isHtmlMode() || Helpers::isAjax() ? null : false; // false calls normal error handler
		}
	}


	protected static function removeOutputBuffers($errorOccurred)
	{
		while (ob_get_level() > static::$obLevel) {
			$status = ob_get_status();
			if (in_array($status['name'], ['ob_gzhandler', 'zlib output compression'], true)) {
				break;
			}
			$fnc = $status['chunk_size'] || !$errorOccurred ? 'ob_end_flush' : 'ob_end_clean';
			if (!@$fnc()) { // @ may be not removable
				break;
			}
		}
	}


	/********************* services ****************d*g**/


	/**
	 * @return BlueScreen
	 */
	public static function getBlueScreen()
	{
		if (!static::$blueScreen) {
			static::$blueScreen = new BlueScreen;
			static::$blueScreen->info = [
				'PHP ' . PHP_VERSION,
				isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : null,
				'Tracy ' . static::VERSION,
			];
		}
		return static::$blueScreen;
	}


	/**
	 * @return Bar
	 */
	public static function getBar()
	{
		if (!static::$bar) {
			static::$bar = new Bar;
			static::$bar->addPanel($info = new DefaultBarPanel('info'), 'Tracy:info');
			$info->cpuUsage = static::$cpuUsage;
			static::$bar->addPanel(new DefaultBarPanel('errors'), 'Tracy:errors'); // filled by errorHandler()
		}
		return static::$bar;
	}


	/**
	 * @return void
	 */
	public static function setLogger(ILogger $logger)
	{
		static::$logger = $logger;
	}


	/**
	 * @return ILogger
	 */
	public static function getLogger()
	{
		if (!static::$logger) {
			static::$logger = new Logger(static::$logDirectory, static::$email, static::getBlueScreen());
			static::$logger->directory = &static::$logDirectory; // back compatiblity
			static::$logger->email = &static::$email;
		}
		return static::$logger;
	}


	/**
	 * @return ILogger
	 */
	public static function getFireLogger()
	{
		if (!static::$fireLogger) {
			static::$fireLogger = new FireLogger;
		}
		return static::$fireLogger;
	}


	/********************* useful tools ****************d*g**/


	/**
	 * Dumps information about a variable in readable format.
	 * @tracySkipLocation
	 * @param  mixed  variable to dump
	 * @param  bool   return output instead of printing it? (bypasses $productionMode)
	 * @return mixed  variable itself or dump
	 */
	public static function dump($var, $return = false)
	{
		if ($return) {
			ob_start(function () {});
			Dumper::dump($var, [
				Dumper::DEPTH => static::$maxDepth,
				Dumper::TRUNCATE => static::$maxLength,
			]);
			return ob_get_clean();

		} elseif (!static::$productionMode) {
			Dumper::dump($var, [
				Dumper::DEPTH => static::$maxDepth,
				Dumper::TRUNCATE => static::$maxLength,
				Dumper::LOCATION => static::$showLocation,
			]);
		}

		return $var;
	}


	/**
	 * Starts/stops stopwatch.
	 * @param  string  name
	 * @return float   elapsed seconds
	 */
	public static function timer($name = null)
	{
		static $time = [];
		$now = microtime(true);
		$delta = isset($time[$name]) ? $now - $time[$name] : 0;
		$time[$name] = $now;
		return $delta;
	}


	/**
	 * Dumps information about a variable in Tracy Debug Bar.
	 * @tracySkipLocation
	 * @param  mixed  variable to dump
	 * @param  string optional title
	 * @param  array  dumper options
	 * @return mixed  variable itself
	 */
	public static function barDump($var, $title = null, array $options = null)
	{
		if (!static::$productionMode) {
			static $panel;
			if (!$panel) {
				static::getBar()->addPanel($panel = new DefaultBarPanel('dumps'), 'Tracy:dumps');
			}
			$panel->data[] = ['title' => $title, 'dump' => Dumper::toHtml($var, (array) $options + [
				Dumper::DEPTH => static::$maxDepth,
				Dumper::TRUNCATE => static::$maxLength,
				Dumper::LOCATION => static::$showLocation ?: Dumper::LOCATION_CLASS | Dumper::LOCATION_SOURCE,
			])];
		}
		return $var;
	}


	/**
	 * Logs message or exception.
	 * @param  string|\Exception|\Throwable
	 * @return mixed
	 */
	public static function log($message, $priority = ILogger::INFO)
	{
		return static::getLogger()->log($message, $priority);
	}


	/**
	 * Sends message to FireLogger console.
	 * @param  mixed   message to log
	 * @return bool    was successful?
	 */
	public static function fireLog($message)
	{
		if (!static::$productionMode) {
			return static::getFireLogger()->log($message);
		}
	}


	/**
	 * Detects debug mode by IP address.
	 * @param  string|array  IP addresses or computer names whitelist detection
	 * @return bool
	 */
	public static function detectDebugMode($list = null)
	{
		$addr = isset($_SERVER['REMOTE_ADDR'])
			? $_SERVER['REMOTE_ADDR']
			: php_uname('n');
		$secret = isset($_COOKIE[static::COOKIE_SECRET]) && is_string($_COOKIE[static::COOKIE_SECRET])
			? $_COOKIE[static::COOKIE_SECRET]
			: null;
		$list = is_string($list)
			? preg_split('#[,\s]+#', $list)
			: (array) $list;
		if (!isset($_SERVER['HTTP_X_FORWARDED_FOR']) && !isset($_SERVER['HTTP_FORWARDED'])) {
			$list[] = '127.0.0.1';
			$list[] = '::1';
		}
		return in_array($addr, $list, true) || in_array("$secret@$addr", $list, true);
	}
}
