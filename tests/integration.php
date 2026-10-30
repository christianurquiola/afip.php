<?php
/**
 * External tests (network access, non fiscal)
 *
 * Nothing is contacted unless --network is given explicitly:
 *
 *   php tests/integration.php --network=testing [--seclevel=2|system]
 *   php tests/integration.php --network=production [--seclevel=2|system]
 *
 * --network=testing      ARCA homologation (wswhomo, wsaahomo, awshomo) and
 *                        public TLS test hosts (badssl.com, www.cloudflare.com)
 * --network=production   also ARCA production: FEDummy / dummy and an invalid
 *                        CMS to WSAA only. Run it only with explicit authorization
 *
 * --seclevel=2 (default) forces SECLEVEL=2 in this process with the
 *                        'security_level' SSL option (PHP >= 7.2, OpenSSL >= 1.1.0)
 * --seclevel=system      OpenSSL configuration of the environment. To emulate a
 *                        global SECLEVEL=2 without changing the server, run with
 *                        OPENSSL_CONF pointing to a file with
 *                        "CipherString = DEFAULT:@SECLEVEL=2" (see README)
 *
 * Optional homologation credentials, read from environment variables and
 * never stored in the repository. They are only used against homologation
 * (WSAA login and FECompUltimoAutorizado, a query):
 *
 *   AFIP_TEST_CUIT, AFIP_TEST_CERT, AFIP_TEST_KEY   (absolute paths)
 *   AFIP_TEST_PASSPHRASE                            (optional)
 *   AFIP_TEST_TA_FOLDER                             (optional, writable folder to
 *                                                    keep the TA between runs)
 *   AFIP_TEST_PTO_VTA, AFIP_TEST_CBTE_TIPO          (optional)
 *
 * No vouchers are created and no CAE is requested.
 **/

include __DIR__.'/bootstrap.php';

$args = array();
foreach (array_slice($argv, 1) as $arg) {
	if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $match))
		$args[$match[1]] = $match[2];
}

$network  = isset($args['network']) ? $args['network'] : NULL;
$seclevel = isset($args['seclevel']) ? $args['seclevel'] : '2';

if (!in_array($network, array('testing', 'production'), TRUE) || !in_array($seclevel, array('2', 'system'), TRUE)) {
	echo 'External tests were not run: --network=testing or --network=production is required.'.PHP_EOL;
	echo 'Usage: php tests/integration.php --network=testing|production [--seclevel=2|system]'.PHP_EOL;
	exit(2);
}

$ssl          = $seclevel === '2' ? array('security_level' => 2) : NULL;
$environments = $network === 'production' ? array(FALSE, TRUE) : array(FALSE);
$padron       = array('RegisterScopeFour', 'RegisterScopeFive', 'RegisterScopeTen', 'RegisterScopeThirteen');

echo 'Network: '.$network.PHP_EOL;
echo 'Security level: '.($ssl ? 'SECLEVEL=2 forced per process' : 'OpenSSL configuration'.(getenv('OPENSSL_CONF') ? ' (OPENSSL_CONF='.getenv('OPENSSL_CONF').')' : '')).PHP_EOL.PHP_EOL;

/**
 * TLS handshake with the given SSL options, returns the crypto
 * metadata or FALSE if the handshake fails
 **/
function handshake($host, $ssl)
{
	$ssl = (isset($ssl) ? $ssl : array()) + array('verify_peer' => TRUE, 'verify_peer_name' => TRUE);

	$socket = @stream_socket_client('ssl://'.$host.':443', $errno, $errstr, 20, STREAM_CLIENT_CONNECT, stream_context_create(array('ssl' => $ssl)));
	if ($socket === FALSE)
		return FALSE;

	$meta = stream_get_meta_data($socket);
	fclose($socket);

	return $meta['crypto'];
}

function expect_connection_error($callback, $label)
{
	try {
		$callback();
	} catch (Exception $e) {
		check($e->getCode() == 4 && strpos($e->getMessage(), 'HTTP') !== FALSE, $label.': unexpected error: '.$e->getMessage());
		return;
	}
	throw new Exception($label.': connection was accepted');
}

/**
 * Afip instance (homologation only) with the credentials of the environment
 **/
