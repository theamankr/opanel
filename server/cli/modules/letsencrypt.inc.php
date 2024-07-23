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

class letsencrypt_cli extends cli
{

	function __construct()
	{
		$cmd_opt                                = [];
		$cmd_opt['letsencrypt']                 = 'showHelp';
		$cmd_opt['letsencrypt:list']            = 'list';
		$cmd_opt['letsencrypt:cleanup-expired'] = 'cleanupExpired';
		$this->addCmdOpt($cmd_opt);
	}

	public function list($arg)
	{
		global $app;
		$app->uses('letsencrypt');
		$certificates = $app->letsencrypt->get_certificate_list();
		foreach ($certificates as $certificate) {
			print_r($certificate);
		}
	}

	public function cleanupExpired($arg)
	{
		global $app;
		$app->uses('letsencrypt');
		$removals     = 0;
		$hasErrors    = false;
		$certificates = $app->letsencrypt->get_certificate_list();
		foreach ($certificates as $certificate) {
			if (! $certificate['is_valid']) {
				$this->swriteln("Removing ".join(', ', $certificate['domains'])." expired certificate...");
				if ($app->letsencrypt->remove_certificate($certificate)) {
					$removals += 1;
				} else {
					$this->swriteln("Could not remove ".print_r($certificate, true));
					$hasErrors = true;
				}
			}
		}
		if ($removals) {
			$this->swriteln("Removed $removals expired certificates");
		} else {
			$this->swriteln("No certificates were removed");
		}
		if ($hasErrors) {
			exit(1);
		}
	}

	public function showHelp($arg)
	{
		global $conf;

		$this->swriteln("---------------------------------");
		$this->swriteln("- Available commandline option -");
		$this->swriteln("---------------------------------");
		$this->swriteln("ispc letsencrypt list - lists all known certificates");
		$this->swriteln("ispc letsencrypt cleanup-expired - Cleanup all expired certificates.");
		$this->swriteln("---------------------------------");
		$this->swriteln();
	}

}

