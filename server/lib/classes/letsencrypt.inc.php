<?php

/*
Copyright (c) 2017, Marius Burkard, projektfarm Gmbh
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

class letsencrypt {

	private $renew_config_path = '/etc/letsencrypt/renewal';
	private $certbot_use_certcommand = false;

	public function get_acme_script() {
		$acme = explode("\n", shell_exec('which acme.sh /usr/local/ispconfig/server/scripts/acme.sh /root/.acme.sh/acme.sh 2> /dev/null') ?? '');
		$acme = reset($acme);
		if(is_executable($acme)) {
			return $acme;
		} else {
			return false;
		}
	}

	public function get_acme_command($domains, $key_file, $bundle_file, $cert_file, $server_type = 'apache') {
		global $app, $conf;

		$letsencrypt = $this->get_acme_script();

		$cmd = '';
		// generate cli format
		foreach($domains as $domain) {
			$cmd .= (string) " -d " . $domain;
		}

		if($cmd == '') {
			return false;
		}

		if($server_type != 'apache' || version_compare($app->system->getapacheversion(true), '2.4.8', '>=')) {
			$cert_arg = '--fullchain-file ' . escapeshellarg($cert_file);
		} else {
			$cert_arg = '--fullchain-file ' . escapeshellarg($bundle_file) . ' --cert-file ' . escapeshellarg($cert_file);
		}

		$cmd = 'R=0 ; C=0 ; ' . $letsencrypt . ' --issue ' . $cmd . ' -w /usr/local/ispconfig/interface/acme --always-force-new-domain-key --keylength 4096; R=$? ; if [ $R -eq 0 -o $R -eq 2 ] ; then ' . $letsencrypt . ' --install-cert ' . $cmd . ' --key-file ' . escapeshellarg($key_file) . ' ' . $cert_arg . ' --reloadcmd ' . escapeshellarg($this->get_reload_command()) . ' --log ' . escapeshellarg($conf['ispconfig_log_dir'].'/acme.log') . '; C=$? ; fi ; if [ $C -eq 0 ] ; then exit $R ; else exit $C  ; fi';

		return $cmd;
	}

	public function get_certbot_script() {
		$which_certbot = shell_exec('which certbot /root/.local/share/letsencrypt/bin/letsencrypt /opt/eff.org/certbot/venv/bin/certbot letsencrypt');
		$letsencrypt = explode("\n", $which_certbot ? $which_certbot : '');
		$letsencrypt = reset($letsencrypt);
		if(is_executable($letsencrypt)) {
			return $letsencrypt;
		} else {
			return false;
		}
	}

	private function install_acme() {
		$install_cmd = 'wget -O -  https://get.acme.sh | sh';
		$ret = null;
		$val = 0;
		exec($install_cmd . ' 2>&1', $ret, $val);

		return ($val == 0 ? true : false);
	}

	private function get_reload_command() {
		global $app, $conf;

		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');

		$daemon = '';
		switch ($web_config['server_type']) {
			case 'nginx':
				$daemon = $web_config['server_type'];
				break;
			default:
				if(is_file($conf['init_scripts'] . '/' . 'httpd24-httpd') || is_dir('/opt/rh/httpd24/root/etc/httpd')) {
					$daemon = 'httpd24-httpd';
				} elseif(is_file($conf['init_scripts'] . '/' . 'httpd') || is_dir('/etc/httpd')) {
					$daemon = 'httpd';
				} else {
					$daemon = 'apache2';
				}
		}

		$cmd = $app->system->getinitcommand($daemon, 'force-reload');
		return $cmd;
	}

	private function get_certbot_version($certbot_script) {
		$matches = array();
		$ret = null;
		$val = 0;
		$letsencrypt_version = exec($certbot_script . ' --version  2>&1', $ret, $val);
		if(preg_match('/^(\S+|\w+)\s+(\d+(\.\d+)+)$/', $letsencrypt_version, $matches)) {
			$letsencrypt_version = $matches[2];
		}
		return $letsencrypt_version;
	}

	public function get_certbot_command($domains) {
		global $app;

		$letsencrypt = $this->get_certbot_script();

		$cmd = '';
		// generate cli format
		foreach($domains as $domain) {
			$cmd .= (string) " --domains " . $domain;
		}

		if($cmd == '') {
			return false;
		}

		$primary_domain = $domains[0];

		$letsencrypt_version = $this->get_certbot_version($letsencrypt);
		if (version_compare($letsencrypt_version, '0.22', '>=')) {
			$acme_version = 'https://acme-v02.api.letsencrypt.org/directory';
		} else {
			$acme_version = 'https://acme-v01.api.letsencrypt.org/directory';
		}
		if (version_compare($letsencrypt_version, '0.30', '>=')) {
			$app->log("LE version is " . $letsencrypt_version . ", so using certificates command and --cert-name instead of --expand", LOGLEVEL_DEBUG);
			$this->certbot_use_certcommand = true;
			$webroot_map = array();
			for($i = 0; $i < count($domains); $i++) {
				$webroot_map[$domains[$i]] = '/usr/local/ispconfig/interface/acme';
			}
			$webroot_args = "--webroot-map " . escapeshellarg(str_replace(array("\r", "\n"), '', json_encode($webroot_map)));
			// --cert-name might be working with earlier versions of certbot, but there is no exact version documented
			// So for safety reasons we add it to the 0.30 version check as it is documented to work as expected in this version
			$cert_selection_command = "--cert-name $primary_domain";
		} else {
			$webroot_args = "$cmd --webroot-path /usr/local/ispconfig/interface/acme";
			$cert_selection_command = "--expand";
		}

		$cmd = $letsencrypt . " certonly -n --text --agree-tos $cert_selection_command --authenticator webroot --server $acme_version --rsa-key-size 4096 --email webmaster@$primary_domain $webroot_args";

		return $cmd;
	}

	public function get_letsencrypt_certificate_paths($domains = array()) {
		global $app;

		if($this->get_acme_script()) {
			return false;
		}

		if(empty($domains)) return false;

		$all_certificates = $this->get_certificate_list();
		if (empty($all_certificates)) {
			return false;
		}

		$main_domain = reset($domains);
		sort($domains);
		$min_diff = false;

		foreach ($all_certificates as $certificate) {
			$certificate['has_main_domain'] = in_array($main_domain, $certificate['domains']);
			$sorted_cert_domains = $certificate['domains'];
			sort($sorted_cert_domains);
			if(count(array_intersect($domains, $sorted_cert_domains)) < 1) {
				$certificate['diff'] = false;
			} else {
				// give higher diff value to missing domains than to those that are too much in there
				$certificate['diff'] = (count(array_diff($domains, $sorted_cert_domains)) * 1.5) + count(array_diff($sorted_cert_domains, $domains));
			}
			if($min_diff === false || ($certificate['diff'] !== false && $certificate['diff'] < $min_diff)) $min_diff = $certificate['diff'];
		}

		if($min_diff === false) return false;

		$cert_paths = false;
		$used_id = false;
		foreach ($all_certificates as $certificate) {
			if($certificate['diff'] === $min_diff) {
				$used_id = $certificate['id'];
				$cert_paths = $certificate['cert_paths'];
				if($certificate['has_main_domain']) break;
			}
		}

		$app->log("Let's Encrypt Cert config path is: " . ($used_id ? $used_id : "not found") . ".", LOGLEVEL_DEBUG);

		return $cert_paths;
	}

	private function get_ssl_domain($data) {
		global $app;

		$domain = $data['new']['ssl_domain'];
		if(!$domain) {
			$domain = $data['new']['domain'];
		}

		if($data['new']['ssl'] == 'y' && $data['new']['ssl_letsencrypt'] == 'y') {
			$domain = $data['new']['domain'];
			if(substr($domain, 0, 2) === '*.') {
				// wildcard domain not yet supported by letsencrypt!
				$app->log('Wildcard domains not yet supported by letsencrypt, so changing ' . $domain . ' to ' . substr($domain, 2), LOGLEVEL_WARN);
				$domain = substr($domain, 2);
			}
		}

		return $domain;
	}

	public function get_website_certificate_paths($data) {
		$ssl_dir = $data['new']['document_root'].'/ssl';
		$domain = $this->get_ssl_domain($data);

		$cert_paths = array(
			'domain' => $domain,
			'key' => $ssl_dir.'/'.$domain.'.key',
			'key2' => $ssl_dir.'/'.$domain.'.key.org',
			'csr' => $ssl_dir.'/'.$domain.'.csr',
			'crt' => $ssl_dir.'/'.$domain.'.crt',
			'bundle' => $ssl_dir.'/'.$domain.'.bundle'
		);

		if($data['new']['ssl'] == 'y' && $data['new']['ssl_letsencrypt'] == 'y') {
			$cert_paths = array(
				'domain' => $domain,
				'key' => $ssl_dir.'/'.$domain.'-le.key',
				'key2' => $ssl_dir.'/'.$domain.'-le.key.org',
				'csr' => '', # Not used for LE.
				'crt' => $ssl_dir.'/'.$domain.'-le.crt',
				'bundle' => $ssl_dir.'/'.$domain.'-le.bundle'
			);
		}

		return $cert_paths;
	}

	public function request_certificates($data, $server_type = 'apache') {
		global $app, $conf;

		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');
		$server_config = $app->getconf->get_server_config($conf['server_id'], 'server');

		$use_acme = false;
		if($this->get_acme_script()) {
			$use_acme = true;
		} elseif(!$this->get_certbot_script()) {
			$app->log("Unable to find Let's Encrypt client, installing acme.sh.", LOGLEVEL_DEBUG);
			// acme and le missing
			$this->install_acme();
			if($this->get_acme_script()) {
				$use_acme = true;
			} else {
				$app->log("Unable to install acme.sh.  Cannot proceed, no Let's Encrypt client found.", LOGLEVEL_WARN);
				return false;
			}
		}

		$tmp = $app->letsencrypt->get_website_certificate_paths($data);
		$domain = $tmp['domain'];
		$key_file = $tmp['key'];
		$crt_file = $tmp['crt'];
		$bundle_file = $tmp['bundle'];

		// default values
		$temp_domains = array($domain);
		$cli_domain_arg = '';
		$subdomains = null;
		$aliasdomains = null;

		//* be sure to have good domain
		if(substr($domain,0,4) != 'www.' && ($data['new']['subdomain'] == "www" || $data['new']['subdomain'] == "*")) {
			$temp_domains[] = "www." . $domain;
		}

		//* then, add subdomain if we have
		$subdomains = $app->db->queryAllRecords('SELECT domain FROM web_domain WHERE parent_domain_id = '.intval($data['new']['domain_id'])." AND active = 'y' AND type = 'subdomain' AND ssl_letsencrypt_exclude != 'y'");
		if(is_array($subdomains)) {
			foreach($subdomains as $subdomain) {
				$temp_domains[] = $subdomain['domain'];
			}
		}

		//* then, add alias domain if we have
		$aliasdomains = $app->db->queryAllRecords('SELECT domain,subdomain FROM web_domain WHERE parent_domain_id = '.intval($data['new']['domain_id'])." AND active = 'y' AND type = 'alias' AND ssl_letsencrypt_exclude != 'y'");
		if(is_array($aliasdomains)) {
			foreach($aliasdomains as $aliasdomain) {
				$temp_domains[] = $aliasdomain['domain'];
				if(isset($aliasdomain['subdomain']) && substr($aliasdomain['domain'],0,4) != 'www.' && ($aliasdomain['subdomain'] == "www" OR $aliasdomain['subdomain'] == "*")) {
					$temp_domains[] = "www." . $aliasdomain['domain'];
				}
			}
		}

		// prevent duplicate
		$temp_domains = array_unique($temp_domains);

		// check if domains are reachable to avoid letsencrypt verification errors
		$le_rnd_file = uniqid('le-', true) . '.txt';
		$le_rnd_hash = md5(uniqid('le-', true));
		if(!is_dir('/usr/local/ispconfig/interface/acme/.well-known/acme-challenge/')) {
			$app->system->mkdir('/usr/local/ispconfig/interface/acme/.well-known/acme-challenge/', false, 0755, true);
		}
		file_put_contents('/usr/local/ispconfig/interface/acme/.well-known/acme-challenge/' . $le_rnd_file, $le_rnd_hash);

		$le_domains = array();
		foreach($temp_domains as $temp_domain) {
			if((isset($web_config['skip_le_check']) && $web_config['skip_le_check'] == 'y') || (isset($server_config['migration_mode']) && $server_config['migration_mode'] == 'y')) {
				$le_domains[] = $temp_domain;
			} else {
				$le_hash_check = trim(@file_get_contents('http://' . $temp_domain . '/.well-known/acme-challenge/' . $le_rnd_file));
				if($le_hash_check == $le_rnd_hash) {
					$le_domains[] = $temp_domain;
					$app->log("Verified domain " . $temp_domain . " should be reachable for letsencrypt.", LOGLEVEL_DEBUG);
				} else {
					$app->log("Could not verify domain " . $temp_domain . ", so excluding it from letsencrypt request.", LOGLEVEL_WARN);
				}
			}
		}
		$temp_domains = $le_domains;
		unset($le_domains);
		@unlink('/usr/local/ispconfig/interface/acme/.well-known/acme-challenge/' . $le_rnd_file);

		$le_domain_count = count($temp_domains);
		if($le_domain_count > 100) {
			$temp_domains = array_slice($temp_domains, 0, 100);
			$app->log("There were " . $le_domain_count . " domains in the domain list. LE only supports 100, so we strip the rest.", LOGLEVEL_WARN);
		}

		if ($le_domain_count == 0) {
			return false;
		}

		// unset useless data
		unset($subdomains);
		unset($aliasdomains);

		$this->certbot_use_certcommand = false;
		$letsencrypt_cmd = '';
		$allow_return_codes = null;
		$old_umask = umask(0022);  # work around acme.sh permission bug, see #6015
		if($use_acme) {
			$letsencrypt_cmd = $this->get_acme_command($temp_domains, $key_file, $bundle_file, $crt_file, $server_type);
			$allow_return_codes = array(2);
			// Cleanup ssl cert symlinks, if exists
			if(@is_link($key_file)) unlink($key_file);
			if(@is_link($bundle_file)) unlink($bundle_file);
			if(@is_link($crt_file)) unlink($crt_file);
		} else {
			$letsencrypt_cmd = $this->get_certbot_command($temp_domains);
			umask($old_umask);
		}

		$success = false;
		if($letsencrypt_cmd) {
			if(!isset($server_config['migration_mode']) || $server_config['migration_mode'] != 'y') {
				$app->log("Create Let's Encrypt SSL Cert for: $domain", LOGLEVEL_DEBUG);
				$app->log("Let's Encrypt SSL Cert domains: $cli_domain_arg", LOGLEVEL_DEBUG);

				$success = $app->system->_exec($letsencrypt_cmd, $allow_return_codes);
			} else {
				$app->log("Migration mode active, skipping Let's Encrypt SSL Cert creation for: $domain", LOGLEVEL_DEBUG);
				$success = true;
			}
		}

		if($use_acme === true) {
			umask($old_umask);
			if(!$success) {
				$app->log('Let\'s Encrypt SSL Cert for: ' . $domain . ' could not be issued.', LOGLEVEL_WARN);
				$app->log($letsencrypt_cmd, LOGLEVEL_WARN);
				return false;
			} else {
				return true;
			}
		}

		$le_files = array();
		if($this->certbot_use_certcommand === true && $letsencrypt_cmd) {
			$cli_domain_arg = '';
			// generate cli format
			foreach($temp_domains as $temp_domain) {
				$cli_domain_arg .= (string) " --domains " . $temp_domain;
			}


			$letsencrypt_cmd = $this->get_certbot_script() . " certificates " . $cli_domain_arg;
			$output = explode("\n", shell_exec($letsencrypt_cmd . " 2>/dev/null | grep -v '^\$'") ?? '');
			$le_path = '';
			$skip_to_next = true;
			$matches = null;
			foreach($output as $outline) {
				$outline = trim($outline);
				$app->log("LE CERT OUTPUT: " . $outline, LOGLEVEL_DEBUG);

				if($skip_to_next === true && !preg_match('/^\s*Certificate Name/', $outline)) {
					continue;
				}
				$skip_to_next = false;

				if(preg_match('/^\s*Expiry.*?VALID:\s+\D/', $outline)) {
					$app->log("Found LE path is expired or invalid: " . $matches[1], LOGLEVEL_DEBUG);
					$skip_to_next = true;
					continue;
				}

				if(preg_match('/^\s*Certificate Path:\s*(\/.*?)\s*$/', $outline, $matches)) {
					$app->log("Found LE path: " . $matches[1], LOGLEVEL_DEBUG);
					$le_path = dirname($matches[1]);
					if(is_dir($le_path)) {
						break;
					} else {
						$le_path = false;
					}
				}
			}

			if($le_path) {
				$le_files = array(
					'privkey' => $le_path . '/privkey.pem',
					'chain' => $le_path . '/chain.pem',
					'cert' => $le_path . '/cert.pem',
					'fullchain' => $le_path . '/fullchain.pem'
				);
			}
		}
		if(empty($le_files)) {
			$le_files = $this->get_letsencrypt_certificate_paths($temp_domains);
		}
		unset($temp_domains);

		if($server_type != 'apache' || version_compare($app->system->getapacheversion(true), '2.4.8', '>=')) {
			$crt_tmp_file = $le_files['fullchain'];
		} else {
			$crt_tmp_file = $le_files['cert'];
		}

		$key_tmp_file = $le_files['privkey'];
		$bundle_tmp_file = $le_files['chain'];

		if(!$success) {
			// error issuing cert
			$app->log('Let\'s Encrypt SSL Cert for: ' . $domain . ' could not be issued.', LOGLEVEL_WARN);
			$app->log($letsencrypt_cmd, LOGLEVEL_WARN);

			// if cert already exists, dont remove it. Ex. expired/misstyped/noDnsYet alias domain, api down...
			if(!file_exists($crt_tmp_file)) {
				return false;
			}
		}

		//* check is been correctly created
		if(file_exists($crt_tmp_file)) {
			$app->log("Let's Encrypt Cert file: $crt_tmp_file exists.", LOGLEVEL_DEBUG);
			$date = date("YmdHis");

			//* TODO: check if is a symlink, if target same keep it, either remove it
			if(is_file($key_file)) {
				$app->system->copy($key_file, $key_file.'.old.'.$date);
				$app->system->chmod($key_file.'.old.'.$date, 0400);
				$app->system->unlink($key_file);
			}

			if(@is_link($key_file)) $app->system->unlink($key_file);
			if(@file_exists($key_tmp_file)) $app->system->exec_safe("ln -s ? ?", $key_tmp_file, $key_file);

			if(is_file($crt_file)) {
				$app->system->copy($crt_file, $crt_file.'.old.'.$date);
				$app->system->chmod($crt_file.'.old.'.$date, 0400);
				$app->system->unlink($crt_file);
			}

			if(@is_link($crt_file)) $app->system->unlink($crt_file);
			if(@file_exists($crt_tmp_file))$app->system->exec_safe("ln -s ? ?", $crt_tmp_file, $crt_file);

			if(is_file($bundle_file)) {
				$app->system->copy($bundle_file, $bundle_file.'.old.'.$date);
				$app->system->chmod($bundle_file.'.old.'.$date, 0400);
				$app->system->unlink($bundle_file);
			}

			if(@is_link($bundle_file)) $app->system->unlink($bundle_file);
			if(@file_exists($bundle_tmp_file)) $app->system->exec_safe("ln -s ? ?", $bundle_tmp_file, $bundle_file);

			return true;
		} else {
			$app->log("Let's Encrypt Cert file: $crt_tmp_file does not exist.", LOGLEVEL_DEBUG);
			return false;
		}
	}

	/**
	 * Gets a list of all installed certificates on this server.
	 *
	 * @return array
	 */
	public function get_certificate_list() {
		global $app;

		$use_acme = false;
		$shell_script = $this->get_acme_script();
		if ($shell_script) {
			$use_acme = true;
		} else {
			$shell_script = $this->get_certbot_script();
		}
		if (!$shell_script) {
			$app->log("get_certificate_list: did not find acme.sh nor certbot", LOGLEVEL_ERROR);
			return [];
		}

		$certs = [];
		if ($use_acme) {
			$info = $app->system->system_safe("$shell_script --info");
			// try to auto-upgrade acme.sh when --info command is not there
			if ($app->system->last_exec_retcode() != 0) {
				$app->system->system_safe("$shell_script --upgrade");
				$info = $app->system->system_safe("$shell_script --info");
			}
			if ($app->system->last_exec_retcode() != 0) {
				$app->log("get_certificate_list: acme.sh --info failed", LOGLEVEL_ERROR);
				return [];
			}
			$info = $this->parse_env_file($info);
			$cert_dir = $info['CERT_HOME'] ?? $info['LE_CONFIG_HOME'];
			if (!is_dir($cert_dir)) {
				$app->log("get_certificate_list: could not find certificate home $cert_dir", LOGLEVEL_ERROR);
				return [];
			}
			$dir = opendir($cert_dir);
			if(!$dir) {
				$app->log("get_certificate_list: could not open certificate home $cert_dir", LOGLEVEL_ERROR);
				return [];
			}
			while($path = readdir($dir)) {
				// valid conf dirs have a . in them
				if($path === '.' || $path === '..' || strpos($path, '.') === false) {
					continue;
				}
				$full_path = $cert_dir.'/'.$path;
				if (!is_dir($full_path)) {
					continue;
				}
				$domain = $path;
				if (preg_match('/_ecc$/', $path)) {
					$domain = substr($path, 0, -4);
				}
				if (!is_file("$full_path/$domain.conf")) {
					continue;
				}
				$certs[] = [
					'type' => 'acme.sh',
					'id' => $path,
					'conf' => $full_path,
					'cert_paths' => [
						'cert' => "$full_path/$domain.cer",
						'privkey' => "$full_path/$domain.key",
						'fullchain' => "$full_path/fullchain.cer",
					]
				];
			}
		} else {
			$letsencrypt_version = $this->get_certbot_version($shell_script);
			if (version_compare($letsencrypt_version, '0.10.0', '<')) {
				$app->log("get_certificate_list: certbot version $letsencrypt_version not supported", LOGLEVEL_ERROR);
				return [];
			}
			if(!is_dir($this->renew_config_path)) {
				$app->log("get_certificate_list: certbot renew dir not found: ".$this->renew_config_path, LOGLEVEL_ERROR);
				return [];
			}
			$dir = opendir($this->renew_config_path);
			if(!$dir) {
				$app->log("get_certificate_list: could not open certbot renew dir", LOGLEVEL_ERROR);
				return [];
			}
			while($file = readdir($dir)) {
				if($file === '.' || $file === '..' || substr($file, -5) !== '.conf')  continue;
				$file_path = $this->renew_config_path . '/' . $file;
				if(!is_file($file_path) || !is_readable($file_path)) continue;

				$fp = fopen($file_path, 'r');
				if(!$fp) continue;
				$certificate = [
					'type' => 'certbot',
					'id' => substr($file, 0, -5),
					'conf' => $file_path,
					'cert_paths' => [
						'cert' => '',
						'privkey' => '',
						'chain' => '',
						'fullchain' => ''
					]
				];
				while(!feof($fp) && $line = fgets($fp)) {
					$line = trim($line);
					if($line === '') continue;
					if($line == '[[webroot_map]]') break;
					$tmp = explode('=', $line, 2);
					if(count($tmp) != 2) continue;
					$key = trim($tmp[0]);
					if($key == 'cert' || $key == 'privkey' || $key == 'chain' || $key == 'fullchain') {
						$certificate['cert_paths'][$key] = trim($tmp[1]);
					}
				}
				fclose($fp);
				$certs[] = $certificate;
			}
			closedir($dir);
		}

		$certificates = [];
		foreach ($certs as $certificate) {
			if (!empty($certificate['cert_paths']['cert']) && !empty($certificate['cert_paths']['priv']) && is_file($certificate['cert_paths']['cert']) && is_file($certificate['cert_paths']['priv'])) {
				$info = $this->extract_x509($certificate['cert_paths']['cert']);
				if ($info) {
					$certificates[] = array_merge($certificate, $info);
				}
			}
		}
		return $certificates;
	}

	/**
	 * @param array $certificate the certificate (from get_certificate_list())
	 *
	 * @return bool whether the certificate could be removed
	 */
	public function remove_certificate($certificate) {
		global $app;

		if ($certificate['type'] == 'certbot') {
			$certbot_script = $this->get_certbot_script();
			if (!$certbot_script) {
				$app->log("remove_certificate: certbot not found, cannot delete ". $certificate['id'], LOGLEVEL_WARN);
				return false;
			}
			$version = $this->get_certbot_version($certbot_script);
			if (version_compare($version, '0.10.0', '<')) {
				$app->log("remove_certificate: certbot is very old. Please update for proper certificate deletion.", LOGLEVEL_WARN);
			} else {
				$app->system->safe_exec("$certbot_script delete --cert-name ?", $certificate['id']);
				if ($app->system->last_exec_retcode() != 0) {
					$app->log("remove_certificate: certbot delete --cert-name ". $certificate['id']. " failed.", LOGLEVEL_WARN);
				}
			}
			if (is_file($certificate['conf'])) {
				@rename($certificate['conf'], $certificate['conf'].'.removed');
				$app->log("remove_certificate: manually move renew conf ". $certificate['conf']. " out of the way.", LOGLEVEL_DEBUG);
			}
		} else {
			if (is_dir($certificate['conf'])) {
				if (!$app->system->rmdir($certificate['conf'], false)) {
					$app->log("remove_certificate: could not delete config folder ". $certificate['conf'], LOGLEVEL_WARN);
					return false;
				}
			}
		}
		return true;
	}

	private function is_domain_name_or_wildcard($input) {
		$input = filter_var($input, FILTER_VALIDATE_DOMAIN);
		if (!$input) {
			return false;
		}
		// $input can still be something like "some. invalid . domain % name", so we check with a simple regex that no unusual things are in domain name
		return preg_match("/^(\*\.)?[\w\p{L}0-9._-]+$/u", $input);
	}

	public function extract_x509($cert_file) {
		global $app;
		if (!function_exists('openssl_x509_parse')) {
			$app->log("extract_x509: openssl extension missing", LOGLEVEL_ERROR);
			return false;
		}
		$info = openssl_x509_parse(file_get_contents($cert_file), true);
		if (!$info) {
			$app->log("extract_x509: $cert_file could not be parsed", LOGLEVEL_ERROR);
			return false;
		}
		if (empty($info['subject']['CN']) || !$this->is_domain_name_or_wildcard($info['subject']['CN'])) {
			return false;
		}
		$domains = [$info['subject']['CN']];
		if (!empty($info['extensions']) && !empty($info['extensions']['subjectAltName'])) {
			$domains = array_filter(array_merge($domains, array_map(function($i) {
				$parts = explode(':', $i, 2);
				if (count($parts) < 2) {
					return false;
				}
				$maybe_domain = trim($parts[1]);
				if (!$this->is_domain_name_or_wildcard($maybe_domain) && !filter_var($maybe_domain, FILTER_VALIDATE_IP)) {
					return false;
				}
				return $maybe_domain;
			}, explode(',', $info['extensions']['subjectAltName']))));
			$domains = array_values(array_unique($domains));
		}
		if (empty($domains)) {
			return false;
		}
		$valid_from = new DateTime('@' .  $info['validFrom_time_t']);
		$valid_to = new DateTime('@' .  $info['validTo_time_t']);
		$now = new DateTime();
		return [
			'serialNumber' => $info['serialNumber'],
			'signatureType' => $info['signatureTypeLN'] ?? '?',
			'subject' => $info['subject'],
			'issuer' => $info['issuer'],
			'domains' => $domains,
			'is_valid' => $valid_from <= $now && $now <= $valid_to, // TODO: add revokation check (OCSP and/or CRL)
			'valid_from' => $valid_from,
			'valid_to' => $valid_to,
		];
	}

	private function parse_env_file($lines) {
		$variables = [];
		foreach ($lines as $line) {
			$line = trim($line);
			// does only handle comment-only lines.
			// lines like `KEY=Value # inline-comment` are not supported (and normally not used by acme.sh)
			if (!$line || substr($line, 0, 1) == '#') {
				continue;
			}
			$parts = explode('=', $line, 2);
			if (count($parts) < 2) {
				continue;
			}
			$key = trim($parts[0]);
			$value = trim($parts[1]);
			if (preg_match('/^"(.*)"$/', $value, $matches)) {
				$value = $matches[1];
			} elseif (preg_match("/^'(.*)'$/", $value, $matches)) {
				$value = $matches[1];
			}
			$variables[$key] = $value;
		}
		return $variables;
	}
}