function make_afip_credentials($ssl)
{
	$ta_folder = getenv('AFIP_TEST_TA_FOLDER');

	if (!$ta_folder) {
		$ta_folder = sys_get_temp_dir().'/afip-tests-ta-'.getmypid();
		@mkdir($ta_folder);
		register_shutdown_function(function () use ($ta_folder) {
			foreach ((array) glob($ta_folder.'/*') as $file)
				@unlink($file);
			@rmdir($ta_folder);
		});
	}

	$options = array(
		'CUIT' 			=> getenv('AFIP_TEST_CUIT'),
		'production' 	=> FALSE,
		'res_folder' 	=> '',
		'cert' 			=> getenv('AFIP_TEST_CERT'),
		'key' 			=> getenv('AFIP_TEST_KEY'),
		'ta_folder' 	=> rtrim($ta_folder, '/\\').'/'
	);

	if (getenv('AFIP_TEST_PASSPHRASE') !== FALSE)
		$options['passphrase'] = getenv('AFIP_TEST_PASSPHRASE');

	if (isset($ssl))
		$options['ssl'] = $ssl;

	return new Afip($options);
}

// Environment check: a server with a 1024 bits DH key, like servicios1.afip.gov.ar
$weak_dh = handshake('dh1024.badssl.com', $ssl);
if ($ssl) {
	test('Environment: DH 1024 (dh1024.badssl.com) is rejected at SECLEVEL=2', function () use ($weak_dh) {
		check($weak_dh === FALSE, 'DH 1024 accepted: SECLEVEL=2 is not effective');
	});
} else {
	echo '[INFO] Environment '.($weak_dh === FALSE ? 'rejects DH 1024 (SECLEVEL >= 2)' : 'accepts DH 1024 (SECLEVEL < 2, the fix is not being exercised)').PHP_EOL;
}

foreach ($environments as $production) {
	$env = $production ? 'production' : 'testing';

	test("WSFE ($env) FEDummy", function () use ($production, $ssl) {
		$status = make_afip($production, $ssl)->ElectronicBilling->GetServerStatus();
		check($status->AppServer === 'OK' && $status->DbServer === 'OK' && $status->AuthServer === 'OK', 'Status: '.json_encode($status));
	});

	test("WSFE ($env) negotiates ECDHE with forward secrecy (not static RSA)", function () use ($production, $ssl) {
		$ws = make_afip($production, $ssl)->ElectronicBilling;
		$ws->GetServerStatus();

		$crypto = handshake(parse_url($ws->URL, PHP_URL_HOST), soap_ssl_options($ws->soap_client));
		check($crypto !== FALSE, 'TLS handshake failed');
		echo '       '.$crypto['protocol'].' '.$crypto['cipher_name'].PHP_EOL;
		check(strpos($crypto['cipher_name'], 'ECDHE-') === 0, 'Negotiated '.$crypto['cipher_name']);
	});

	test("Padron A4/A5/A10/A13 ($env) dummy", function () use ($production, $ssl, $padron) {
		$afip = make_afip($production, $ssl);
		foreach ($padron as $service) {
			$status = $afip->{$service}->GetServerStatus();
			check(isset($status->appserver) && $status->appserver === 'OK', $service.' status: '.json_encode($status));
		}
	});

	test("WSAA ($env) reachable: invalid CMS is rejected by the server, not by TLS", function () use ($production, $ssl) {
		$afip    = make_afip($production, $ssl);
		$results = $afip->CreateSoapClient($afip->WSAA_WSDL, $afip->WSAA_URL, SOAP_1_2)->loginCms(array('in0' => 'invalid'));

		check(is_soap_fault($results), 'Expected SOAP fault');
		check($results->faultcode !== 'HTTP', 'Connection error: '.$results->faultstring);
		echo '       '.$results->faultcode.': '.trim($results->faultstring).PHP_EOL;
	});
}

if ($network === 'production') {
	test('Control: WSFE production with DEFAULT ciphers at SECLEVEL=2 fails and is reported as SOAP Fault', function () {
		$ws = make_afip(TRUE, array('ciphers' => 'DEFAULT', 'security_level' => 2))->ElectronicBilling;

		expect_connection_error(function () use ($ws) { $ws->GetServerStatus(); }, 'WSFE DEFAULT');
	});

	test('Control: WSFE production without the fix (ssl_ciphers = NULL) fails at SECLEVEL=2', function () {
		$ws = make_afip(TRUE, array('security_level' => 2))->ElectronicBilling;
		$ws->ssl_ciphers = NULL;

		expect_connection_error(function () use ($ws) { $ws->GetServerStatus(); }, 'WSFE without fix');
	});
}

test('Control: without the ECDHE policy a DH 1024 server fails at SECLEVEL=2 and is reported as SOAP Fault', function () {
	$ws = make_afip(FALSE, array('security_level' => 2))->ElectronicBilling;
	$ws->ssl_ciphers = NULL;
	$ws->URL = 'https://dh1024.badssl.com/';

	expect_connection_error(function () use ($ws) { $ws->GetServerStatus(); }, 'DH 1024');
});

