<?php
/**
 * Minimal test helpers (no dependencies)
 *
 * Tests never create vouchers or request CAE: only FEDummy / dummy
 * operations and offline checks are used.
 **/

error_reporting(E_ALL);
ini_set('display_errors', '1');

include_once __DIR__.'/../src/Afip.php';

$GLOBALS['TEST_PASSED']  = 0;
$GLOBALS['TEST_FAILED']  = 0;
$GLOBALS['TEST_SKIPPED'] = 0;
$GLOBALS['DEPRECATIONS'] = array();

// Deprecations are listed once at the end instead of after each test
set_error_handler(function ($level, $message, $file, $line) {
	$GLOBALS['DEPRECATIONS'][$message.' ('.basename($file).':'.$line.')'] = TRUE;
	return TRUE;
}, E_DEPRECATED | E_USER_DEPRECATED);

function test($name, $callback)
{
	try {
		$callback();
		$GLOBALS['TEST_PASSED']++;
		echo "[OK]   ".$name.PHP_EOL;
	} catch (Exception $e) {
		$GLOBALS['TEST_FAILED']++;
		echo "[FAIL] ".$name.PHP_EOL."       ".str_replace("\n", ' ', $e->getMessage()).PHP_EOL;
	}
}

function skip($name, $reason)
{
	$GLOBALS['TEST_SKIPPED']++;
	echo "[SKIP] ".$name.PHP_EOL."       ".$reason.PHP_EOL;
}

function check($condition, $message)
{
	if (!$condition)
		throw new Exception($message);
}

function finish()
{
	if (!empty($GLOBALS['DEPRECATIONS'])) {
		echo PHP_EOL.'Deprecations:'.PHP_EOL;
		foreach (array_keys($GLOBALS['DEPRECATIONS']) as $deprecation)
			echo '  '.$deprecation.PHP_EOL;
	}

	echo PHP_EOL.'PHP '.PHP_VERSION.' / '.OPENSSL_VERSION_TEXT.PHP_EOL;
	echo $GLOBALS['TEST_PASSED'].' passed, '.$GLOBALS['TEST_FAILED'].' failed, '.$GLOBALS['TEST_SKIPPED'].' skipped'.PHP_EOL;
	exit($GLOBALS['TEST_FAILED'] > 0 ? 1 : 0);
}

/**
 * Afip instance with empty cert and key in a temporary folder
 **/
function make_afip($production = FALSE, $ssl = NULL)
{
	static $folder;

	if (!isset($folder)) {
		$folder = sys_get_temp_dir().'/afip-tests-'.getmypid().'/';
		@mkdir($folder);
		touch($folder.'cert');
		touch($folder.'key');
		register_shutdown_function(function () use ($folder) {
			@unlink($folder.'cert');
			@unlink($folder.'key');
			@rmdir($folder);
		});
	}

	$options = array(
		'CUIT' 			=> 20111111112,
		'production' 	=> $production,
		'res_folder' 	=> $folder,
		'ta_folder' 	=> $folder
	);

	if (isset($ssl))
		$options['ssl'] = $ssl;

	return new Afip($options);
}

/**
 * Reads a SoapClient internal property (dynamic in PHP 7, private in PHP 8.1+)
 **/
function soap_property($client, $property)
{
	$reflection = new ReflectionObject($client);

	if (!$reflection->hasProperty($property))
		return NULL;

	$p = $reflection->getProperty($property);
	if (PHP_VERSION_ID < 80100)
		$p->setAccessible(TRUE);

	return $p->getValue($client);
}

/**
 * Options of the stream context used by a SoapClient
 **/
function soap_context_options($client)
{
	$context = soap_property($client, '_stream_context');

	return is_resource($context) ? stream_context_get_options($context) : array();
}

/**
 * SSL options of the stream context used by a SoapClient (NULL if none)
 **/
function soap_ssl_options($client)
{
	$options = soap_context_options($client);

	return isset($options['ssl']) ? $options['ssl'] : NULL;
}

/**
 * SoapClient built exactly as the library did before CreateSoapClient
 **/
function baseline_client($wsdl, $location, $soap_version)
{
	return new SoapClient($wsdl, array(
		'soap_version' 	=> $soap_version,
		'location' 		=> $location,
		'trace' 		=> 1,
		'exceptions' 	=> 0
	));
}

/**
 * Checks that a SoapClient is configured like the baseline one,
 * except for the SSL options of its stream context
 **/
function check_like_baseline($client, $wsdl, $location, $soap_version, $label)
{
	$baseline = baseline_client($wsdl, $location, $soap_version);

	foreach (array('location', '_soap_version', 'trace', '_exceptions') as $property)
		check(soap_property($client, $property) == soap_property($baseline, $property), $label.': '.$property.' differs from baseline');

	$options = soap_context_options($client);
	unset($options['ssl']);

	check($options == soap_context_options($baseline), $label.': stream context differs from baseline');
}
