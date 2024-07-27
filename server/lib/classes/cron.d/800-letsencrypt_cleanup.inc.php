<?php

/*
Copyright (c) 2024, Daniel Jagszent
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

class cronjob_letsencrypt_cleanup extends cronjob {

	// job schedule
	protected $_schedule = '@weekly';

	public function onRunJob() {
		global $app, $conf;
		$app->uses('letsencrypt,ini_parser,getconf');

		$server_db_record = $app->db->queryOneRecord("SELECT * FROM server WHERE server_id = ?", $conf['server_id']);
		if(!$server_db_record || !$server_db_record['web_server']) {
			if($conf['log_priority'] <= LOGLEVEL_DEBUG) {
				print 'Webserver not active, not running Let\'s Encrypt cleanup.' . "\n";
			}
			parent::onRunJob();
			return;
		}

		$server_config = $app->getconf->get_server_config($conf['server_id'], 'server');
		if((isset($server_config['migration_mode']) ? $server_config['migration_mode'] : 'n') == 'y') {
			if($conf['log_priority'] <= LOGLEVEL_DEBUG) {
				print 'Migration mode active, not running Let\'s Encrypt cleanup.' . "\n";
			}
			parent::onRunJob();
			return;
		}

		if(!$app->letsencrypt->get_acme_script() && !$app->letsencrypt->get_certbot_script()) {
			if($conf['log_priority'] <= LOGLEVEL_DEBUG) {
				print 'No Let\'s Encrypt client found, not running Let\'s Encrypt cleanup.' . "\n";
			}
			parent::onRunJob();
			return;
		}

		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');
		$le_auto_cleanup = empty($web_config['le_auto_cleanup']) ? 'n' : $web_config['le_auto_cleanup'];
		if($le_auto_cleanup == 'n') {
			parent::onRunJob();
			return;
		}

		$used_serials = [];
		$all_letsencrypt_websites = $app->db->queryAllRecords(
			"SELECT * FROM web_domain WHERE ssl_letsencrypt = 'y' AND `ssl` = 'y' AND document_root IS NOT NULL AND server_id = ?",
			$conf['server_id']
		);
		foreach($all_letsencrypt_websites as $record) {
			$cert_paths = $app->letsencrypt->get_website_certificate_paths(['new' => $record]);
			if(is_readable($cert_paths['crt'])) {
				$info = $app->letsencrypt->extract_x509($cert_paths['crt'], $cert_paths['bundle']);
				if($info) {
					if($record['active'] == 'y') {
						// when website is active we unconditionally deem the certificate as used (even when it is not valid anymore)
						$used = true;
						if($conf['log_priority'] <= LOGLEVEL_DEBUG) {
							print 'active website ' . $record['domain_id'] . '/' . $record['domain'] . ' is using ' . ($info['is_valid'] ? 'valid' : 'invalid') . ' certificate ' . $cert_paths['crt'] . "\n";
						}
					} else {
						// when website is inactive, we only consider its certificates used when the certificate is still valid
						$used = $info['is_valid'];
						if($conf['log_priority'] <= LOGLEVEL_DEBUG) {
							print 'inactive website ' . $record['domain_id'] . '/' . $record['domain'] . ' is using ' . ($info['is_valid'] ? 'valid' : 'invalid') . ' certificate ' . $cert_paths['crt'] . ($used ? '' : ' but we consider it as unused') . "\n";
						}
					}
					if($used) {
						$used_serials[] = $info['serial_number'];
						if($conf['log_priority'] <= LOGLEVEL_DEBUG) {
							print 'mark serial number ' . $info['serial_number'] . ' as used from ' . $cert_paths['crt'] . "\n";
						}
					} else {
						if($conf['log_priority'] <= LOGLEVEL_DEBUG) {
							print 'serial number ' . $info['serial_number'] . ' is referenced but we deem it as unused ' . $cert_paths['crt'] . "\n";
						}
					}
				} else {
					if($conf['log_priority'] <= LOGLEVEL_DEBUG) {
						print 'cannot extract X509 information from ' . $cert_paths['crt'] . "\n";
					}
				}
			} else {
				if($conf['log_priority'] <= LOGLEVEL_DEBUG) {
					print $cert_paths['crt'] . ' is not readable' . "\n";
				}
			}
		}


		$deny_list = empty($web_config['le_auto_cleanup_denylist']) ? [] : array_filter(array_map(function($domain) use ($server_db_record) {
			$domain = trim($domain);
			if($domain == '[server_name]') {
				return $server_db_record['server_name'];
			}

			return $domain;
		}, explode(',', $web_config['le_auto_cleanup_denylist'])));

		$certificates = $app->letsencrypt->get_certificate_list();
		foreach($certificates as $certificate) {
			if(in_array($certificate['serial_number'], $used_serials)) {
				if($conf['log_priority'] <= LOGLEVEL_DEBUG) {
					print 'Skip ' . $certificate['id'] . ' because it still gets used by ISPConfig' . "\n";
				}
				continue;
			}
			foreach($certificate['domains'] as $cert_domain) {
				if(substr($cert_domain, 0, 2) == '*.') {
					if($conf['log_priority'] <= LOGLEVEL_DEBUG) {
						print 'Skip ' . $certificate['id'] . ' because it is a wildcard certificate' . "\n";
					}
					continue 2;
				}
				$on_deny_list = array_filter($deny_list, function($deny_pattern) use ($cert_domain) {
					return mb_strtolower($deny_pattern) == mb_strtolower($cert_domain) || fnmatch($deny_pattern, $cert_domain, FNM_CASEFOLD);
				});
				if(!empty($on_deny_list)) {
					if($conf['log_priority'] <= LOGLEVEL_DEBUG) {
						print 'Skip ' . $certificate['id'] . ' because its domain ' . $cert_domain . ' is on deny list (' . join(', ', $on_deny_list) . ')' . "\n";
					}
					continue 2;
				}
			}
			if($app->letsencrypt->remove_certificate($certificate)) {
				print 'Removed unused certificate ' . $certificate['id'] . "\n";
			} else {
				$app->log('Error removing certificate ' . $certificate['id'], LOGLEVEL_WARN);
				print 'Error removing certificate ' . $certificate['id'] . "\n";
			}
		}

		parent::onRunJob();
	}
}
