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

class cronjob_letsencrypt_cleanup extends cronjob
{

	// job schedule
	protected $_schedule = '@weekly';

	private $denylist = [];

	protected function onBeforeRun()
	{
		global $app, $conf;
		$app->uses('letsencrypt,ini_parser,getconf');

		$server_db_record = $app->db->queryOneRecord("SELECT * FROM server WHERE server_id = ?", $conf['server_id']);
		if (! $server_db_record || ! $server_db_record['web_server']) {
			if ($conf['log_priority'] <= LOGLEVEL_DEBUG) {
				print 'Webserver not active, not running Let\'s Encrypt cleanup.'."\n";
			}

			return false;
		}

		$server_config = $app->getconf->get_server_config($conf['server_id'], 'server');
		if (($server_config['migration_mode'] ?? 'n') != 'y') {
			if ($conf['log_priority'] <= LOGLEVEL_DEBUG) {
				print 'Migration mode active, not running Let\'s Encrypt cleanup.'."\n";
			}

			return false;
		}

		if (! $app->letsencrypt->get_acme_script() && ! $app->letsencrypt->get_certbot_script()) {
			if ($conf['log_priority'] <= LOGLEVEL_DEBUG) {
				print 'No Let\'s Encrypt client found, not running Let\'s Encrypt cleanup.'."\n";
			}

			return false;
		}

		$web_config      = $app->getconf->get_server_config($conf['server_id'], 'web');
		$le_auto_cleanup = $web_config['le_auto_cleanup'] ?? 'n';
		if ($le_auto_cleanup == 'n') {
			return false;
		}
		$this->denylist = array_filter(array_map(function ($domain) use ($server_db_record) {
			$domain = trim($domain);
			if ($domain == '[server_name]') {
				return $server_db_record['server_name'];
			}

			return $domain;
		}, explode(',', $web_config['le_auto_cleanup_denylist'] ?? '')));

		return parent::onBeforeRun();
	}


	public function onRunJob()
	{
		global $app, $conf;
		$used_serials       = [];
		$active_ssl_records = $app->db->queryAllRecords(
			"SELECT * FROM web_domain WHERE active = 'y' AND ssl_letsencrypt = 'y' AND `ssl` = 'y' AND document_root IS NOT NULL AND server_id = ?",
			$conf['server_id']
		);
		foreach ($active_ssl_records as $active_ssl_record) {
			$cert_paths = $app->letsencrypt->get_website_certificate_paths(['new' => $active_ssl_record]);
			if (is_readable($cert_paths['crt'])) {
				$info = $app->letsencrypt->extract_x509($cert_paths['crt']);
				if ($info) {
					$used_serials[] = $info['serialNumber'];
					if ($conf['log_priority'] <= LOGLEVEL_DEBUG) {
						print 'mark serial number '.$info['serialNumber'].' as used from '.$cert_paths['crt']."\n";
					}
				} else {
					if ($conf['log_priority'] <= LOGLEVEL_DEBUG) {
						print 'cannot extract x509 information from '.$cert_paths['crt']."\n";
					}
				}
			} else {
				if ($conf['log_priority'] <= LOGLEVEL_DEBUG) {
					print $cert_paths['crt'].' is not readable'."\n";
				}
			}
		}

		$certificates = $app->letsencrypt->get_certificate_list();
		foreach ($certificates as $certificate) {
			if (in_array($certificate['serialNumber'], $used_serials)) {
				if ($conf['log_priority'] <= LOGLEVEL_DEBUG) {
					print 'Skip '.$certificate['id'] . ' because it still gets used by ISPConfig' . "\n";
				}
				continue;
			}
			foreach ($this->denylist as $domain) {
				if (in_array($domain, $certificate['domains'])) {
					if ($conf['log_priority'] <= LOGLEVEL_DEBUG) {
						print 'Skip '.$certificate['id'] . ' because it is on denylist' . "\n";
					}
					continue 2;
				}
			}
			if ($app->letsencrypt->remove_certificate($certificate)) {
				print 'Removed unused certificate '.$certificate['id']."\n";
			} else {
				print 'Error removing certificate '.$certificate['id']."\n";
			}
		}

		parent::onRunJob();
	}
}
