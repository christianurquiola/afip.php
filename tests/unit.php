<?php
/**
 * Offline tests: SoapClient creation and SSL context configuration
 *
 * Usage: php tests/unit.php
 *
 * No network access is needed: requests are sent to a closed local
 * port, so they fail before leaving the machine.
 **/

include __DIR__.'/bootstrap.php';

define('CLOSED_URL', 'https://127.0.0.1:1/');

/**
 * Runs an operation against a closed port and returns the service
 * with its SoapClient already created
 **/
function service_with_client($afip, $service, $operation)
{
	$ws = $afip->{$service};
	$ws->URL = CLOSED_URL;

	try {
		$ws->ExecuteRequest($operation);
		throw new Exception('Request to a closed port did not fail');
	} catch (Exception $e) {
		check($e->getCode() == 4, 'Expected SOAP Fault exception (code 4), got: '.$e->getMessage());
	}

	check($ws->soap_client instanceof SoapClient, 'SoapClient was not created');

	return $ws;
}

$padron = array('RegisterScopeFour', 'RegisterScopeFive', 'RegisterScopeTen', 'RegisterScopeThirteen');

test('ElectronicBilling declares ECDHE+AESGCM; Padron services use system default', function () use ($padron) {
	$afip = make_afip();
	check($afip->ElectronicBilling->ssl_ciphers === 'ECDHE+AESGCM', 'Unexpected ElectronicBilling ciphers');
	foreach ($padron as $service)
		check($afip->{$service}->ssl_ciphers === NULL, $service.' must not declare ciphers');
});

foreach (array(TRUE, FALSE) as $production) {
	$env = $production ? 'production' : 'testing';

	test("ElectronicBilling ($env): baseline SOAP options + ECDHE context with peer verification", function () use ($production) {
		$ws  = service_with_client(make_afip($production), 'ElectronicBilling', 'FEDummy');
		$ssl = soap_ssl_options($ws->soap_client);

		check_like_baseline($ws->soap_client, $ws->WSDL, CLOSED_URL, SOAP_1_2, 'ElectronicBilling');
		check($ssl === array('ciphers' => 'ECDHE+AESGCM', 'verify_peer' => TRUE, 'verify_peer_name' => TRUE), 'Unexpected SSL options: '.json_encode($ssl));
	});

	test("Padron services ($env): SoapClient identical to baseline, no SSL options", function () use ($production, $padron) {
		$afip = make_afip($production);
		foreach ($padron as $service) {
			$ws = service_with_client($afip, $service, 'dummy');
			check(soap_ssl_options($ws->soap_client) === NULL, $service.' got SSL options');
			check_like_baseline($ws->soap_client, $ws->WSDL, CLOSED_URL, SOAP_1_1, $service);
		}
	});

	test("WSAA ($env): SoapClient identical to baseline, no SSL options", function () use ($production) {
		$afip   = make_afip($production);
		$client = $afip->CreateSoapClient($afip->WSAA_WSDL, $afip->WSAA_URL, SOAP_1_2);

		check(soap_ssl_options($client) === NULL, 'WSAA got SSL options');
		check_like_baseline($client, $afip->WSAA_WSDL, $afip->WSAA_URL, SOAP_1_2, 'WSAA');
	});
}

test("'ssl' option overrides ciphers and keeps other options", function () {
	$afip = make_afip(TRUE, array('ciphers' => 'ECDHE+AESGCM:ECDHE+CHACHA20', 'security_level' => 2));
	$ssl  = soap_ssl_options(service_with_client($afip, 'ElectronicBilling', 'FEDummy')->soap_client);

	check($ssl['ciphers'] === 'ECDHE+AESGCM:ECDHE+CHACHA20', 'ciphers option not applied');
	check($ssl['security_level'] === 2, 'security_level option lost');
});

test("'ssl' option cannot disable peer verification", function () {
	$afip = make_afip(TRUE, array('verify_peer' => FALSE, 'verify_peer_name' => FALSE));

	foreach (array('ElectronicBilling' => 'FEDummy', 'RegisterScopeFour' => 'dummy') as $service => $operation) {
		$ssl = soap_ssl_options(service_with_client($afip, $service, $operation)->soap_client);
		check($ssl['verify_peer'] === TRUE && $ssl['verify_peer_name'] === TRUE, $service.' verification disabled');
	}
});

test("Non array 'ssl' option is ignored (no error, default policy kept)", function () {
	foreach (array(NULL, FALSE, TRUE, '', 'DEFAULT', 1) as $value) {
		$label = 'ssl='.var_export($value, TRUE);
		$afip  = make_afip(TRUE, array());
		$afip->options['ssl'] = $value;

		$ws  = service_with_client($afip, 'ElectronicBilling', 'FEDummy');
		$ssl = soap_ssl_options($ws->soap_client);
		check($ssl === array('ciphers' => 'ECDHE+AESGCM', 'verify_peer' => TRUE, 'verify_peer_name' => TRUE), $label.': unexpected SSL options '.json_encode($ssl));

		$ws = service_with_client($afip, 'RegisterScopeFour', 'dummy');
		check(soap_ssl_options($ws->soap_client) === NULL, $label.': RegisterScopeFour got SSL options');
	}
});

test("'ssl' => array('ciphers' => NULL) disables the policy (WSFE identical to baseline)", function () {
	$ws = service_with_client(make_afip(TRUE, array('ciphers' => NULL)), 'ElectronicBilling', 'FEDummy');

	check(soap_ssl_options($ws->soap_client) === NULL, 'WSFE still has SSL options');
	check_like_baseline($ws->soap_client, $ws->WSDL, CLOSED_URL, SOAP_1_2, 'ElectronicBilling');
});

test("'ssl' => array('ciphers' => NULL, ...) keeps the other options with PHP default ciphers", function () {
	$ws  = service_with_client(make_afip(TRUE, array('ciphers' => NULL, 'security_level' => 2)), 'ElectronicBilling', 'FEDummy');
	$ssl = soap_ssl_options($ws->soap_client);

	check(!array_key_exists('ciphers', $ssl), 'ciphers must not be set');
	check($ssl['security_level'] === 2 && $ssl['verify_peer'] === TRUE && $ssl['verify_peer_name'] === TRUE, 'Unexpected SSL options: '.json_encode($ssl));
});

test('Existing consumer config (no ssl option) still builds every service', function () use ($padron) {
	$afip = make_afip(TRUE);
	foreach (array_merge(array('ElectronicBilling'), $padron) as $service)
		check($afip->{$service} instanceof AfipWebService, $service.' not created');
});

// Keep last: the default context can not be restored in the same process
test('Default stream context is not imported (same as SoapClient without context)', function () {
	stream_context_set_default(array('ssl' => array('cafile' => '/custom/ca.pem')));

	$ws  = service_with_client(make_afip(TRUE), 'ElectronicBilling', 'FEDummy');
	$ssl = soap_ssl_options($ws->soap_client);

	check(!isset($ssl['cafile']), 'Default context options were imported');
	check_like_baseline($ws->soap_client, $ws->WSDL, CLOSED_URL, SOAP_1_2, 'ElectronicBilling');
});

finish();
