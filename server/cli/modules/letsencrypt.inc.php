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

class letsencrypt_cli extends cli {

	function __construct() {
		$cmd_opt = [];
		$cmd_opt['letsencrypt'] = 'showHelp';
		$cmd_opt['letsencrypt:list'] = 'listCertificates';
		$cmd_opt['letsencrypt:info'] = 'outputCertificate';
		$cmd_opt['letsencrypt:cleanup-expired'] = 'cleanupExpired';
		$this->addCmdOpt($cmd_opt);
	}

	public function listCertificates($arg) {
		global $app;
		$app->uses('letsencrypt');
		$certificates = $this->getCertificates();
		if(empty($certificates)) {
			return;
		}
		$table = [['type', 'id', 'valid info', 'serial', 'domains']];
		$ansi_reset = "\033[0m";
		$bold_red = "\033[1m\033[31m";
		$bold_green = "\033[1m\033[32m";
		foreach($certificates as $certificate) {
			$valid = ($certificate['is_valid'] ? ($bold_green . 'yes' . $ansi_reset) : ($bold_red . 'no ' . $ansi_reset)) . ' ' . $this->getValidInfo($certificate);
			$table[] = [
				$certificate['signature_type'],
				$certificate['id'],
				$valid,
				$certificate['serial_number'],
				$this->getList($certificate['domains']),
			];
		}
		$this->outputTable($table, ['variable_columns' => '1,4', 'expand' => true]);
	}

	public function outputCertificate($args) {
		global $app;
		if(empty($args)) {

		}
		if(empty($args)) {
			$this->swriteln('error: ID of the certificate is missing');
			$this->showHelp($args);
			exit(1);
		}
		$app->uses('letsencrypt');
		$certificates = $this->getCertificates();
		if(empty($certificates)) {
			return;
		}
		foreach($args as $id) {
			$certificate = false;
			foreach($certificates as $c) {
				if($c['id'] == $id) {
					$certificate = $c;
					break;
				}
			}
			if($certificate) {
				$ansi_reset = "\033[0m";
				$bold_red = "\033[1m\033[31m";
				$bold_green = "\033[1m\033[32m";
				$bold_yellow = "\033[1m\033[33m";
				$gray = "\033[38;5;7m";
				$valid = ($certificate['is_valid'] ? ($bold_green . 'yes' . $ansi_reset) : ($bold_red . 'no ' . $ansi_reset)) . ' ' . $this->getValidInfo($certificate);
				$table = [
					['key', 'value'],
					['id', $certificate['id']],
					['serial', $certificate['serial_number']],
					['type', $certificate['signature_type']],
					['valid', $valid . "\n" . $gray . 'from ' . $ansi_reset . $certificate['valid_from']->format('Y-m-d H:i:s') . "\n" . $gray . 'to   ' . $ansi_reset . $certificate['valid_to']->format('Y-m-d H:i:s')],
					['revokation', $certificate['is_revoked'] === null ? ($bold_yellow . 'not checked' . $ansi_reset) : $certificate['is_revoked'] ? ($bold_red . 'REVOKED' . $ansi_reset) : ($bold_green . 'not revoked' . $ansi_reset)],
					['domains', $this->getList($certificate['domains'])],
					['subject', $this->getAssocArray($certificate['subject'])],
					['issuer', $this->getAssocArray($certificate['issuer'])],
					['source', $certificate['source']],
					['conf', $certificate['conf']],
					['files', $this->getAssocArray($certificate['cert_paths'])],
				];
				$this->outputTable($table, ['min_lengths' => [10], 'variable_columns' => '1', 'expand' => true]);
			} else {
				$this->swriteln("\n" . 'Certificate not found: ' . $id . "\n");
			}
		}

	}