test('ECDHE+AESGCM policy does not block TLS 1.3 (www.cloudflare.com)', function () use ($ssl) {
	$ws     = make_afip(FALSE, $ssl)->ElectronicBilling;
	$client = $ws->afip->CreateSoapClient($ws->WSDL, $ws->URL, $ws->soap_version, $ws->ssl_ciphers);
	$crypto = handshake('www.cloudflare.com', soap_ssl_options($client));

	check($crypto !== FALSE, 'TLS handshake failed');
	echo '       '.$crypto['protocol'].' '.$crypto['cipher_name'].PHP_EOL;
	check($crypto['protocol'] === 'TLSv1.3', 'Negotiated '.$crypto['protocol']);
});

test('TLS 1.3 keeps host name verification (www.cloudflare.com with another peer name)', function () use ($ssl) {
	$ws     = make_afip(FALSE, (isset($ssl) ? $ssl : array()) + array('peer_name' => 'mismatch.invalid'))->ElectronicBilling;
	$client = $ws->afip->CreateSoapClient($ws->WSDL, $ws->URL, $ws->soap_version, $ws->ssl_ciphers);

	check(handshake('www.cloudflare.com', soap_ssl_options($client)) === FALSE, 'Handshake accepted');
});

test('Peer name mismatch is rejected (WSFE homologation)', function () use ($ssl) {
	$ws = make_afip(FALSE, (isset($ssl) ? $ssl : array()) + array('peer_name' => 'mismatch.invalid'))->ElectronicBilling;

	expect_connection_error(function () use ($ws) { $ws->GetServerStatus(); }, 'peer_name');
});

foreach (array('self-signed', 'expired', 'wrong.host', 'untrusted-root') as $badssl) {
	test("Invalid certificate ($badssl.badssl.com) is rejected (WSFE, Padron, and 'ssl' verify_peer=false)", function () use ($badssl, $ssl) {
		$services = array(
			'ElectronicBilling' 					=> make_afip(FALSE, $ssl)->ElectronicBilling,
			'RegisterScopeFour' 					=> make_afip(FALSE, $ssl)->RegisterScopeFour,
			'ElectronicBilling verify_peer=false' 	=> make_afip(FALSE, array('verify_peer' => FALSE, 'verify_peer_name' => FALSE))->ElectronicBilling
		);

		foreach ($services as $label => $ws) {
			$ws->URL = 'https://'.$badssl.'.badssl.com/';
			expect_connection_error(function () use ($ws) { $ws->GetServerStatus(); }, $label);
		}
	});
}

test('Control: valid certificate (badssl.com) connects (server answers, no TLS error)', function () use ($ssl) {
	$ws = make_afip(FALSE, $ssl)->ElectronicBilling;
	$ws->URL = 'https://badssl.com/';

	try {
		$ws->GetServerStatus();
	} catch (Exception $e) {
		check(strpos($e->getMessage(), 'Could not connect to host') === FALSE, 'TLS error: '.$e->getMessage());
	}
});

if (getenv('AFIP_TEST_CUIT') && getenv('AFIP_TEST_CERT') && getenv('AFIP_TEST_KEY')) {
	$afip = make_afip_credentials($ssl);

	test('WSAA homologation login (GetServiceTA wsfe)', function () use ($afip) {
		$ta = $afip->GetServiceTA('wsfe');
		check($ta instanceof TokenAutorization, 'TA not obtained (check certificate, key and passphrase)');
		check(strlen($ta->token) > 0 && strlen($ta->sign) > 0, 'Empty token or sign');
	});

	if (getenv('AFIP_TEST_PTO_VTA') && getenv('AFIP_TEST_CBTE_TIPO')) {
		test('WSFE homologation FECompUltimoAutorizado (query, no CAE)', function () use ($afip) {
			$number = $afip->ElectronicBilling->GetLastVoucher((int) getenv('AFIP_TEST_PTO_VTA'), (int) getenv('AFIP_TEST_CBTE_TIPO'));
			check(is_numeric($number), 'Unexpected result: '.json_encode($number));
			echo '       Last voucher: '.$number.PHP_EOL;
		});
	} else {
		skip('WSFE homologation FECompUltimoAutorizado (query, no CAE)', 'AFIP_TEST_PTO_VTA / AFIP_TEST_CBTE_TIPO not set');
	}
} else {
	skip('WSAA homologation login and FECompUltimoAutorizado', 'AFIP_TEST_CUIT / AFIP_TEST_CERT / AFIP_TEST_KEY not set');
}

finish();
