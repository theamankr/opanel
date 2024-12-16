<?php

/*
Copyright (c) 2020, Herman van Rink, Initfour websolutions
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

class cronjob_monitor_domain_mx  extends cronjob {

	// job schedule
	protected $_schedule = '30 6 * * *';
	protected $_run_at_new = true;

	private $_tools = null;

	/* this function is optional if it contains no custom code */
	public function onPrepare() {
		global $app;

		parent::onPrepare();
	}

	/* this function is optional if it contains no custom code */
	public function onBeforeRun() {
		global $app;

		return parent::onBeforeRun();
	}

	private function _resolveHostnameBoth46($hostname) {
		global $app;
		$app->uses('getconf,functions');
		$smtpin_ips = gethostbynamel($hostname);
		$smtpin_ips_v6 = $app->functions->gethostbynamel6($hostname);
		if ($smtpin_ips_v6) {
			$smtpin_ips = array_merge($smtpin_ips, $smtpin_ips_v6);
		}
		return $smtpin_ips;
	}

	public function onRunJob() {
		global $app, $conf;

		$app->uses('getconf,functions');
		$mail_config = $app->getconf->get_server_config($conf['server_id'], 'mail');

		if ($mail_config['monitor_mx_records'] != 'y') {
			return;
		}
		/* used for all monitor cronjobs */
		$app->load('monitor_tools');
		$this->_tools = new monitor_tools();
		/* end global section for monitor cronjobs */

		// Initialize data array
		$data = array();
		$state = 'no_state';

		// the id of the server as int
		$server_id = intval($conf['server_id']);

		$hostname = $app->system->hostname();
		$smtpin_ips = $this->_resolveHostnameBoth46($hostname);
		if (empty($smtpin_ips)) {
			$app->log('Our hostname['. $hostname . '] doet not resolve.', LOGLEVEL_WARN);
		}

		# Add additional IP's, e.g. an extrernal spamfilter/proxy.
		if (!empty($mail_config['additional_smtp_hostnames'])) {
			$additional_smtp_hostnames = explode(',', $mail_config['additional_smtp_hostnames']);
			foreach ($additional_smtp_hostnames as $hostname) {
				$extra = $this->_resolveHostnameBoth46($hostname);
				if ($extra) {
					$smtpin_ips = array_merge($smtpin_ips, $extra);
				}
			}
		}

		# Add additional IP's , e.g. for secondary IP on the same box or a proxy.
		if (!empty($mail_config['additional_smtp_ips'])) {
			$smtpin_ips = array_merge($smtpin_ips, explode(',', $mail_config['additional_smtp_ips']));
		}

		$maildomains = $app->db->queryAllRecords("SELECT domain, active FROM mail_domain WHERE server_id = ?", $server_id);
		if(is_array($maildomains)) {
			$state = 'ok';
			foreach ($maildomains as $maildomain) {
				$mx_records = array();
				$mx_weight = array();
				$found_mx = getmxrr($maildomain['domain'], $mx_records, $mx_weight) ;

				$mx_sorted = array();
				$mx_ip = '';

				// Merge records and weight into a single array to sort on priority.
				// ignore multiple mx's at the same weight
				foreach ($mx_records as $key => $name) {
					$mx_sorted[$mx_weight[$key]] = $mx_records[$key];
				}
				ksort ($mx_sorted, SORT_NUMERIC);
				reset ($mx_sorted);

				$first_mx = array_shift($mx_sorted);
				if (!empty($first_mx)) {
					$mx_ip = gethostbyname($first_mx);
				}

				if (empty($mx_ip) || !in_array( $mx_ip, $smtpin_ips)) {
					if ($maildomain['active'] == 'y') {
						$str = 'Domain is active but the DNS does not match our IP.';
						if ($first_mx) {
							$str .= ' (points to ' . $first_mx . ' on ' . $mx_ip . ')';
						}
						else {
							$str .= ' (no mx record found)';
						}
						$app->log('Mail domain[' . $maildomain['domain'] . ']: ' . $str, LOGLEVEL_WARN);
						$state = 'warning';
						$data[$maildomain['domain']] = $str;
					} else {

						$app->log('Good, the mail domain[' . $maildomain['domain'] . '] is not active and DNS is not pointing to us.', LOGLEVEL_DEBUG);
					}
				}
				else {
					if ($maildomain['active'] == 'n') {
						$app->log('DNS points to our IP but the mail domain[' . $maildomain['domain'] . '] is not active.', LOGLEVEL_WARN);
						$state = 'warning';
						$data[$maildomain['domain']] = 'DNS points to our IP but the mail domain is not active.';
					}
					else {
						// DNS OK.
					}
				}
			}
		}

		$res = array();
		$res['server_id'] = $server_id;
		$res['type'] = 'mx_ip_match';
		$res['data'] = $data;
		$res['state'] = $state;

		/**
		 * Insert the data into the database
		 */
		$sql = 'REPLACE INTO monitor_data (server_id, type, created, data, state) ' .
			'VALUES (?, ?, UNIX_TIMESTAMP(), ?, ?)';
		$app->dbmaster->query($sql, $res['server_id'], $res['type'], serialize($res['data']), $res['state']);

		// The new data is written, now we can delete the old one.
		$this->_tools->delOldRecords($res['type'], $res['server_id']);

		parent::onRunJob();
	}

	/* this function is optional if it contains no custom code */
	public function onAfterRun() {
		global $app;

		parent::onAfterRun();
	}

}

?>
