<?php

/*
Copyright (c) 2018, Florian Schaal - schaal @it UG
All rights reserved.

Redistribution and use in source and binary forms, with or without modification,
are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright notice,
      this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright notice,
      this list of conditions and the following disclaimer in the documentation
      and/or other materials provided with the distribution.
    * Neither the name of ISPConfig nor the names of its contributors
      may be used to endorse or promote products derived from this software without
      specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY
OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE,
EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';

//* Check permissions for module
$app->auth->check_module_permissions('mail');

$app->uses('functions');

$type = $_GET['type'];
$domain_id = $app->functions->idn_encode($_GET['domain_id']);

if($type == 'create_dkim' && $domain_id != ''){
	$dkim_selector = $_GET['dkim_selector'];
	$domain = $domain_id;
	if(is_numeric($domain_id)) {
		$temp = $app->db->queryOneRecord("SELECT domain FROM domain WHERE domain_id = ? AND ".$app->tform->getAuthSQL('r'), $domain_id);
		$domain = $temp['domain'];
	}
	$rec = $app->db->queryOneRecord("SELECT server_id FROM mail_domain WHERE domain = ?", $domain);
	$server_id = $rec['server_id'];
	unset($rec);
	$mail_config = $app->getconf->get_server_config($server_id, 'mail');
	$dkim_strength = $app->functions->intval($mail_config['dkim_strength']);
	if ($dkim_strength == '' || $dkim_strength == 0 ) $dkim_strength = 2048;

	// Generate a new private key.
	$dkim_private = openssl_pkey_new(['private_key_bits' => $dkim_strength]);
	$dkim_private_pem = '';
	openssl_pkey_export($dkim_private, $dkim_private_pem);
	$dkim_public = openssl_pkey_get_details($dkim_private)['key'];

	if (!validate_selector($dkim_selector) ) {
		$dkim_selector = 'invalid selector';
	}

	$dns_key = str_replace(array('-----BEGIN PUBLIC KEY-----','-----END PUBLIC KEY-----',"\r","\n"), '', $dkim_public);
	$dkim_txt_data = 'v=DKIM1; t=s; p=' . $dns_key;
	$dns_record = $dkim_selector . '._domainkey.' . $domain . '. 3600  IN  TXT   "' . $dkim_txt_data . '"';

	$output = [
		'dkim_private' => $dkim_private_pem,
		'dkim_public' => $dkim_public,
		'dkim_selector' => $dkim_selector,
		'dns_record' => $dns_record,
		'domain' => $domain,
	];
	header('Content-type: application/json');
	echo json_encode($output);
}
else {
	// Invalid
}

function validate_selector($selector) {
	$regex = '/^[a-z0-9]{0,63}$/';
	if ( preg_match($regex, $selector) === 1 ) return true; else return false;
}

?>