	public function cleanupExpired($arg) {
		global $app;
		$app->uses('letsencrypt');
		$removals = 0;
		$hasErrors = false;
		$certificates = $this->getCertificates();
		if(empty($certificates)) {
			return;
		}
		$certificates_to_remove = [];
		foreach($certificates as $certificate) {
			if(!$certificate['is_valid']) {
				$certificates_to_remove[] = $certificate;
			}
		}
		if(empty($certificates_to_remove)) {
			$this->swriteln('No expired certificates found');
			return;
		}
		$ansi_reset = "\033[0m";
		$bold_red = "\033[1m\033[31m";
		$table = [['type', 'id', 'valid info', 'serial', 'domains']];
		foreach($certificates_to_remove as $certificate) {
			$valid = $this->getValidInfo($certificate);
			$table[] = [
				$certificate['signature_type'],
				$certificate['id'],
				$valid,
				$certificate['serial_number'],
				$this->getList($certificate['domains']),
			];
		}
		$this->outputTable($table, ['variable_columns' => '1,4', 'expand' => true]);
		$this->swriteln('');
		if($this->simple_query($bold_red . 'Do you want to delete the certificates?' . $ansi_reset, ['yes', 'no'], 'no') == 'no') {
			$this->swriteln('No certificates were removed');
			return;
		}
		foreach($certificates_to_remove as $certificate) {
			$this->swriteln('Removing expired certificate ' . $certificate['id']);
			if($app->letsencrypt->remove_certificate($certificate)) {
				$removals += 1;
			} else {
				$this->swriteln("Could not remove " . print_r($certificate, true));
				$hasErrors = true;
			}
		}
		$this->swriteln('Removed ' . $removals . ' expired certificates');
		if($hasErrors) {
			exit(1);
		}
	}

	public function showHelp($arg) {
		global $conf;

		$this->swriteln("---------------------------------");
		$this->swriteln("- Available commandline options -");
		$this->swriteln("---------------------------------");
		$this->swriteln("ispc letsencrypt list - lists all known certificates");
		$this->swriteln("ispc letsencrypt info <id> - outputs all information of one certificate");
		$this->swriteln("ispc letsencrypt cleanup-expired - Cleanup all expired certificates.");
		$this->swriteln("---------------------------------");
		$this->swriteln();
	}


	private function getList($array) {
		return join("\n", array_map(function($line) {
			$ansi_reset = "\033[0m";
			$gray = "\033[38;5;7m";
			return $gray . '• ' . $ansi_reset . $line;
		}, $array));
	}

	private function getAssocArray($array) {
		return join("\n", array_map(function($key, $value) {
			$ansi_reset = "\033[0m";
			$gray = "\033[38;5;7m";
			return $gray . $key . '=' . $ansi_reset . $value;
		}, array_keys($array), array_values($array)));
	}

	private function getValidInfo($certificate) {
		$ansi_reset = "\033[0m";
		$bold_red = "\033[1m\033[31m";
		$bold_green = "\033[1m\033[32m";
		$bold_yellow = "\033[1m\033[33m";
		$gray = "\033[38;5;7m";
		$now = new DateTime('now');
		$diff = $now->diff($certificate['valid_to'])->format('%r%a');
		if($diff > 0) {
			if($diff <= 7) {
				$info = $bold_yellow . $diff . ' day' . ($diff > 1 ? 's' : '') . ' valid' . $ansi_reset;
			} else {
				$info = $bold_green . $diff . ' days valid' . $ansi_reset;
			}
		} else {
			$diff = abs($diff);
			$info = $bold_red . $diff . ' day' . ($diff != 1 ? 's' : '') . ' expired' . $ansi_reset;
		}
		// $info .= $gray . $certificate['valid_to']->format('Y-m-d H:i:s') . $ansi_reset;
		if($certificate['is_revoked'] === null) {
			$info .= $gray . ' (no OCSP)' . $ansi_reset;
		} elseif($certificate['is_revoked']) {
			$info .= $bold_red . ' REVOKED' . $ansi_reset;
		}
		return $info;
	}

	private function getCertificates() {
		global $app;
		$this->swriteln('Getting all certificates…');
		$certificates = $app->letsencrypt->get_certificate_list();
		if(empty($certificates)) {
			$this->swriteln('No certificates found');
		}
		return $certificates;
	}
}

