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

	public function get_acme_script() {
		$acme = explode("\n", shell_exec('which acme.sh /usr/local/ispconfig/server/scripts/acme.sh /root/.acme.sh/acme.sh 2> /dev/null') ?: '');
		$acme = reset($acme);
		if(is_executable($acme)) {
			return $acme;
		} else {
			return false;
		}
	}

	public function get_acme_command($domains, $key_file, $bundle_file, $cert_file, $server_type = 'apache', &$cert_type = 'RSA') {
		global $app, $conf;

		if(empty($domains)) {
			return false;
		}

		$acme_sh = '';
		$use_acme = $this->use_acme($acme_sh);
		if(!$use_acme || !$acme_sh) {
			return false;
		}
		$version = $this->get_acme_version($acme_sh);
		if(empty($version)) {
			return false;
		}

		$acme_sh .= ' --log ' . escapeshellarg($conf['ispconfig_log_dir'] . '/acme.log');

		$domain_args = ' -d ' . join(' -d ', array_map('escapeshellarg', $domains));
		$files_to_install = ' --key-file ' . escapeshellarg($key_file);
		if($server_type != 'apache' || version_compare($app->system->getapacheversion(true), '2.4.8', '>=')) {
			$files_to_install .= ' --fullchain-file ' . escapeshellarg($cert_file);
		} else {
			$files_to_install .= ' --fullchain-file ' . escapeshellarg($bundle_file) . ' --cert-file ' . escapeshellarg($cert_file);
		}

		// the minimum acme.sh version for ECDSA might be lower, but this version should work OK
		if($cert_type == 'ECDSA' && version_compare($version, '2.6.4', '>=')) {
			$app->log('acme.sh version is ' . $version . ', so using --keylength ec-256 instead of --keylength 4096', LOGLEVEL_DEBUG);
			$certificate_type_arg = ' --keylength ec-256';
			$conf_selection_arg = ' --ecc';
		} else {
			$certificate_type_arg = ' --keylength 4096';
			$conf_selection_arg = '';
			if($cert_type != 'RSA') {
				$cert_type = 'RSA';
				$app->log($cert_type . ' was requested by we use RSA because acme.sh version is ' . $version, LOGLEVEL_DEBUG);
			}
		}

		$commands = [
			'R=0 ; C=0',
			$acme_sh . ' --issue ' . $domain_args . ' -w /usr/local/ispconfig/interface/acme --always-force-new-domain-key ' . $conf_selection_arg . $certificate_type_arg,
			'R=$?',
			'if [ $R -eq 0 ] || [ $R -eq 2 ]; then :',
			'  ' . $acme_sh . ' --install-cert ' . $domain_args . $conf_selection_arg . $files_to_install . ' --reloadcmd ' . escapeshellarg($this->get_reload_command($server_type)),
			'  C=$?',
			'fi',
			'if [ $C -eq 0 ]',
			'  then exit $R',
			'  else exit $C',
			'fi'
		];

		return join(' ; ', $commands);
	}

	private function install_acme() {
		$install_cmd = 'wget -O -  https://get.acme.sh | sh';
		$ret = null;
		$val = 0;
		exec($install_cmd . ' 2>&1', $ret, $val);

		return $val == 0;
	}

	private function get_acme_version($acme_script) {
		$matches = array();
		$output = shell_exec($acme_script . ' --version  2>&1') ?: '';
		if(preg_match('/^v(\d+(\.\d+)+)$/m', $output, $matches)) {
			return $matches[1];
		}
		return false;
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

	public function get_certbot_command($domains, &$cert_type = 'RSA') {
		global $app;

		if(empty($domains)) {
			return false;
		}

		$letsencrypt = $this->get_certbot_script();

		$primary_domain = $domains[0];

		$letsencrypt_version = $this->get_certbot_version($letsencrypt);

		if(version_compare($letsencrypt_version, '0.22', '>=')) {
			$acme_version = 'https://acme-v02.api.letsencrypt.org/directory';
		} else {
			$acme_version = 'https://acme-v01.api.letsencrypt.org/directory';
		}

		if($cert_type == 'ECDSA' && version_compare($letsencrypt_version, '2.0', '>=')) {
			$app->log('LE version is ' . $letsencrypt_version . ', so using --elliptic-curve secp256r1 instead of --rsa-key-size 4096', LOGLEVEL_DEBUG);
			$certificate_type_arg = "--elliptic-curve secp256r1";
			$name_suffix = '_ecc';
		} else {
			$certificate_type_arg = "--rsa-key-size 4096";
			$name_suffix = '';
			if($cert_type != 'RSA') {
				$cert_type = 'RSA';
				$app->log($cert_type . ' was requested by we use RSA because certbot version is ' . $letsencrypt_version, LOGLEVEL_DEBUG);
			}
		}

		if(version_compare($letsencrypt_version, '0.30', '>=')) {
			$app->log('LE version is ' . $letsencrypt_version . ', so using --cert-name instead of --expand', LOGLEVEL_DEBUG);
			$webroot_map = [];
			foreach($domains as $domain) {
				$webroot_map[$domain] = '/usr/local/ispconfig/interface/acme';
			}
			$webroot_args = "--webroot-map " . escapeshellarg(str_replace(array("\r", "\n"), '', json_encode($webroot_map)));
			// --cert-name might be working with earlier versions of certbot, but there is no exact version documented
			// So for safety reasons we add it to the 0.30 version check as it is documented to work as expected in this version
			$cert_selection_command = "--cert-name $primary_domain$name_suffix";
		} else {
			$cmd = ' --domains ' . join(' --domains ', array_map('escapeshellarg', $domains));
			$webroot_args = "$cmd --webroot-path /usr/local/ispconfig/interface/acme";
			$cert_selection_command = "--expand";
		}

		return $letsencrypt . " certonly -n --text --agree-tos $cert_selection_command --authenticator webroot --server $acme_version $certificate_type_arg --email webmaster@$primary_domain $webroot_args";
	}

	private function get_reload_command($server_type) {
		global $app, $conf;

		$daemon = '';
		switch($server_type) {
			case 'nginx':
				$daemon = 'nginx';
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

	private function use_acme(&$script = null) {
		global $app;

		$script = $this->get_acme_script();
		if($script) {
			return true;
		}
		$script = $this->get_certbot_script();
		if(!$script) {
			$app->log("Unable to find Let's Encrypt client, installing acme.sh.", LOGLEVEL_DEBUG);
			// acme and le missing
			$this->install_acme();
			$script = $this->get_acme_script();
			if($script) {
				return true;
			} else {
				$app->log("Unable to install acme.sh.  Cannot proceed, no Let's Encrypt client found.", LOGLEVEL_WARN);
				return null;
			}
		}
		return false;
	}

	public function get_letsencrypt_certificate_paths($domains = [], $cert_type = 'RSA') {
		global $app;

		if(empty($domains)) return false;

		$all_certificates = $this->get_certificate_list();
		if(empty($all_certificates)) {
			return false;
		}

		$primary_domain = reset($domains);
		$sorted_domains = $domains;
		sort($sorted_domains);
		$min_diff = false;
		$possible_certificates = [];
		foreach($all_certificates as $certificate) {
			if($certificate['signature_type'] != $cert_type) {
				continue;
			}
			$sorted_cert_domains = $certificate['domains'];
			sort($sorted_cert_domains);
			if(count(array_intersect($sorted_domains, $sorted_cert_domains)) < 1) {
				continue;
			} else {
				// if the domains are exactly the same (including order) consider this better than a certificate that has all domains but in a different order
				if($domains === $certificate['domains']) {
					$certificate['diff'] = -1;
				} else {
					// give higher diff value to missing domains than to those that are too much in there
					$certificate['diff'] = (count(array_diff($sorted_domains, $sorted_cert_domains)) * 1.5) + count(array_diff($sorted_cert_domains, $sorted_domains));
				}
				$certificate['has_main_domain'] = in_array($primary_domain, $certificate['domains']);
			}
			if($min_diff === false || ($certificate['diff'] < $min_diff)) $min_diff = $certificate['diff'];
			$possible_certificates[] = $certificate;
		}

		if($min_diff === false) return false;

		$cert_paths = false;
		$used_id = false;
		foreach($possible_certificates as $certificate) {
			if($certificate['diff'] === $min_diff) {
				$used_id = $certificate['id'];
				$cert_paths = $certificate['cert_paths'];
				if($certificate['has_main_domain']) break;
			}
		}

		$app->log("Let's Encrypt Cert config path is: " . ($used_id ?: "not found") . ".", LOGLEVEL_DEBUG);

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
		$ssl_dir = $data['new']['document_root'] . '/ssl';
		$domain = $this->get_ssl_domain($data);

		$cert_paths = array(
			'domain' => $domain,
			'key' => $ssl_dir . '/' . $domain . '.key',
			'key2' => $ssl_dir . '/' . $domain . '.key.org',
			'csr' => $ssl_dir . '/' . $domain . '.csr',
			'crt' => $ssl_dir . '/' . $domain . '.crt',
			'bundle' => $ssl_dir . '/' . $domain . '.bundle'
		);

		if($data['new']['ssl'] == 'y' && $data['new']['ssl_letsencrypt'] == 'y') {
			$cert_paths = array(
				'domain' => $domain,
				'key' => $ssl_dir . '/' . $domain . '-le.key',
				'key2' => $ssl_dir . '/' . $domain . '-le.key.org',
				'csr' => '', # Not used for LE.
				'crt' => $ssl_dir . '/' . $domain . '-le.crt',
				'bundle' => $ssl_dir . '/' . $domain . '-le.bundle'
			);
		}

		return $cert_paths;
	}


	private function assemble_domains_to_request($data, $main_domain, $do_check) {
		global $app, $conf;

		$certificate_domains = array($main_domain);

		//* be sure to have good domain
		if(substr($main_domain, 0, 4) != 'www.' && ($data['new']['subdomain'] == "www" || $data['new']['subdomain'] == "*")) {
			$certificate_domains[] = "www." . $main_domain;
		}

		//* then, add subdomain if we have
		$subdomains = $app->db->queryAllRecords("SELECT domain FROM web_domain WHERE parent_domain_id = ? AND active = 'y' AND type = 'subdomain' AND ssl_letsencrypt_exclude != 'y'", intval($data['new']['domain_id']));
		if(is_array($subdomains)) {
			foreach($subdomains as $subdomain) {
				$certificate_domains[] = $subdomain['domain'];
			}
		}

		//* then, add alias domain if we have
		$alias_domains = $app->db->queryAllRecords("SELECT domain,subdomain FROM web_domain WHERE parent_domain_id = ? AND active = 'y' AND type = 'alias' AND ssl_letsencrypt_exclude != 'y'", intval($data['new']['domain_id']));
		if(is_array($alias_domains)) {
			foreach($alias_domains as $alias_domain) {
				$certificate_domains[] = $alias_domain['domain'];
				if(isset($alias_domain['subdomain']) && substr($alias_domain['domain'], 0, 4) != 'www.' && ($alias_domain['subdomain'] == "www" or $alias_domain['subdomain'] == "*")) {
					$certificate_domains[] = "www." . $alias_domain['domain'];
				}
			}
		}

		// prevent duplicate
		$certificate_domains = array_values(array_unique($certificate_domains));

		// check if domains are reachable to avoid let's encrypt verification errors
		if($do_check) {
			$le_rnd_file = uniqid('le-', true) . '.txt';
			$le_rnd_hash = md5(uniqid('le-', true));
			if(!is_dir('/usr/local/ispconfig/interface/acme/.well-known/acme-challenge/')) {
				$app->system->mkdir('/usr/local/ispconfig/interface/acme/.well-known/acme-challenge/', false, 0755, true);
			}
			file_put_contents('/usr/local/ispconfig/interface/acme/.well-known/acme-challenge/' . $le_rnd_file, $le_rnd_hash);

			$checked_domains = [];
			foreach($certificate_domains as $domain_to_check) {
				$le_hash_check = trim(@file_get_contents('http://' . $domain_to_check . '/.well-known/acme-challenge/' . $le_rnd_file));
				if($le_hash_check == $le_rnd_hash) {
					$checked_domains[] = $domain_to_check;
					$app->log("Verified domain " . $domain_to_check . " should be reachable for let's encrypt.", LOGLEVEL_DEBUG);
				} else {
					$app->log("Could not verify domain " . $domain_to_check . ", so excluding it from let's encrypt request.", LOGLEVEL_WARN);
				}
			}
			$certificate_domains = $checked_domains;
			@unlink('/usr/local/ispconfig/interface/acme/.well-known/acme-challenge/' . $le_rnd_file);
		}

		$le_domain_count = count($certificate_domains);
		if($le_domain_count > 100) {
			$certificate_domains = array_slice($certificate_domains, 0, 100);
			$app->log("There were " . $le_domain_count . " domains in the domain list. LE only supports 100, so we strip the rest.", LOGLEVEL_WARN);
		}

		return $certificate_domains;
	}

	private function link_file($target, $source) {
		global $app;

		$needs_link = true;
		if(@is_link($target)) {
			$existing_source = readlink($target);
			if($existing_source == $source) {
				$needs_link = false;
			} else {
				$app->system->unlink($target);
			}
		} elseif(is_file($target)) {
			$suffix = '.old.' . date('YmdHis');
			$app->system->copy($target, $target . $suffix);
			$app->system->chmod($target . $suffix, 0400);
			$app->system->unlink($target);
		}
		if($needs_link) {
			$app->system->exec_safe("ln -s ? ?", $source, $target);
		}
	}

	public function request_certificates($data, $server_type = 'apache', $desired_signature_type = '') {
		global $app, $conf;

		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');
		$server_config = $app->getconf->get_server_config($conf['server_id'], 'server');

		if(!in_array($desired_signature_type, ['RSA', 'ECDSA'])) {
			$desired_signature_type = $web_config['le_signature_type'] ?: 'RSA';
		}

		$certificate_paths = $this->get_website_certificate_paths($data);
		$key_file = $certificate_paths['key'];
		$crt_file = $certificate_paths['crt'];
		$bundle_file = $certificate_paths['bundle'];
		$main_domain = $certificate_paths['domain'];
		$migration_mode = isset($server_config['migration_mode']) && $server_config['migration_mode'] == 'y';
		$do_check = (empty($web_config['skip_le_check']) || $web_config['skip_le_check'] == 'n') && !$migration_mode;
		$certificate_domains = $this->assemble_domains_to_request($data, $main_domain, $do_check);

		if(empty($certificate_domains)) {
			return false;
		}

		if($migration_mode) {
			$app->log("Migration mode active, skipping Let's Encrypt SSL Cert creation for: $main_domain", LOGLEVEL_DEBUG);
		}

		$use_acme = $this->use_acme();
		if($use_acme === null) {
			return false;
		}

		if($use_acme) {
			if(!$migration_mode) {
				$letsencrypt_cmd = $this->get_acme_command($certificate_domains, $key_file, $bundle_file, $crt_file, $server_type, $desired_signature_type);
				// Cleanup ssl cert symlinks, if exists so that amcme.sh can install copies of its files to the target location
				if(@is_link($key_file)) unlink($key_file);
				if(@is_link($bundle_file)) unlink($bundle_file);
				if(@is_link($crt_file)) unlink($crt_file);

				$app->log("Create Let's Encrypt SSL Cert for " . $main_domain . ' (' . $desired_signature_type . ') via acme.sh, domains to include: ' . join(', ', $certificate_domains), LOGLEVEL_DEBUG);
				$old_umask = umask(0022);  # work around acme.sh permission bug, see #6015
				$success = $letsencrypt_cmd && $app->system->_exec($letsencrypt_cmd, [2]);
				umask($old_umask);
				if(!$success) {
					$app->log("Let's Encrypt SSL Cert for " . $main_domain . ' via acme.sh could not be issued. Used command: ' . $letsencrypt_cmd, LOGLEVEL_WARN);
					return false;
				}
			}
			// acme.sh directly installs a copy of the certificate at the place we expect them to be, so we are done here
			return true;
		} else {
			if(!$migration_mode) {
				$letsencrypt_cmd = $this->get_certbot_command($certificate_domains, $desired_signature_type);
				// get_certbot_command sets $this->certbot_use_certcommand
				$app->log("Create Let's Encrypt SSL Cert for " . $main_domain . ' (' . $desired_signature_type . ') via certbot, domains to include: ' . join(', ', $certificate_domains), LOGLEVEL_DEBUG);
				$success = $letsencrypt_cmd && $app->system->_exec($letsencrypt_cmd);
				if(!$success) {
					$app->log("Let's Encrypt SSL Cert for " . $main_domain . ' via certbot could not be issued. Used command: ' . $letsencrypt_cmd, LOGLEVEL_WARN);
					return false;
				}
			}

			$discovered_paths = $this->get_letsencrypt_certificate_paths($certificate_domains, $desired_signature_type);
			if(empty($discovered_paths)) {
				$app->log("Let's Encrypt Cert file: could not find the issued certificate", LOGLEVEL_WARN);
				return false;
			}
			$this->link_file($key_file, $discovered_paths['privkey']);
			$this->link_file($bundle_file, $discovered_paths['chain']);
			if($server_type != 'apache' || version_compare($app->system->getapacheversion(true), '2.4.8', '>=')) {
				$this->link_file($crt_file, $discovered_paths['fullchain']);
			} else {
				$this->link_file($crt_file, $discovered_paths['cert']);
			}
			return true;
		}
	}

	/**
	 * Gets a list of all installed certificates on this server.
	 *
	 * @return array
	 */
	public function get_certificate_list() {
		global $app, $conf;

		$shell_script = '';
		$use_acme = $this->use_acme($shell_script);
		if($use_acme === null || !$shell_script) {
			$app->log('get_certificate_list: did not find acme.sh nor certbot', LOGLEVEL_WARN);
			return [];
		}

		$candidates = [];
		if($use_acme) {
			// Use an inline shell script to get the configured acme.sh certificate home.
			// We use a shell script because acme.sh config file is a shell script itself - to support even dynamic configs, we will evaluate the config file.
			// The used --info command was not always there, so we try to auto-upgrade acme.sh when the command fails
			$home_extract_cmd = join(' ; ', [
				'_info() { :',
				'  _info_stdout=$(' . escapeshellarg($shell_script) . ' --info 2>/dev/null)',
				'  _info_ret=$?',
				'}',
				'_echo_home() { :',
				'  eval "$_info_stdout"',
				'  _info_ret=$?',
				'  if [ $_info_ret -eq 0 ]; then :',
				'    if [ -z "$CERT_HOME" ]',
				'      then echo "$LE_CONFIG_HOME"',
				'      else echo "$CERT_HOME"',
				'    fi',
				'   else :',
				'     echo "Error eval-ing --info output (exit code $_info_ret). stdout was: $_info_stdout"',
				'     exit 1',
				'  fi',
				'}',
				'_info',
				'if [ $_info_ret -eq 0 ]; then :',
				'  _echo_home',
				'else :',
				'  if ' . escapeshellarg($shell_script) . ' --upgrade 2>&1; then :',
				'    _info',
				'    if [ $_info_ret -eq 0 ]; then :',
				'      _echo_home',
				'    else :',
				'      echo "--info failed (exit code $_info_ret). stdout was: $_info_stdout"',
				'      exit 1',
				'    fi',
				'  else :',
				'    echo "--info failed (exit code $_info_ret) and auto-upgrade failed, too. Initial info stdout was: $_info_stdout"',
				'    exit 1',
				'  fi',
				'fi',
			]);
			$ret = 0;
			$cert_home = [];
			exec($home_extract_cmd, $cert_home, $ret);
			$cert_home = trim(implode("\n", $cert_home));
			if($ret != 0 || empty($cert_home) || !is_dir($cert_home)) {
				$app->log('get_certificate_list: could not find certificate home. Error: ' . $cert_home . '. Command used: ' . $home_extract_cmd, LOGLEVEL_ERROR);
				return [];
			}
			$app->log('get_certificate_list: discovered cert home as ' . $cert_home . '. Command used: ' . $home_extract_cmd, LOGLEVEL_DEBUG);
			$dir = opendir($cert_home);
			if(!$dir) {
				$app->log('get_certificate_list: could not open certificate home ' . $cert_home, LOGLEVEL_ERROR);
				return [];
			}
			while($path = readdir($dir)) {
				$full_path = $cert_home . '/' . $path;
				// valid conf dirs have a . in them
				if($path === '.' || $path === '..' || strpos($path, '.') === false || !is_dir($full_path)) {
					continue;
				}
				$domain = $path;
				if(preg_match('/_ecc$/', $path)) {
					$domain = substr($path, 0, -4);
				}
				if(!$this->is_readable_link_or_file($full_path . '/' . $domain . '.conf')) {
					$app->log('get_certificate_list: skip ' . $full_path . '/' . $domain . '.conf because it is not readable', LOGLEVEL_DEBUG);
					continue;
				}
				$candidates[] = [
					'source' => 'acme.sh',
					'id' => $path,
					'conf' => $full_path,
					'cert_paths' => [
						'cert' => "$full_path/$domain.cer",
						'privkey' => "$full_path/$domain.key",
						'chain' => "$full_path/ca.cer",
						'fullchain' => "$full_path/fullchain.cer",
					]
				];
			}
		} else {
			if(!is_dir($this->renew_config_path)) {
				$app->log('get_certificate_list: certbot renew dir not found: ' . $this->renew_config_path, LOGLEVEL_ERROR);
				return [];
			}
			$dir = opendir($this->renew_config_path);
			if(!$dir) {
				$app->log('get_certificate_list: could not open certbot renew dir', LOGLEVEL_ERROR);
				return [];
			}
			while($file = readdir($dir)) {
				$file_path = $this->renew_config_path . $conf['fs_div'] . $file;
				if($file === '.' || $file === '..' || substr($file, -5) !== '.conf' || !$this->is_readable_link_or_file($file_path)) {
					continue;
				}
				$fp = fopen($file_path, 'r');
				if(!$fp) continue;
				$certificate = [
					'source' => 'certbot',
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
				$candidates[] = $certificate;
			}
			closedir($dir);
		}

		$certificates = [];
		foreach($candidates as $certificate) {
			if($this->is_readable_link_or_file($certificate['cert_paths']['cert'])
				&& $this->is_readable_link_or_file($certificate['cert_paths']['privkey'])
				&& $this->is_readable_link_or_file($certificate['cert_paths']['fullchain'])
				&& $this->is_readable_link_or_file($certificate['cert_paths']['chain'])) {
				$info = $this->extract_x509($certificate['cert_paths']['cert'], $certificate['cert_paths']['chain']);
				if($info) {
					$certificate = array_merge($certificate, $info);
					$certificates[] = $certificate;
					$app->log('get_certificate_list found certificate ' . $certificate['conf'] . ' ' . $certificate['signature_type'] . ' ' . $certificate['serial_number'] . ($certificate['is_valid'] ? ' (valid) ' : ' (invalid) ') . join(', ', $certificate['domains']), LOGLEVEL_DEBUG);
				} else {
					$app->log('get_certificate_list certificate candidate ' . $certificate['conf'] . ' invalid because X509 extraction was unsuccessful', LOGLEVEL_DEBUG);
				}
			} else {
				$app->log('get_certificate_list certificate candidate ' . $certificate['conf'] . ' invalid because files are missing', LOGLEVEL_DEBUG);
			}
		}
		return $certificates;
	}

	/** @var array|null */
	private $_deny_list_domains = null;
	/** @var array|null */
	private $_deny_list_serials = null;

	private function get_deny_list() {
		global $app, $conf;

		if(is_null($this->_deny_list_domains)) {
			$server_db_record = $app->db->queryOneRecord("SELECT * FROM server WHERE server_id = ?", $conf['server_id']);
			$app->uses('getconf');
			$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');

			$this->_deny_list_domains = empty($web_config['le_auto_cleanup_denylist']) ? [] : array_filter(array_map(function($pattern) use ($server_db_record) {
				$pattern = trim($pattern);
				if($server_db_record && $pattern == '[server_name]') {
					return $server_db_record['server_name'];
				}

				return $pattern;
			}, explode(',', $web_config['le_auto_cleanup_denylist'])));

			$this->_deny_list_domains = array_values(array_unique($this->_deny_list_domains));

			// search certificates the installer creates and automatically add their serial numbers to deny list
			$this->_deny_list_serials = [];
			foreach([
						'/usr/local/ispconfig/interface/ssl/ispserver.crt',
						'/etc/postfix/smtpd.cert',
						'/etc/ssl/private/pure-ftpd.pem'
					] as $possible_cert_file) {
				$cert = $this->extract_first_certificate($possible_cert_file);
				if($cert) {
					$info = $this->extract_x509($cert);
					if($info) {
						$app->log('add serial number ' . $info['serial_number'] . ' from ' . $possible_cert_file . ' to deny list', LOGLEVEL_DEBUG);
						$this->_deny_list_serials[] = $info['serial_number'];
					}
				}
			}
			$this->_deny_list_serials = array_values(array_unique($this->_deny_list_serials));
		}
		return [$this->_deny_list_domains, $this->_deny_list_serials];
	}

	/**
	 * Checks if $certificate is on the deny list or has a wildcard domain.
	 * Returns an array of the deny list patterns and serials numbers that matched the certificate.
	 * An empty array means that the $certificate is not on the deny list.
	 *
	 * @param array $certificate
	 * @return array
	 */
	public function check_deny_list($certificate) {
		list($deny_list_domains, $deny_list_serials) = $this->get_deny_list();
		$on_deny_list = [];
		foreach($certificate['domains'] as $cert_domain) {
			if(substr($cert_domain, 0, 2) == '*.') {
				// wildcard domains are always on the deny list
				$on_deny_list[] = $cert_domain;
			} else {
				$on_deny_list = array_merge($on_deny_list, array_filter($deny_list_domains, function($deny_pattern) use ($cert_domain) {
					return mb_strtolower($deny_pattern) == mb_strtolower($cert_domain) || fnmatch($deny_pattern, $cert_domain, FNM_CASEFOLD);
				}));
			}
		}
		if(in_array($certificate['serial_number'], $deny_list_serials, true)) {
			$on_deny_list[] = $certificate['serial_number'];
		}
		return $on_deny_list;
	}

	/**
	 * Remove and maybe revoke a certificate.
	 * @param array $certificate the certificate (from get_certificate_list())
	 * @param null|bool $revoke_before_delete try to revoke certificate before deletion. when `null` the configured default is used.
	 * @param bool $check_deny_list refuse to delete certificate when it is on the servers purge deny list.
	 * @return bool whether the certificate could be removed
	 */
	public function remove_certificate($certificate, $revoke_before_delete = null, $check_deny_list = true) {
		global $app, $conf;

		if(is_null($revoke_before_delete)) {
			$app->uses('getconf');
			$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');
			$revoke_before_delete = !empty($web_config['le_revoke_before_delete']) && $web_config['le_revoke_before_delete'] == 'y';
		}

		if($certificate['is_revoked'] && $revoke_before_delete) {
			$revoke_before_delete = false;
			$app->log('remove_certificate: skip revokation of ' . $certificate['id'] . ' because it already is revoked', LOGLEVEL_DEBUG);
		}

		if($check_deny_list) {
			$on_deny_list = $this->check_deny_list($certificate);
			if(!empty($on_deny_list)) {
				$app->log('remove_certificate: did not remove ' . $certificate['id'] . ' because one of its domains is on deny list or a wildcard domain (' . join(', ', $on_deny_list) . ')', LOGLEVEL_DEBUG);
				return false;
			}
		}

		if($certificate['source'] == 'certbot') {
			$certbot_script = $this->get_certbot_script();
			if(!$certbot_script) {
				$app->log("remove_certificate: certbot not found, cannot delete " . $certificate['id'], LOGLEVEL_WARN);
				return false;
			}
			$version = $this->get_certbot_version($certbot_script);
			if($revoke_before_delete && $this->is_readable_link_or_file($certificate['cert_paths']['cert'])) {
				if(version_compare($version, '0.22', '>=')) {
					$server = 'https://acme-v02.api.letsencrypt.org/directory';
				} else {
					$server = 'https://acme-v01.api.letsencrypt.org/directory';
				}
				$app->system->exec_safe($certbot_script . ' revoke -n --server ? --cert-path ? --reason cessationofoperation 2>&1', $server, $certificate['cert_paths']['cert']);
				if($app->system->last_exec_retcode() == 0) {
					$app->log('remove_certificate: certbot revoked ' . $certificate['id'] . ' before deletion', LOGLEVEL_DEBUG);
				} else {
					$app->log('remove_certificate: certbot revoke ' . $certificate['id'] . ' before deletion failed: ' . $app->system->last_exec_out(), LOGLEVEL_WARN);
				}
			} else {
				$app->log('remove_certificate: certbot skip revoke ' . $certificate['id'] . ' before deletion', LOGLEVEL_DEBUG);
			}
			// the revoke command above might already have done the delete
			if(is_file($certificate['conf'])) {
				if(version_compare($version, '0.30.0', '<')) {
					$app->log('remove_certificate: certbot is very old. Please update for proper certificate deletion.', LOGLEVEL_WARN);
				} else {
					$app->system->exec_safe($certbot_script . ' delete -n --cert-name ? 2>&1', $certificate['id']);
					if($app->system->last_exec_retcode() != 0) {
						$app->log('remove_certificate: certbot delete -n --cert-name ' . $certificate['id'] . ' failed: ' . $app->system->last_exec_out(), LOGLEVEL_WARN);
					}
				}
			}
			// if the conf file is still lingering around, we move it out of the way
			if(is_file($certificate['conf'])) {
				@rename($certificate['conf'], $certificate['conf'] . '.removed');
				$app->log('remove_certificate: manually move renew conf ' . $certificate['conf'] . ' out of the way.', LOGLEVEL_DEBUG);
			}
		} else {
			if(is_dir($certificate['conf'])) {
				if($revoke_before_delete) {
					$acme_script = $this->get_acme_script();
					if($acme_script) {
						$cert_selection = '';
						$domain = $certificate['id'];
						if(substr($domain, -4) == '_ecc') {
							$cert_selection = '--ecc';
							$domain = substr($domain, 0, -4);
						}
						// 5 = cessationOfOperation, see https://github.com/acmesh-official/acme.sh/wiki/revokecert
						$app->system->exec_safe($acme_script . ' --revoke --revoke-reason 5 -d ? ' . $cert_selection . ' 2>&1', $domain);
						if($app->system->last_exec_retcode() == 0) {
							$app->log('remove_certificate: acme.sh revoked ' . $certificate['id'] . ' before deletion', LOGLEVEL_DEBUG);
						} else {
							$app->log('remove_certificate: acme.sh revoke ' . $certificate['id'] . ' before deletion failed: ' . $app->system->last_exec_out(), LOGLEVEL_WARN);
						}
					}
				} else {
					$app->log('remove_certificate: acme.sh skip revoke ' . $certificate['id'] . ' before deletion', LOGLEVEL_DEBUG);
				}
				if(!$app->system->rmdir($certificate['conf'], true)) {
					$app->log('remove_certificate: could not delete config folder ' . $certificate['conf'], LOGLEVEL_WARN);
					return false;
				}
			}
		}
		return true;
	}

	public function extract_x509($cert_file_or_contents, $chain_file = null) {
		global $app;
		if(!function_exists('openssl_x509_parse')) {
			$app->log('extract_x509: openssl extension missing', LOGLEVEL_ERROR);
			return false;
		}
		$cert_file = false;
		if(strpos($cert_file_or_contents, '-----BEGIN CERTIFICATE-----') === false) {
			$cert_file = $cert_file_or_contents;
			$cert_file_or_contents = file_get_contents($cert_file_or_contents);
		}
		$info = openssl_x509_parse($cert_file_or_contents, true);
		if(!$info) {
			$app->log('extract_x509: ' . ($cert_file ?: 'inline certificate') . ' could not be parsed', LOGLEVEL_ERROR);
			return false;
		}
		if(empty($info['subject']['CN']) || !$this->is_domain_name_or_wildcard($info['subject']['CN'])) {
			$domains = [];
		} else {
			$domains = [$app->functions->idn_encode($info['subject']['CN'])];
		}
		if(!empty($info['extensions']) && !empty($info['extensions']['subjectAltName'])) {
			$domains = array_filter(array_merge($domains, array_map(function($i) {
				global $app;
				$parts = explode(':', $i, 2);
				if(count($parts) < 2) {
					return false;
				}
				$maybe_domain = trim($parts[1]);
				if(filter_var($maybe_domain, FILTER_VALIDATE_IP)) {
					return $maybe_domain;
				}
				if($this->is_domain_name_or_wildcard($maybe_domain)) {
					return $app->functions->idn_encode($maybe_domain);
				}
				return false;
			}, explode(',', $info['extensions']['subjectAltName']))));
			$domains = array_values(array_unique($domains));
		}
		if(empty($domains)) {
			return false;
		}
		$valid_from = new DateTime('@' . $info['validFrom_time_t']);
		$valid_to = new DateTime('@' . $info['validTo_time_t']);
		$now = new DateTime();
		$is_valid = $valid_from <= $now && $now <= $valid_to;
		$is_revoked = null;
		// only do online revokation check when cert is valid and we got the required chain
		if($is_valid && $cert_file && $this->is_readable_link_or_file($chain_file)) {
			$ocsp_uri = $app->system->exec_safe('openssl x509 -noout -ocsp_uri -in ? 2>&1', $cert_file);
			$ocsp_host = parse_url($ocsp_uri ?: '', PHP_URL_HOST);
			if($ocsp_uri && $ocsp_host) {
				$ocsp_response = $app->system->system_safe('openssl ocsp -issuer ? -cert ? -text -url ? -header HOST=? 2>&1', $chain_file, $cert_file, $ocsp_uri, $ocsp_host);
				if($app->system->last_exec_retcode() == 0) {
					$is_revoked = strpos($ocsp_response, 'Cert Status: good') === false;
					if($is_revoked) {
						$is_valid = false;
					}
				} else {
					$app->log('extract_x509: ' . $cert_file . ' getting OCSP response from ' . $ocsp_uri . ' failed: ' . $ocsp_response, LOGLEVEL_WARN);
				}
			}
		}
		$signature_type = 'RSA';
		$long_type = strtolower(isset($info['signatureTypeLN']) ? $info['signatureTypeLN'] : '?');
		if(strpos($long_type, 'ecdsa') !== false) {
			$signature_type = 'ECDSA';
		}
		return [
			'serial_number' => $info['serialNumberHex'] ?: $info['serialNumber'],
			'signature_type' => $signature_type,
			'subject' => $info['subject'],
			'issuer' => $info['issuer'],
			'domains' => $domains,
			'is_valid' => $is_valid,
			'is_revoked' => $is_revoked,
			'valid_from' => $valid_from,
			'valid_to' => $valid_to,
		];
	}

	private function extract_first_certificate($file) {
		if(!$this->is_readable_link_or_file($file)) {
			return false;
		}
		$contents = file_get_contents($file);
		if(!$contents) {
			return false;
		}
		$matches = [];
		if(!preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/ms', $contents, $matches)) {
			return false;
		}
		return $matches[0];
	}

	private function is_domain_name_or_wildcard($input) {
		$input = filter_var($input, FILTER_VALIDATE_DOMAIN);
		if(!$input) {
			return false;
		}
		// $input can still be something like "some. invalid . domain % name", so we check with a simple regex that no unusual things are in domain name
		return preg_match("/^(\*\.)?[\w\p{L}0-9._-]+$/u", $input);
	}

	private function is_readable_link_or_file($path) {
		return $path && (@is_link($path) || @is_file($path)) && @is_readable($path);
	}
}
