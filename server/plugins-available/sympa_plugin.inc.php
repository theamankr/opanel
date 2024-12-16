<?php

/*
Copyright (c) 2007, Till Brehm, projektfarm Gmbh
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

class sympa_plugin {

	var $plugin_name = 'sympa_plugin';
	var $class_name = 'sympa_plugin';


	var $sympa_config_dir = '/etc/sympa';
	var $sympa_expldir_dir = '/var/lib/sympa/list_data';

	//* This function is called during ispconfig installation to determine
	//  if a symlink shall be created for this plugin.
	function onInstall() {
		global $conf;

		if($conf['services']['mail'] == true) {
			return true;
		} else {
			return false;
		}

	}

	/*
	 	This function is called when the plugin is loaded
	*/

	function onLoad() {
		global $app;

		/*
		Register for the events
		*/

		$app->plugins->registerEvent('mail_mailinglist_insert', 'sympa_plugin', 'insert');
		$app->plugins->registerEvent('mail_mailinglist_update', 'sympa_plugin', 'update');
		$app->plugins->registerEvent('mail_mailinglist_delete', 'sympa_plugin', 'delete');

	}

	function insert($event_name, $data) {
		global $app, $conf;

		$this->update_config();

		// Generate a config File
		if(file_exists($conf["rootpath"]."/conf-custom/sympa_list_creation.xml.master")) {
			$content = file_get_contents($conf["rootpath"]."/conf-custom/sympa_list_creation.xml.master");
		} else {
			$content = file_get_contents($conf["rootpath"]."/conf/sympa_list_creation.xml.master");
		}

		$content = str_replace('{listname}', $data["new"]["listname"], $content);
		$content = str_replace('{domain}', $data["new"]["domain"], $content);
		$content = str_replace('{email}', $data["new"]["email"], $content);

		$filename = '/tmp/sympa_list_creation'.$data["new"]['mailinglist_id'].'.xml';

		file_put_contents($filename, $content);

		$pid = $app->system->exec_safe("nohup /usr/bin/sympa --create_list --robot ? --input_file ? >/dev/null 2>&1 & echo $!;", $data["new"]["domain"], $filename);
		$running = true;
		do {
			exec('ps -p '.intval($pid), $out);
			if (count($out) ==1) $running=false; else sleep(1);
			unset($out);
		} while ($running);
		unset($out);
	
		if(is_file('/etc/sympa/virtual.sympa')) exec('postmap /etc/sympa/virtual.sympa');
		if(is_file('/etc/sympa/sympa_transport')) exec('postmap /etc/sympa/sympa_transport');
		
		exec('nohup '.$conf['init_scripts'] . '/' . 'sympa reload >/dev/null 2>&1 &');

		$app->db->query("UPDATE mail_mailinglist SET password = '' WHERE mailinglist_id = ?", $data["new"]['mailinglist_id']);

	}

	// The purpose of this plugin is to rewrite the main.cf file
	function update($event_name, $data) {
		global $app, $conf;
		
		$this->update_config();

		if($data["new"]["password"] != $data["old"]["password"] && $data["new"]["password"] != '') {
			// Password not used in Sympa, no action needed
			$app->db->query("UPDATE mail_mailinglist SET password = '' WHERE mailinglist_id = ?", $data["new"]['mailinglist_id']);
		}
	}

	function delete($event_name, $data) {
		global $app, $conf;

		$this->update_config();

		$app->system->exec_safe("nohup /usr/bin/sympa --close_list=? >/dev/null 2>&1 &", $data["old"]["listname"].'@'.$data["old"]["domain"]);

		exec('nohup '.$conf['init_scripts'] . '/' . 'sympa reload >/dev/null 2>&1 &');
	}

	function update_config() {
		global $app, $conf;

		// create virtual_domains list
		$domainAll = $app->db->queryAllRecords("SELECT domain FROM mail_mailinglist GROUP BY domain");
		$virtual_domains = '';
		foreach($domainAll as $domain)
		{
			if ($domainAll[0]['domain'] == $domain['domain'])
				$virtual_domains .= "'".$domain['domain']."'";
			else
				$virtual_domains .= ", '".$domain['domain']."'";
			
			// create the domain https://github.com/sympa-community/sympa-community.github.io/blob/master/manual/install/configure-mail-server-postfix.md#adding-new-domain
			if(!is_dir($this->sympa_config_dir.'/'.$domain['domain'])) mkdir($this->sympa_config_dir.'/'.$domain['domain'], 0755);
			chown($this->sympa_config_dir.'/'.$domain['domain'], 'sympa');
			chgrp($this->sympa_config_dir.'/'.$domain['domain'], 'sympa');
			
			/* If we need custom variable per domain, might be good for the lang
			if(is_dir($this->sympa_config_dir.'/'.$domain['domain'])) {
				if(is_file($conf['ispconfig_install_dir'].'/server/conf-custom/install/sympa.robot.conf.master')) {
					copy($conf['ispconfig_install_dir'].'/server/conf-custom/install/sympa.robot.conf.master', $full_file_name);
				} else {
					copy('tpl/mailman-virtual_to_transport.sh', $this->sympa_config_dir.'/'.$domain['domain'].'/robot.conf');
				}
				chgrp($full_file_name, $this->mailman_group);
				chmod($full_file_name, 0755);
			}*/

			if(!is_file($this->sympa_config_dir.'/'.$domain['domain'].'/robot.conf')) touch($this->sympa_config_dir.'/'.$domain['domain'].'/robot.conf');
			chown($this->sympa_config_dir.'/'.$domain['domain'].'/robot.conf', 'sympa');
			chgrp($this->sympa_config_dir.'/'.$domain['domain'].'/robot.conf', 'sympa');

			//* Configure transport.sympa and add aliases
			$content_transport = $this->rf($this->sympa_config_dir.'/transport.sympa');
			if(strpos($content_transport, 'sympa@'.$domain['domain']) === false){
				$this->af($this->sympa_config_dir.'/transport.sympa', "sympa@".$domain['domain']."          sympa:sympa@".$domain['domain']."\n");
			}
			if(strpos($content_transport, 'listmaster@'.$domain['domain']) === false){
				$this->af($this->sympa_config_dir.'/transport.sympa', "listmaster@".$domain['domain']."     sympa:listmaster@".$domain['domain']."\n");
			}
			if(strpos($content_transport, 'bounce@'.$domain['domain']) === false){
				$this->af($this->sympa_config_dir.'/transport.sympa', "bounce@".$domain['domain']."        sympabounce:sympa@".$domain['domain']."\n");
			}
			if(strpos($content_transport, 'abuse-feedback-report@'.$domain['domain']) === false){
				$this->af($this->sympa_config_dir.'/transport.sympa', "abuse-feedback-report@".$domain['domain']."  sympabounce:sympa@".$domain['domain']."\n");
			}
			unset($content_transport);

			//* Configure virtual.sympa and add aliases
			$content_virtual = $this->rf($this->sympa_config_dir.'/virtual.sympa');
			if(strpos($content_virtual , 'sympa-request@'.$domain['domain']) === false){
				$this->af($this->sympa_config_dir.'/virtual.sympa', "sympa-request@".$domain['domain']."  postmaster@".$domain['domain']."\n");
			}
			if(strpos($content_virtual , 'sympa-owner@'.$domain['domain']) === false){
				$this->af($this->sympa_config_dir.'/virtual.sympa', "sympa-owner@".$domain['domain']."  postmaster@".$domain['domain']."\n");
			}
			unset($content_virtual);

			if(!is_dir($this->sympa_expldir_dir.'/'.$domain['domain'])) mkdir($this->sympa_expldir_dir.'/'.$domain['domain'], 0750);
			chown($this->sympa_expldir_dir.'/'.$domain['domain'], 'sympa');
			chgrp($this->sympa_expldir_dir.'/'.$domain['domain'], 'sympa');
		}

		if(is_file($this->sympa_config_dir.'/virtual.sympa')) exec('postmap '.$this->sympa_config_dir.'/virtual.sympa');
		if(is_file($this->sympa_config_dir.'/transport.sympa')) exec('postmap '.$this->sympa_config_dir.'/transport.sympa');

		exec('nohup '.$conf['init_scripts'] . '/' . 'sympa reload >/dev/null 2>&1 &');
	}

	// TODO:
	// If someone has a better idea than redefining this functions
	function rf($file){
		global $app;
		clearstatcache();
		if(!$fp = fopen($file, 'rb')){
			$app->log('WARNING: Could not open file '.$file, 2);
			return false;
		} else {
			if(filesize($file) > 0){
				$content = fread($fp, filesize($file));
			} else {
				$content = '';
			}
			fclose($fp);
			return $content;
		}
	}

	function mkdirs($strPath, $mode = '0755'){
		if(isset($strPath) && $strPath != ''){
			//* Verzeichnisse rekursiv erzeugen
			if(is_dir($strPath)){
				return true;
			}
			$pStrPath = dirname($strPath);
			if(!mkdirs($pStrPath, $mode)){
				return false;
			}
			$old_umask = umask(0);
			$ret_val = mkdir($strPath, octdec($mode));
			umask($old_umask);
			return $ret_val;
		}
		return false;
	}
	function wf($file, $content){
		global $app;
		$this->mkdirs(dirname($file));
		if(!$fp = fopen($file, 'wb')){
			$app->log('WARNING: Could not open file '.$file, 2);
			return false;
		} else {
			fwrite($fp, $content);
			fclose($fp);
			return true;
		}
	}

	function af($file, $content){
		global $app;
		$this->mkdirs(dirname($file));
		if(!$fp = fopen($file, 'ab')){
			$app->log('WARNING: Could not open file '.$file, 2);
			return false;
		} else {
			fwrite($fp, $content);
			fclose($fp);
			return true;
		}
	}

} // end class

?>
