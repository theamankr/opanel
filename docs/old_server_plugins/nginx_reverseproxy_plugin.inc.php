<?php

/**
 * ISPConfig Nginx Reverse Proxy Plugin.
 *
 * This class extends ISPConfig's vhost management with the functionality to run
 * Nginx in front of Apache2 as a transparent reverse proxy.
 *
 * @author Adam Biciste <adam@freshost.cz>
 */
class nginx_reverseproxy_plugin {

	/**
	 * Stores the internal plugin name.
	 *
	 * @var string
	 */
	var $plugin_name = 'nginx_reverseproxy_plugin';

	/**
	 * Stores the internal class name.
	 *
	 * Needs to be the same as $plugin_name.
	 *
	 * @var string
	 */
	var $class_name = 'nginx_reverseproxy_plugin';

	/**
	 * Stores the current vhost action.
	 *
	 * When ISPConfig triggers the vhost event, it passes either create,update,delete etc.
	 *
	 * @see onLoad()
	 *
	 * @var string
	 */
	var $action = '';


	/**
	 * ISPConfig onInstall hook.
	 *
	 * Called during ISPConfig installation to determine if a symlink shall be created.
	 *
	 * @return bool create symlink if true
	 */
	function onInstall() {
		global $conf;
		return $conf['services']['web'] == true;
	}

	/**
	 * ISPConfig onLoad hook.
	 *
	 * Register the plugin for some site related events.
	 */
	function onLoad() {
		global $app;

		$app->plugins->registerEvent('web_domain_insert', $this->plugin_name, 'insert');
		$app->plugins->registerEvent('web_domain_update', $this->plugin_name, 'update');
		$app->plugins->registerEvent('web_domain_delete', $this->plugin_name, 'delete');
	}

	/**
	 * ISPConfig insert hook.
	 *
	 * Called every time a new site is created.
	 *
	 * @uses update()
	 *
	 * @param string $event_name the event/action name
	 * @param array  $data       the vhost data
	 */
	function insert($event_name, $data)	{
		global $app, $conf;

		$this->action = 'insert';
		$this->update($event_name, $data);
	}

	/**
	 * ISPConfig update hook.
	 *
	 * Called every time a site gets updated from within ISPConfig.
	 *
	 * @see insert()
	 * @see delete()
	 *
	 * @param string $event_name the event/action name
	 * @param array  $data       the vhost data
	 */
	function update($event_name, $data)	{
		global $app, $conf;

		if($this->action != 'insert') $this->action = 'update';

		if($data['new']['type'] != 'vhost' && $data['new']['type'] != 'vhostsubdomain' && $data['new']['type'] != 'vhostalias' && $data['new']['parent_domain_id'] > 0) {

			$old_parent_domain_id = intval($data['old']['parent_domain_id']);
			$new_parent_domain_id = intval($data['new']['parent_domain_id']);

			// If the parent_domain_id has been changed, we will have to update the old site as well.
			if($this->action == 'update' && $data['new']['parent_domain_id'] != $data['old']['parent_domain_id']) {
				$tmp = $app->db->queryOneRecord('SELECT * FROM web_domain WHERE domain_id = ? AND active = ?', $old_parent_domain_id, 'y');
				$data['new'] = $tmp;
				$data['old'] = $tmp;
				$this->action = 'update';
				$this->update($event_name, $data);
			}

			// This is not a vhost, so we need to update the parent record instead.
			$tmp = $app->db->queryOneRecord('SELECT * FROM web_domain WHERE domain_id = ? AND active = ?', $new_parent_domain_id, 'y');
			$data['new'] = $tmp;
			$data['old'] = $tmp;
			$this->action = 'update';
		}

		// load the server configuration options
		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');

		//* Check if nginx is using a chrooted setup
		if($web_config['website_basedir'] != '' && @is_file($web_config['website_basedir'].'/etc/passwd')) {
			$nginx_chrooted = true;
			$app->log('Info: nginx is chrooted.', LOGLEVEL_DEBUG);
		} else {
			$nginx_chrooted = false;
		}

		if($data['new']['document_root'] == '') {
			if($data['new']['type'] == 'vhost' || $data['new']['type'] == 'vhostsubdomain' || $data['new']['type'] == 'vhostalias') $app->log('document_root not set', LOGLEVEL_WARN);
			return 0;
		}
		if($app->system->is_allowed_user($data['new']['system_user'], $app->system->is_user($data['new']['system_user']), true) == false
			|| $app->system->is_allowed_group($data['new']['system_group'], $app->system->is_group($data['new']['system_group']), true) == false) {
			$app->log('Problem with website user or group.  Websites cannot be owned by root or an existing user/group. User: '.$data['new']['system_user'].' Group: '.$data['new']['system_group'], LOGLEVEL_WARN);
			return 0;
		}
		if(trim($data['new']['domain']) == '') {
			$app->log('domain is empty', LOGLEVEL_WARN);
			return 0;
		}

		$web_folder = 'web';
		$log_folder = 'log';
		$old_web_folder = 'web';
		$old_log_folder = 'log';
		if($data['new']['type'] == 'vhost'){
			if($data['new']['web_folder'] != ''){
				if(substr($data['new']['web_folder'],0,1) == '/') $data['new']['web_folder'] = substr($data['new']['web_folder'],1);
				if(substr($data['new']['web_folder'],-1) == '/') $data['new']['web_folder'] = substr($data['new']['web_folder'],0,-1);
			}
			$web_folder .= '/'.$data['new']['web_folder'];

			if($data['old']['web_folder'] != ''){
				if(substr($data['old']['web_folder'],0,1) == '/') $data['old']['web_folder'] = substr($data['old']['web_folder'],1);
				if(substr($data['old']['web_folder'],-1) == '/') $data['old']['web_folder'] = substr($data['old']['web_folder'],0,-1);
			}
			$old_web_folder .= '/'.$data['old']['web_folder'];
		}
		if($data['new']['type'] == 'vhostsubdomain' || $data['new']['type'] == 'vhostalias') {
			// new one
			$tmp = $app->db->queryOneRecord('SELECT `domain` FROM web_domain WHERE domain_id = ?', $data['new']['parent_domain_id']);
			$subdomain_host = preg_replace('/^(.*)\.' . preg_quote($tmp['domain'], '/') . '$/', '$1', $data['new']['domain']);
			if($subdomain_host == '') $subdomain_host = 'web'.$data['new']['domain_id'];
			$web_folder = $data['new']['web_folder'];
			$log_folder .= '/' . $subdomain_host;
			unset($tmp);

			if($app->system->is_blacklisted_web_path($web_folder)) {
				$app->log('Vhost ' . $subdomain_host . ' is using a blacklisted web folder: ' . $web_folder, LOGLEVEL_ERROR);
				return 0;
			}

			if(isset($data['old']['parent_domain_id'])) {
				// old one
				$tmp = $app->db->queryOneRecord('SELECT `domain` FROM web_domain WHERE domain_id = ?', $data['old']['parent_domain_id']);
				$subdomain_host = preg_replace('/^(.*)\.' . preg_quote($tmp['domain'], '/') . '$/', '$1', $data['old']['domain']);
				if($subdomain_host == '') $subdomain_host = 'web'.$data['old']['domain_id'];
				$old_web_folder = $data['old']['web_folder'];
				$old_log_folder .= '/' . $subdomain_host;
				unset($tmp);
			}
		}

		//* Create the vhost config file
		$app->load('tpl');

		$tpl = new tpl();
		$tpl->newTemplate('nginx_reverseproxy_vhost.conf.master');

		// IPv4
		if($data['new']['ip_address'] == '') $data['new']['ip_address'] = '*';

		//* use ip-mapping for web-mirror
		if($data['new']['ip_address'] != '*' && $conf['mirror_server_id'] > 0) {
			$sql = "SELECT destination_ip FROM server_ip_map WHERE server_id = ? AND source_ip = ?";
			$newip = $app->db->queryOneRecord($sql, $conf['server_id'], $data['new']['ip_address']);
			$data['new']['ip_address'] = $newip['destination_ip'];
			unset($newip);
		}

		$vhost_data = $data['new'];

		//unset($vhost_data['ip_address']);
		$vhost_data['web_document_root'] = $data['new']['document_root'].'/' . $web_folder;
		$vhost_data['web_document_root_www'] = $web_config['website_basedir'].'/'.$data['new']['domain'].'/' . $web_folder;
		$vhost_data['web_basedir'] = $web_config['website_basedir'];

		// IPv6
		if($data['new']['ipv6_address'] != ''){
			$tpl->setVar('ipv6_enabled', 1);
			if ($conf['serverconfig']['web']['vhost_rewrite_v6'] == 'y') {
				if (isset($conf['serverconfig']['server']['v6_prefix']) && $conf['serverconfig']['server']['v6_prefix'] <> '') {
					$explode_v6prefix=explode(':', $conf['serverconfig']['server']['v6_prefix']);
					$explode_v6=explode(':', $data['new']['ipv6_address']);

					for ( $i = 0; $i <= count($explode_v6prefix)-1; $i++ ) {
						$explode_v6[$i] = $explode_v6prefix[$i];
					}
					$data['new']['ipv6_address'] = implode(':', $explode_v6);
					$vhost_data['ipv6_address'] = $data['new']['ipv6_address'];
				}
			}
		}
		if($data['new']['ip_address'] == '*' && $data['new']['ipv6_address'] == '') $tpl->setVar('ipv6_wildcard', 1);

		// Custom rewrite rules
		/*
		$final_rewrite_rules = array();
		$custom_rewrite_rules = $data['new']['rewrite_rules'];
		// Make sure we only have Unix linebreaks
		$custom_rewrite_rules = str_replace("\r\n", "\n", $custom_rewrite_rules);
		$custom_rewrite_rules = str_replace("\r", "\n", $custom_rewrite_rules);
		$custom_rewrite_rule_lines = explode("\n", $custom_rewrite_rules);
		if(is_array($custom_rewrite_rule_lines) && !empty($custom_rewrite_rule_lines)){
			foreach($custom_rewrite_rule_lines as $custom_rewrite_rule_line){
				$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
			}
		}
		$tpl->setLoop('rewrite_rules', $final_rewrite_rules);
		*/

		// Custom rewrite rules
		$final_rewrite_rules = array();

		if(isset($data['new']['rewrite_rules']) && trim($data['new']['rewrite_rules']) != '') {
			$custom_rewrite_rules = trim($data['new']['rewrite_rules']);
			$custom_rewrites_are_valid = true;
			// use this counter to make sure all curly brackets are properly closed
			$if_level = 0;
			// Make sure we only have Unix linebreaks
			$custom_rewrite_rules = str_replace("\r\n", "\n", $custom_rewrite_rules);
			$custom_rewrite_rules = str_replace("\r", "\n", $custom_rewrite_rules);
			$custom_rewrite_rule_lines = explode("\n", $custom_rewrite_rules);
			if(is_array($custom_rewrite_rule_lines) && !empty($custom_rewrite_rule_lines)){
				foreach($custom_rewrite_rule_lines as $custom_rewrite_rule_line){
					// ignore comments
					if(substr(ltrim($custom_rewrite_rule_line), 0, 1) == '#'){
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						continue;
					}
					// empty lines
					if(trim($custom_rewrite_rule_line) == ''){
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						continue;
					}
					// rewrite
					if(preg_match('@^\s*rewrite\s+(^/)?\S+(\$)?\s+\S+(\s+(last|break|redirect|permanent|))?\s*;\s*$@', $custom_rewrite_rule_line)){
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						continue;
					}
					if(preg_match('@^\s*rewrite\s+(^/)?(\'[^\']+\'|"[^"]+")+(\$)?\s+(\'[^\']+\'|"[^"]+")+(\s+(last|break|redirect|permanent|))?\s*;\s*$@', $custom_rewrite_rule_line)){
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						continue;
					}
					if(preg_match('@^\s*rewrite\s+(^/)?(\'[^\']+\'|"[^"]+")+(\$)?\s+\S+(\s+(last|break|redirect|permanent|))?\s*;\s*$@', $custom_rewrite_rule_line)){
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						continue;
					}
					if(preg_match('@^\s*rewrite\s+(^/)?\S+(\$)?\s+(\'[^\']+\'|"[^"]+")+(\s+(last|break|redirect|permanent|))?\s*;\s*$@', $custom_rewrite_rule_line)){
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						continue;
					}
					// if
					if(preg_match('@^\s*if\s+\(\s*\$\S+(\s+(\!?(=|~|~\*))\s+(\S+|\".+\"))?\s*\)\s*\{\s*$@', $custom_rewrite_rule_line)){
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						$if_level += 1;
						continue;
					}
					// if - check for files, directories, etc.
					if(preg_match('@^\s*if\s+\(\s*\!?-(f|d|e|x)\s+\S+\s*\)\s*\{\s*$@', $custom_rewrite_rule_line)){
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						$if_level += 1;
						continue;
					}
					// break
					if(preg_match('@^\s*break\s*;\s*$@', $custom_rewrite_rule_line)){
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						continue;
					}
					// return code [ text ]
					if(preg_match('@^\s*return\s+\d\d\d.*;\s*$@', $custom_rewrite_rule_line)){
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						continue;
					}
					// return code URL
					// return URL
					if(preg_match('@^\s*return(\s+\d\d\d)?\s+(http|https|ftp)\://([a-zA-Z0-9\.\-]+(\:[a-zA-Z0-9\.&%\$\-]+)*\@)*((25[0-5]|2[0-4][0-9]|[0-1]{1}[0-9]{2}|[1-9]{1}[0-9]{1}|[1-9])\.(25[0-5]|2[0-4][0-9]|[0-1]{1}[0-9]{2}|[1-9]{1}[0-9]{1}|[1-9]|0)\.(25[0-5]|2[0-4][0-9]|[0-1]{1}[0-9]{2}|[1-9]{1}[0-9]{1}|[1-9]|0)\.(25[0-5]|2[0-4][0-9]|[0-1]{1}[0-9]{2}|[1-9]{1}[0-9]{1}|[0-9])|localhost|([a-zA-Z0-9\-]+\.)*[a-zA-Z0-9\-]+\.(com|edu|gov|int|mil|net|org|biz|arpa|info|name|pro|aero|coop|museum|[a-zA-Z]{2}))(\:[0-9]+)*(/($|[a-zA-Z0-9\.\,\?\'\\\+&%\$#\=~_\-]+))*\s*;\s*$@', $custom_rewrite_rule_line)){
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						continue;
					}
					// set
					if(preg_match('@^\s*set\s+\$\S+\s+\S+\s*;\s*$@', $custom_rewrite_rule_line)){
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						continue;
					}
					// closing curly bracket
					if(trim($custom_rewrite_rule_line) == '}'){
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						$if_level -= 1;
						continue;
					}
					$custom_rewrites_are_valid = false;
					break;
				}
			}
			if(!$custom_rewrites_are_valid || $if_level != 0){
				$final_rewrite_rules = array();
			}
		}
		$tpl->setLoop('rewrite_rules', $final_rewrite_rules);

		// Custom nginx directives
		$final_nginx_directives = array();
		if($data['new']['enable_pagespeed'] == 'y'){
			// if PageSpeed is already enabled, don't add configuration again
			if(stripos($nginx_directives, 'pagespeed') !== false){
				$vhost_data['enable_pagespeed'] = false;
			} else {
				$vhost_data['enable_pagespeed'] = true;
			}
		} else {
			$vhost_data['enable_pagespeed'] = false;
		}
		if(intval($data['new']['directive_snippets_id']) > 0){
			$snippet = $app->db->queryOneRecord("SELECT * FROM directive_snippets WHERE directive_snippets_id = ? AND type = 'nginx' AND active = 'y' AND customer_viewable = 'y'", $data['new']['directive_snippets_id']);
			if(isset($snippet['snippet'])){
				$nginx_directives = $snippet['snippet'];
			} else {
				$nginx_directives = $data['new']['nginx_directives'];
			}
/*
			if($data['new']['enable_pagespeed'] == 'y'){
				// if PageSpeed is already enabled, don't add configuration again
				if(stripos($nginx_directives, 'pagespeed') !== false){
					$vhost_data['enable_pagespeed'] = false;
				} else {
					$vhost_data['enable_pagespeed'] = true;
				}
			} else {
				$vhost_data['enable_pagespeed'] = false;
			}
*/
		} else {
			$nginx_directives = $data['new']['nginx_directives'];
//			$vhost_data['enable_pagespeed'] = false;
		}
		if(!$nginx_directives) {
			$nginx_directives = ''; // ensure it is not null
		}

		// folder_directive_snippets
		if(trim($data['new']['folder_directive_snippets']) != ''){
			$data['new']['folder_directive_snippets'] = trim($data['new']['folder_directive_snippets']);
			$data['new']['folder_directive_snippets'] = str_replace("\r\n", "\n", $data['new']['folder_directive_snippets']);
			$data['new']['folder_directive_snippets'] = str_replace("\r", "\n", $data['new']['folder_directive_snippets']);
			$folder_directive_snippets_lines = explode("\n", $data['new']['folder_directive_snippets']);

			if(is_array($folder_directive_snippets_lines) && !empty($folder_directive_snippets_lines)){
				foreach($folder_directive_snippets_lines as $folder_directive_snippets_line){
					list($folder_directive_snippets_folder, $folder_directive_snippets_snippets_id) = explode(':', $folder_directive_snippets_line);

					$folder_directive_snippets_folder = trim($folder_directive_snippets_folder);
					$folder_directive_snippets_snippets_id = trim($folder_directive_snippets_snippets_id);

					if($folder_directive_snippets_folder  != '' && intval($folder_directive_snippets_snippets_id) > 0 && preg_match('@^((?!(.*\.\.)|(.*\./)|(.*//))[^/][\w/_\.\-]{1,100})?$@', $folder_directive_snippets_folder)){
						if(substr($folder_directive_snippets_folder, -1) != '/') $folder_directive_snippets_folder .= '/';
						if(substr($folder_directive_snippets_folder, 0, 1) == '/') $folder_directive_snippets_folder = substr($folder_directive_snippets_folder, 1);

						$master_snippet = $app->db->queryOneRecord("SELECT * FROM directive_snippets WHERE directive_snippets_id = ? AND type = 'nginx' AND active = 'y' AND customer_viewable = 'y'", intval($folder_directive_snippets_snippets_id));
						if(isset($master_snippet['snippet'])){
							$folder_directive_snippets_trans = array('{FOLDER}' => $folder_directive_snippets_folder, '{FOLDERMD5}' => md5($folder_directive_snippets_folder));
							$master_snippet['snippet'] = strtr($master_snippet['snippet'], $folder_directive_snippets_trans);
							$nginx_directives .= "\n\n".$master_snippet['snippet'];
						}
					}
				}
			}
		}

		// use vLib for template logic
		if(trim($nginx_directives) != '') {
			$nginx_directives_new = '';
			$ngx_conf_tpl = new tpl();
			$ngx_conf_tpl_tmp_file = tempnam($conf['temppath'], "ngx");
			file_put_contents($ngx_conf_tpl_tmp_file, $nginx_directives);
			$ngx_conf_tpl->newTemplate($ngx_conf_tpl_tmp_file);
			$ngx_conf_tpl->setVar($vhost_data);
			$nginx_directives_new = $ngx_conf_tpl->grab();
			if(is_file($ngx_conf_tpl_tmp_file)) unlink($ngx_conf_tpl_tmp_file);
			if($nginx_directives_new != '') $nginx_directives = $nginx_directives_new;
			unset($nginx_directives_new);
		}

		// Make sure we only have Unix linebreaks
		$nginx_directives = str_replace("\r\n", "\n", $nginx_directives);
		$nginx_directives = str_replace("\r", "\n", $nginx_directives);
		$nginx_directive_lines = explode("\n", $nginx_directives);
		if(is_array($nginx_directive_lines) && !empty($nginx_directive_lines)){
			$trans = array(
				'{DOCROOT}' => $vhost_data['web_document_root_www'],
				'{DOCROOT_CLIENT}' => $vhost_data['web_document_root'],
        		'{DOMAIN}' => $vhost_data['domain']
			);
			foreach($nginx_directive_lines as $nginx_directive_line){
				$final_nginx_directives[] = array('nginx_directive' => strtr($nginx_directive_line, $trans));
			}
		}
		$tpl->setLoop('nginx_directives', $final_nginx_directives);

		$app->uses('letsencrypt');
		// Check if a SSL cert exists
		$tmp = $app->letsencrypt->get_website_certificate_paths($data);
		$domain = $tmp['domain'];
		$key_file = $tmp['key'];
		$csr_file = $tmp['csr'];
		$crt_file = $tmp['crt'];
		$bundle_file = $tmp['bundle'];
		unset($tmp);

		$data['new']['ssl_domain'] = $domain;
		$vhost_data['ssl_domain'] = $domain;
		$vhost_data['ssl_crt_file'] = $crt_file;
		$vhost_data['ssl_key_file'] = $key_file;
		$vhost_data['ssl_bundle_file'] = $bundle_file;

		if($domain!='' && $data['new']['ssl'] == 'y' && @is_file($crt_file) && @is_file($key_file) && (@filesize($crt_file)>0)  && (@filesize($key_file)>0)) {
			$vhost_data['ssl_enabled'] = 1;
			$app->log('Enable SSL for: '.$domain, LOGLEVEL_DEBUG);
		} else {
			$vhost_data['ssl_enabled'] = 0;
			$app->log('SSL Disabled. '.$domain, LOGLEVEL_DEBUG);
		}

		// Set SEO Redirect
		if($data['new']['seo_redirect'] != ''){
			$vhost_data['seo_redirect_enabled'] = 1;
			$tmp_seo_redirects = $this->get_seo_redirects($data['new']);
			if(is_array($tmp_seo_redirects) && !empty($tmp_seo_redirects)){
				foreach($tmp_seo_redirects as $key => $val){
					$vhost_data[$key] = $val;
				}
			} else {
				$vhost_data['seo_redirect_enabled'] = 0;
			}
		} else {
			$vhost_data['seo_redirect_enabled'] = 0;
		}

		// Rewrite rules
		$own_rewrite_rules = array();
		$rewrite_rules = array();
		$local_rewrite_rules = array();
		if($data['new']['redirect_type'] != '' && $data['new']['redirect_path'] != '') {
			if(substr($data['new']['redirect_path'], -1) != '/') $data['new']['redirect_path'] .= '/';
			if(substr($data['new']['redirect_path'], 0, 8) == '[scheme]'){
				if($data['new']['redirect_type'] != 'proxy'){
					$data['new']['redirect_path'] = '$scheme'.substr($data['new']['redirect_path'], 8);
				} else {
					$data['new']['redirect_path'] = 'http'.substr($data['new']['redirect_path'], 8);
				}
			}

			// Custom proxy directives
			if($data['new']['redirect_type'] == 'proxy' && trim($data['new']['proxy_directives'] != '')){
				$final_proxy_directives = array();
				$proxy_directives = $data['new']['proxy_directives'];
				// Make sure we only have Unix linebreaks
				$proxy_directives = str_replace("\r\n", "\n", $proxy_directives);
				$proxy_directives = str_replace("\r", "\n", $proxy_directives);
				$proxy_directive_lines = explode("\n", $proxy_directives);
				if(is_array($proxy_directive_lines) && !empty($proxy_directive_lines)){
					foreach($proxy_directive_lines as $proxy_directive_line){
						$final_proxy_directives[] = array('proxy_directive' => $proxy_directive_line);
					}
				}
			} else {
				$final_proxy_directives = false;
			}

			switch($data['new']['subdomain']) {
			case 'www':
				$exclude_own_hostname = '';
				if(substr($data['new']['redirect_path'], 0, 1) == '/'){ // relative path
					if($data['new']['redirect_type'] == 'proxy'){
						$vhost_data['web_document_root_www_proxy'] = 'root '.$vhost_data['web_document_root_www'].';';
						$vhost_data['web_document_root_www'] .= substr($data['new']['redirect_path'], 0, -1);
						break;
					}
					$rewrite_exclude = '(?!/('.substr($data['new']['redirect_path'], 1, -1).(substr($data['new']['redirect_path'], 1, -1) != ''? '|': '').'stats'.($vhost_data['errordocs'] == 1 ? '|error' : '').'|\.well-known/acme-challenge))/';
				} else { // URL - check if URL is local
					$tmp_redirect_path = $data['new']['redirect_path'];
					if(substr($tmp_redirect_path, 0, 7) == '$scheme') $tmp_redirect_path = 'http'.substr($tmp_redirect_path, 7);
					$tmp_redirect_path_parts = parse_url($tmp_redirect_path);
					if(($tmp_redirect_path_parts['host'] == $data['new']['domain'] || $tmp_redirect_path_parts['host'] == 'www.'.$data['new']['domain']) && ($tmp_redirect_path_parts['port'] == '80' || $tmp_redirect_path_parts['port'] == '443' || !isset($tmp_redirect_path_parts['port']))){
						// URL is local
						if(substr($tmp_redirect_path_parts['path'], -1) == '/') $tmp_redirect_path_parts['path'] = substr($tmp_redirect_path_parts['path'], 0, -1);
						if(substr($tmp_redirect_path_parts['path'], 0, 1) != '/') $tmp_redirect_path_parts['path'] = '/'.$tmp_redirect_path_parts['path'];
						//$rewrite_exclude = '((?!'.$tmp_redirect_path_parts['path'].'))';
						if($data['new']['redirect_type'] == 'proxy'){
							$vhost_data['web_document_root_www_proxy'] = 'root '.$vhost_data['web_document_root_www'].';';
							$vhost_data['web_document_root_www'] .= $tmp_redirect_path_parts['path'];
							break;
						} else {
							$rewrite_exclude = '(?!/('.substr($tmp_redirect_path_parts['path'], 1).(substr($tmp_redirect_path_parts['path'], 1) != ''? '|': '').'stats'.($vhost_data['errordocs'] == 1 ? '|error' : '').'|\.well-known/acme-challenge))/';
							$exclude_own_hostname = $tmp_redirect_path_parts['host'];
						}
					} else {
						// external URL
						$rewrite_exclude = '(?!/(\.well-known/acme-challenge))/';
						if($data['new']['redirect_type'] == 'proxy'){
							$vhost_data['use_proxy'] = 'y';
							$rewrite_subdir = $tmp_redirect_path_parts['path'];
							if(substr($rewrite_subdir, 0, 1) == '/') $rewrite_subdir = substr($rewrite_subdir, 1);
							if(substr($rewrite_subdir, -1) != '/') $rewrite_subdir .= '/';
							if($rewrite_subdir == '/') $rewrite_subdir = '';
						}
					}
					unset($tmp_redirect_path);
					unset($tmp_redirect_path_parts);
				}
				$own_rewrite_rules[] = array( 'rewrite_domain'  => '^'.$this->_rewrite_quote($data['new']['domain']),
					'rewrite_type'   => ($data['new']['redirect_type'] == 'no')?'':$this->rewrite_flag_apache_to_nginx($data['new']['redirect_type']),
					'rewrite_target'  => $data['new']['redirect_path'],
					'rewrite_exclude' => $rewrite_exclude,
					'rewrite_subdir' => $rewrite_subdir,
					'exclude_own_hostname' => $exclude_own_hostname,
					'proxy_directives' => $final_proxy_directives,
					'use_rewrite' => ($data['new']['redirect_type'] == 'proxy' ? false:true),
					'use_proxy' => ($data['new']['redirect_type'] == 'proxy' ? true:false));
				break;
			case '*':
				$exclude_own_hostname = '';
				if(substr($data['new']['redirect_path'], 0, 1) == '/'){ // relative path
					if($data['new']['redirect_type'] == 'proxy'){
						$vhost_data['web_document_root_www_proxy'] = 'root '.$vhost_data['web_document_root_www'].';';
						$vhost_data['web_document_root_www'] .= substr($data['new']['redirect_path'], 0, -1);
						break;
					}
					$rewrite_exclude = '(?!/('.substr($data['new']['redirect_path'], 1, -1).(substr($data['new']['redirect_path'], 1, -1) != ''? '|': '').'stats'.($vhost_data['errordocs'] == 1 ? '|error' : '').'|\.well-known/acme-challenge))/';
				} else { // URL - check if URL is local
					$tmp_redirect_path = $data['new']['redirect_path'];
					if(substr($tmp_redirect_path, 0, 7) == '$scheme') $tmp_redirect_path = 'http'.substr($tmp_redirect_path, 7);
					$tmp_redirect_path_parts = parse_url($tmp_redirect_path);

					//if($is_serveralias && ($tmp_redirect_path_parts['port'] == '80' || $tmp_redirect_path_parts['port'] == '443' || !isset($tmp_redirect_path_parts['port']))){
					if($this->url_is_local($tmp_redirect_path_parts['host'], $data['new']['domain_id']) && ($tmp_redirect_path_parts['port'] == '80' || $tmp_redirect_path_parts['port'] == '443' || !isset($tmp_redirect_path_parts['port']))){
						// URL is local
						if(substr($tmp_redirect_path_parts['path'], -1) == '/') $tmp_redirect_path_parts['path'] = substr($tmp_redirect_path_parts['path'], 0, -1);
						if(substr($tmp_redirect_path_parts['path'], 0, 1) != '/') $tmp_redirect_path_parts['path'] = '/'.$tmp_redirect_path_parts['path'];
						//$rewrite_exclude = '((?!'.$tmp_redirect_path_parts['path'].'))';
						if($data['new']['redirect_type'] == 'proxy'){
							$vhost_data['web_document_root_www_proxy'] = 'root '.$vhost_data['web_document_root_www'].';';
							$vhost_data['web_document_root_www'] .= $tmp_redirect_path_parts['path'];
							break;
						} else {
							$rewrite_exclude = '(?!/('.substr($tmp_redirect_path_parts['path'], 1).(substr($tmp_redirect_path_parts['path'], 1) != ''? '|': '').'stats'.($vhost_data['errordocs'] == 1 ? '|error' : '').'|\.well-known/acme-challenge))/';
							$exclude_own_hostname = $tmp_redirect_path_parts['host'];
						}
					} else {
						// external URL
						$rewrite_exclude = '(?!/(\.well-known/acme-challenge))/';
						if($data['new']['redirect_type'] == 'proxy'){
							$vhost_data['use_proxy'] = 'y';
							$rewrite_subdir = $tmp_redirect_path_parts['path'];
							if(substr($rewrite_subdir, 0, 1) == '/') $rewrite_subdir = substr($rewrite_subdir, 1);
							if(substr($rewrite_subdir, -1) != '/') $rewrite_subdir .= '/';
							if($rewrite_subdir == '/') $rewrite_subdir = '';
						}
					}
					unset($tmp_redirect_path);
					unset($tmp_redirect_path_parts);
				}
				$own_rewrite_rules[] = array( 'rewrite_domain'  => '(^|\.)'.$this->_rewrite_quote($data['new']['domain']),
					'rewrite_type'   => ($data['new']['redirect_type'] == 'no')?'':$this->rewrite_flag_apache_to_nginx($data['new']['redirect_type']),
					'rewrite_target'  => $data['new']['redirect_path'],
					'rewrite_exclude' => $rewrite_exclude,
					'rewrite_subdir' => $rewrite_subdir,
					'exclude_own_hostname' => $exclude_own_hostname,
					'proxy_directives' => $final_proxy_directives,
					'use_rewrite' => ($data['new']['redirect_type'] == 'proxy' ? false:true),
					'use_proxy' => ($data['new']['redirect_type'] == 'proxy' ? true:false));
				break;
			default:
				if(substr($data['new']['redirect_path'], 0, 1) == '/'){ // relative path
					$exclude_own_hostname = '';
					if($data['new']['redirect_type'] == 'proxy'){
						$vhost_data['web_document_root_www_proxy'] = 'root '.$vhost_data['web_document_root_www'].';';
						$vhost_data['web_document_root_www'] .= substr($data['new']['redirect_path'], 0, -1);
						break;
					}
					$rewrite_exclude = '(?!/('.substr($data['new']['redirect_path'], 1, -1).(substr($data['new']['redirect_path'], 1, -1) != ''? '|': '').'stats'.($vhost_data['errordocs'] == 1 ? '|error' : '').'|\.well-known/acme-challenge))/';
				} else { // URL - check if URL is local
					$tmp_redirect_path = $data['new']['redirect_path'];
					if(substr($tmp_redirect_path, 0, 7) == '$scheme') $tmp_redirect_path = 'http'.substr($tmp_redirect_path, 7);
					$tmp_redirect_path_parts = parse_url($tmp_redirect_path);
					if($tmp_redirect_path_parts['host'] == $data['new']['domain'] && ($tmp_redirect_path_parts['port'] == '80' || $tmp_redirect_path_parts['port'] == '443' || !isset($tmp_redirect_path_parts['port']))){
						// URL is local
						if(substr($tmp_redirect_path_parts['path'], -1) == '/') $tmp_redirect_path_parts['path'] = substr($tmp_redirect_path_parts['path'], 0, -1);
						if(substr($tmp_redirect_path_parts['path'], 0, 1) != '/') $tmp_redirect_path_parts['path'] = '/'.$tmp_redirect_path_parts['path'];
						//$rewrite_exclude = '((?!'.$tmp_redirect_path_parts['path'].'))';
						if($data['new']['redirect_type'] == 'proxy'){
							$vhost_data['web_document_root_www_proxy'] = 'root '.$vhost_data['web_document_root_www'].';';
							$vhost_data['web_document_root_www'] .= $tmp_redirect_path_parts['path'];
							break;
						} else {
							$rewrite_exclude = '(?!/('.substr($tmp_redirect_path_parts['path'], 1).(substr($tmp_redirect_path_parts['path'], 1) != ''? '|': '').'stats'.($vhost_data['errordocs'] == 1 ? '|error' : '').'|\.well-known/acme-challenge))/';
							$exclude_own_hostname = $tmp_redirect_path_parts['host'];
						}
					} else {
						// external URL
						$rewrite_exclude = '(?!/(\.well-known/acme-challenge))/';
						if($data['new']['redirect_type'] == 'proxy'){
							$vhost_data['use_proxy'] = 'y';
							$rewrite_subdir = $tmp_redirect_path_parts['path'];
							if(substr($rewrite_subdir, 0, 1) == '/') $rewrite_subdir = substr($rewrite_subdir, 1);
							if(substr($rewrite_subdir, -1) != '/') $rewrite_subdir .= '/';
							if($rewrite_subdir == '/') $rewrite_subdir = '';
						}
					}
					unset($tmp_redirect_path);
					unset($tmp_redirect_path_parts);
				}
				$own_rewrite_rules[] = array( 'rewrite_domain'  => '^'.$this->_rewrite_quote($data['new']['domain']),
					'rewrite_type'   => ($data['new']['redirect_type'] == 'no')?'':$this->rewrite_flag_apache_to_nginx($data['new']['redirect_type']),
					'rewrite_target'  => $data['new']['redirect_path'],
					'rewrite_exclude' => $rewrite_exclude,
					'rewrite_subdir' => $rewrite_subdir,
					'exclude_own_hostname' => $exclude_own_hostname,
					'proxy_directives' => $final_proxy_directives,
					'use_rewrite' => ($data['new']['redirect_type'] == 'proxy' ? false:true),
					'use_proxy' => ($data['new']['redirect_type'] == 'proxy' ? true:false));
			}
		}

		// set logging variable
		$vhost_data['logging'] = $web_config['logging'];

		// Provide TLS 1.3 support if Nginx version is >= 1.13.0 and when it was linked against OpenSSL(>=1.1.1) at build time and when it was linked against OpenSSL(>=1.1.1) at runtime.
		$nginx_openssl_build_ver = $app->system->exec_safe('nginx -V 2>&1 | grep \'built with OpenSSL\' | sed \'s/.*built\([a-zA-Z ]*\)OpenSSL \([0-9.]*\).*/\2/\'');
		$nginx_openssl_running_ver = $app->system->exec_safe('nginx -V 2>&1 | grep \'running with OpenSSL\' | sed \'s/.*running\([a-zA-Z ]*\)OpenSSL \([0-9.]*\).*/\2/\'');
		if(version_compare($app->system->getnginxversion(true), '1.13.0', '>=')
			&& version_compare($nginx_openssl_build_ver, '1.1.1', '>=')
			&& (empty($nginx_openssl_running_ver) || version_compare($nginx_openssl_running_ver, '1.1.1', '>='))) {
			$app->log('Enable TLS 1.3 for: '.$domain, LOGLEVEL_DEBUG);
			$vhost_data['tls13_supported'] = "y";
		}

		$tpl->setVar($vhost_data);

		$server_alias = array();

		// get autoalias
		$auto_alias = $web_config['website_autoalias'];
		if($auto_alias != '') {
			// get the client username
			$client = $app->db->queryOneRecord("SELECT `username` FROM `client` WHERE `client_id` = ?", $client_id);
			$aa_search = array('[client_id]', '[website_id]', '[client_username]', '[website_domain]');
			$aa_replace = array($client_id, $data['new']['domain_id'], $client['username'], $data['new']['domain']);
			$auto_alias = str_replace($aa_search, $aa_replace, $auto_alias);
			unset($client);
			unset($aa_search);
			unset($aa_replace);
			$server_alias[] .= $auto_alias.' ';
		}

		// get alias domains (co-domains and subdomains)
		$aliases = $app->db->queryAllRecords("SELECT * FROM web_domain WHERE parent_domain_id = ? AND active = 'y' AND (type != 'vhostsubdomain' AND type != 'vhostalias')", $data['new']['domain_id']);
		$alias_seo_redirects = array();
		switch($data['new']['subdomain']) {
		case 'www':
			$server_alias[] = 'www.'.$data['new']['domain'].' ';
			break;
		case '*':
			$server_alias[] = '*.'.$data['new']['domain'].' ';
			break;
		}
		if(is_array($aliases)) {
			foreach($aliases as $alias) {

				// Custom proxy directives
				if($alias['redirect_type'] == 'proxy' && trim($alias['proxy_directives'] != '')){
					$final_proxy_directives = array();
					$proxy_directives = $alias['proxy_directives'];
					// Make sure we only have Unix linebreaks
					$proxy_directives = str_replace("\r\n", "\n", $proxy_directives);
					$proxy_directives = str_replace("\r", "\n", $proxy_directives);
					$proxy_directive_lines = explode("\n", $proxy_directives);
					if(is_array($proxy_directive_lines) && !empty($proxy_directive_lines)){
						foreach($proxy_directive_lines as $proxy_directive_line){
							$final_proxy_directives[] = array('proxy_directive' => $proxy_directive_line);
						}
					}
				} else {
					$final_proxy_directives = false;
				}

				if($alias['redirect_type'] == '' || $alias['redirect_path'] == '' || substr($alias['redirect_path'], 0, 1) == '/') {
					switch($alias['subdomain']) {
					case 'www':
						$server_alias[] = 'www.'.$alias['domain'].' '.$alias['domain'].' ';
						break;
					case '*':
						$server_alias[] = '*.'.$alias['domain'].' '.$alias['domain'].' ';
						break;
					default:
						$server_alias[] = $alias['domain'].' ';
						break;
					}
					$app->log('Add server alias: '.$alias['domain'], LOGLEVEL_DEBUG);

					// Add SEO redirects for alias domains
					if($alias['seo_redirect'] != '' && $data['new']['seo_redirect'] != '*_to_www_domain_tld' && $data['new']['seo_redirect'] != '*_to_domain_tld' && ($alias['type'] == 'alias' || ($alias['type'] == 'subdomain' && $data['new']['seo_redirect'] != '*_domain_tld_to_www_domain_tld' && $data['new']['seo_redirect'] != '*_domain_tld_to_domain_tld'))){
						$tmp_seo_redirects = $this->get_seo_redirects($alias, 'alias_');
						if(is_array($tmp_seo_redirects) && !empty($tmp_seo_redirects)){
							$alias_seo_redirects[] = $tmp_seo_redirects;
						}
					}
				}

				// Local Rewriting (inside vhost server {} container)
				if($alias['redirect_type'] != '' && substr($alias['redirect_path'], 0, 1) == '/' && $alias['redirect_type'] != 'proxy') {  // proxy makes no sense with local path
					if(substr($alias['redirect_path'], -1) != '/') $alias['redirect_path'] .= '/';
					$rewrite_exclude = '(?!/('.substr($alias['redirect_path'], 1, -1).(substr($alias['redirect_path'], 1, -1) != ''? '|': '').'stats'.($vhost_data['errordocs'] == 1 ? '|error' : '').'|\.well-known/acme-challenge))/';
					switch($alias['subdomain']) {
					case 'www':
						// example.com
						$local_rewrite_rules[] = array( 'local_redirect_origin_domain'  => $alias['domain'],
							'local_redirect_operator' => '=',
							'local_redirect_exclude' => $rewrite_exclude,
							'local_redirect_target' => $alias['redirect_path'],
							'local_redirect_type' => ($alias['redirect_type'] == 'no')?'':$this->rewrite_flag_apache_to_nginx($alias['redirect_type']));

						// www.example.com
						$local_rewrite_rules[] = array( 'local_redirect_origin_domain'  => 'www.'.$alias['domain'],
							'local_redirect_operator' => '=',
							'local_redirect_exclude' => $rewrite_exclude,
							'local_redirect_target' => $alias['redirect_path'],
							'local_redirect_type' => ($alias['redirect_type'] == 'no')?'':$this->rewrite_flag_apache_to_nginx($alias['redirect_type']));
						break;
					case '*':
						$local_rewrite_rules[] = array( 'local_redirect_origin_domain'  => '^('.str_replace('.', '\.', $alias['domain']).'|.+\.'.str_replace('.', '\.', $alias['domain']).')$',
							'local_redirect_operator' => '~*',
							'local_redirect_exclude' => $rewrite_exclude,
							'local_redirect_target' => $alias['redirect_path'],
							'local_redirect_type' => ($alias['redirect_type'] == 'no')?'':$this->rewrite_flag_apache_to_nginx($alias['redirect_type']));
						break;
					default:
						$local_rewrite_rules[] = array( 'local_redirect_origin_domain'  => $alias['domain'],
							'local_redirect_operator' => '=',
							'local_redirect_exclude' => $rewrite_exclude,
							'local_redirect_target' => $alias['redirect_path'],
							'local_redirect_type' => ($alias['redirect_type'] == 'no')?'':$this->rewrite_flag_apache_to_nginx($alias['redirect_type']));
					}
				}

				// External Rewriting (extra server {} containers)
				if($alias['redirect_type'] != '' && $alias['redirect_path'] != '' && substr($alias['redirect_path'], 0, 1) != '/') {
					if(substr($alias['redirect_path'], -1) != '/') $alias['redirect_path'] .= '/';
					if(substr($alias['redirect_path'], 0, 8) == '[scheme]'){
						if($alias['redirect_type'] != 'proxy'){
							$alias['redirect_path'] = '$scheme'.substr($alias['redirect_path'], 8);
						} else {
							$alias['redirect_path'] = 'http'.substr($alias['redirect_path'], 8);
						}
					}

					switch($alias['subdomain']) {
					case 'www':
						if($alias['redirect_type'] == 'proxy'){
							$tmp_redirect_path = $alias['redirect_path'];
							$tmp_redirect_path_parts = parse_url($tmp_redirect_path);
							$rewrite_subdir = $tmp_redirect_path_parts['path'];
							if(substr($rewrite_subdir, 0, 1) == '/') $rewrite_subdir = substr($rewrite_subdir, 1);
							if(substr($rewrite_subdir, -1) != '/') $rewrite_subdir .= '/';
							if($rewrite_subdir == '/') $rewrite_subdir = '';
						}

						if($alias['redirect_type'] != 'proxy'){
							if(substr($alias['redirect_path'], -1) == '/') $alias['redirect_path'] = substr($alias['redirect_path'], 0, -1);
						}
						// Add SEO redirects for alias domains
						$alias_seo_redirects2 = array();
						if($alias['seo_redirect'] != ''){
							$tmp_seo_redirects = $this->get_seo_redirects($alias, 'alias_', 'none');
							if(is_array($tmp_seo_redirects) && !empty($tmp_seo_redirects)){
								$alias_seo_redirects2[] = $tmp_seo_redirects;
							}
						}
						$rewrite_rules[] = array( 'rewrite_domain'  => $alias['domain'],
							'rewrite_type'   => ($alias['redirect_type'] == 'no')?'':$this->rewrite_flag_apache_to_nginx($alias['redirect_type']),
							'rewrite_target'  => $alias['redirect_path'],
							'rewrite_subdir' => $rewrite_subdir,
							'proxy_directives' => $final_proxy_directives,
							'use_rewrite' => ($alias['redirect_type'] == 'proxy' ? false:true),
							'use_proxy' => ($alias['redirect_type'] == 'proxy' ? true:false),
							'alias_seo_redirects2' => (count($alias_seo_redirects2) > 0 ? $alias_seo_redirects2 : false));

						// Add SEO redirects for alias domains
						$alias_seo_redirects2 = array();
						if($alias['seo_redirect'] != ''){
							$tmp_seo_redirects = $this->get_seo_redirects($alias, 'alias_', 'www');
							if(is_array($tmp_seo_redirects) && !empty($tmp_seo_redirects)){
								$alias_seo_redirects2[] = $tmp_seo_redirects;
							}
						}
						$rewrite_rules[] = array( 'rewrite_domain'  => 'www.'.$alias['domain'],
							'rewrite_type'   => ($alias['redirect_type'] == 'no')?'':$this->rewrite_flag_apache_to_nginx($alias['redirect_type']),
							'rewrite_target'  => $alias['redirect_path'],
							'rewrite_subdir' => $rewrite_subdir,
							'proxy_directives' => $final_proxy_directives,
							'use_rewrite' => ($alias['redirect_type'] == 'proxy' ? false:true),
							'use_proxy' => ($alias['redirect_type'] == 'proxy' ? true:false),
							'alias_seo_redirects2' => (count($alias_seo_redirects2) > 0 ? $alias_seo_redirects2 : false));
						break;
					case '*':
						if($alias['redirect_type'] == 'proxy'){
							$tmp_redirect_path = $alias['redirect_path'];
							$tmp_redirect_path_parts = parse_url($tmp_redirect_path);
							$rewrite_subdir = $tmp_redirect_path_parts['path'];
							if(substr($rewrite_subdir, 0, 1) == '/') $rewrite_subdir = substr($rewrite_subdir, 1);
							if(substr($rewrite_subdir, -1) != '/') $rewrite_subdir .= '/';
							if($rewrite_subdir == '/') $rewrite_subdir = '';
						}

						if($alias['redirect_type'] != 'proxy'){
							if(substr($alias['redirect_path'], -1) == '/') $alias['redirect_path'] = substr($alias['redirect_path'], 0, -1);
						}
						// Add SEO redirects for alias domains
						$alias_seo_redirects2 = array();
						if($alias['seo_redirect'] != ''){
							$tmp_seo_redirects = $this->get_seo_redirects($alias, 'alias_');
							if(is_array($tmp_seo_redirects) && !empty($tmp_seo_redirects)){
								$alias_seo_redirects2[] = $tmp_seo_redirects;
							}
						}
						$rewrite_rules[] = array( 'rewrite_domain'  => $alias['domain'].' *.'.$alias['domain'],
							'rewrite_type'   => ($alias['redirect_type'] == 'no')?'':$this->rewrite_flag_apache_to_nginx($alias['redirect_type']),
							'rewrite_target'  => $alias['redirect_path'],
							'rewrite_subdir' => $rewrite_subdir,
							'proxy_directives' => $final_proxy_directives,
							'use_rewrite' => ($alias['redirect_type'] == 'proxy' ? false:true),
							'use_proxy' => ($alias['redirect_type'] == 'proxy' ? true:false),
							'alias_seo_redirects2' => (count($alias_seo_redirects2) > 0 ? $alias_seo_redirects2 : false));
						break;
					default:
						if($alias['redirect_type'] == 'proxy'){
							$tmp_redirect_path = $alias['redirect_path'];
							$tmp_redirect_path_parts = parse_url($tmp_redirect_path);
							$rewrite_subdir = $tmp_redirect_path_parts['path'];
							if(substr($rewrite_subdir, 0, 1) == '/') $rewrite_subdir = substr($rewrite_subdir, 1);
							if(substr($rewrite_subdir, -1) != '/') $rewrite_subdir .= '/';
							if($rewrite_subdir == '/') $rewrite_subdir = '';
						}

						if($alias['redirect_type'] != 'proxy'){
							if(substr($alias['redirect_path'], -1) == '/') $alias['redirect_path'] = substr($alias['redirect_path'], 0, -1);
						}
						if(substr($alias['domain'], 0, 2) === '*.') $domain_rule = '*.'.substr($alias['domain'], 2);
						else $domain_rule = $alias['domain'];
						// Add SEO redirects for alias domains
						$alias_seo_redirects2 = array();
						if($alias['seo_redirect'] != ''){
							if(substr($alias['domain'], 0, 2) === '*.'){
								$tmp_seo_redirects = $this->get_seo_redirects($alias, 'alias_');
							} else {
								$tmp_seo_redirects = $this->get_seo_redirects($alias, 'alias_', 'none');
							}
							if(is_array($tmp_seo_redirects) && !empty($tmp_seo_redirects)){
								$alias_seo_redirects2[] = $tmp_seo_redirects;
							}
						}
						$rewrite_rules[] = array( 'rewrite_domain'  => $domain_rule,
							'rewrite_type'   => ($alias['redirect_type'] == 'no')?'':$this->rewrite_flag_apache_to_nginx($alias['redirect_type']),
							'rewrite_target'  => $alias['redirect_path'],
							'rewrite_subdir' => $rewrite_subdir,
							'proxy_directives' => $final_proxy_directives,
							'use_rewrite' => ($alias['redirect_type'] == 'proxy' ? false:true),
							'use_proxy' => ($alias['redirect_type'] == 'proxy' ? true:false),
							'alias_seo_redirects2' => (count($alias_seo_redirects2) > 0 ? $alias_seo_redirects2 : false));
					}
				}
			}
		}

		//* If we have some alias records
		if(count($server_alias) > 0) {
			$server_alias_str = '';
			$n = 0;

			foreach($server_alias as $tmp_alias) {
				$server_alias_str .= $tmp_alias;
			}
			unset($tmp_alias);

			$tpl->setVar('alias', trim($server_alias_str));
		} else {
			$tpl->setVar('alias', '');
		}

		if(count($rewrite_rules) > 0) {
			$tpl->setLoop('redirects', $rewrite_rules);
		}
		if(count($own_rewrite_rules) > 0) {
			$tpl->setLoop('own_redirects', $own_rewrite_rules);
		}
		if(count($local_rewrite_rules) > 0) {
			$tpl->setLoop('local_redirects', $local_rewrite_rules);
		}
		if(count($alias_seo_redirects) > 0) {
			$tpl->setLoop('alias_seo_redirects', $alias_seo_redirects);
		}

		$stats_web_folder = 'web';
		if($data['new']['type'] == 'vhost'){
			if($data['new']['web_folder'] != ''){
				if(substr($data['new']['web_folder'], 0, 1) == '/') $data['new']['web_folder'] = substr($data['new']['web_folder'],1);
				if(substr($data['new']['web_folder'], -1) == '/') $data['new']['web_folder'] = substr($data['new']['web_folder'],0,-1);
			}
			$stats_web_folder .= '/'.$data['new']['web_folder'];
		} elseif($data['new']['type'] == 'vhostsubdomain' || $data['new']['type'] == 'vhostalias') {
			$stats_web_folder = $data['new']['web_folder'];
		}

		//* Create basic http auth for website statistics
		$tpl->setVar('stats_auth_passwd_file', $data['new']['document_root']."/" . $stats_web_folder . "/stats/.htpasswd_stats");

		// Create basic http auth for other directories
		$basic_auth_locations = $this->_create_web_folder_auth_configuration($data['new']);
		if(is_array($basic_auth_locations) && !empty($basic_auth_locations)) $tpl->setLoop('basic_auth_locations', $basic_auth_locations);

		$vhost_file = $web_config['nginx_vhost_conf_dir'].'/'.$data['new']['domain'].'.vhost';
		//* Make a backup copy of vhost file
		if(file_exists($vhost_file)) copy($vhost_file, $vhost_file.'~');

		//* Write vhost file
		$app->system->file_put_contents($vhost_file, $this->nginx_merge_locations($tpl->grab()));
		$app->log('Writing the vhost file: '.$vhost_file, LOGLEVEL_DEBUG);
		unset($tpl);

		//* Set the symlink to enable the vhost
		//* First we check if there is a old type of symlink and remove it
		$vhost_symlink = $web_config['nginx_vhost_conf_enabled_dir'].'/'.$data['new']['domain'].'.vhost';
		if(is_link($vhost_symlink)) $app->system->unlink($vhost_symlink);

		//* Remove old or changed symlinks
		if($data['new']['subdomain'] != $data['old']['subdomain'] or $data['new']['active'] == 'n') {
			$vhost_symlink = $web_config['nginx_vhost_conf_enabled_dir'].'/900-'.$data['new']['domain'].'.vhost';
			if(is_link($vhost_symlink)) {
				$app->system->unlink($vhost_symlink);
				$app->log('Removing symlink: '.$vhost_symlink.'->'.$vhost_file, LOGLEVEL_DEBUG);
			}
			$vhost_symlink = $web_config['nginx_vhost_conf_enabled_dir'].'/100-'.$data['new']['domain'].'.vhost';
			if(is_link($vhost_symlink)) {
				$app->system->unlink($vhost_symlink);
				$app->log('Removing symlink: '.$vhost_symlink.'->'.$vhost_file, LOGLEVEL_DEBUG);
			}
		}

		//* New symlink
		if($data['new']['subdomain'] == '*') {
			$vhost_symlink = $web_config['nginx_vhost_conf_enabled_dir'].'/900-'.$data['new']['domain'].'.vhost';
		} else {
			$vhost_symlink = $web_config['nginx_vhost_conf_enabled_dir'].'/100-'.$data['new']['domain'].'.vhost';
		}
		if($data['new']['active'] == 'y' && !is_link($vhost_symlink)) {
			symlink($vhost_file, $vhost_symlink);
			$app->log('Creating symlink: '.$vhost_symlink.'->'.$vhost_file, LOGLEVEL_DEBUG);
		}

		// remove old symlink and vhost file, if domain name of the site has changed
		if($this->action == 'update' && $data['old']['domain'] != '' && $data['new']['domain'] != $data['old']['domain']) {
			$vhost_symlink = $web_config['nginx_vhost_conf_enabled_dir'].'/900-'.$data['old']['domain'].'.vhost';
			if(is_link($vhost_symlink)) {
				$app->system->unlink($vhost_symlink);
				$app->log('Removing symlink: '.$vhost_symlink.'->'.$vhost_file, LOGLEVEL_DEBUG);
			}
			$vhost_symlink = $web_config['nginx_vhost_conf_enabled_dir'].'/100-'.$data['old']['domain'].'.vhost';
			if(is_link($vhost_symlink)) {
				$app->system->unlink($vhost_symlink);
				$app->log('Removing symlink: '.$vhost_symlink.'->'.$vhost_file, LOGLEVEL_DEBUG);
			}
			$vhost_symlink = $web_config['nginx_vhost_conf_enabled_dir'].'/'.$data['old']['domain'].'.vhost';
			if(is_link($vhost_symlink)) {
				$app->system->unlink($vhost_symlink);
				$app->log('Removing symlink: '.$vhost_symlink.'->'.$vhost_file, LOGLEVEL_DEBUG);
			}
			$vhost_file = $web_config['nginx_vhost_conf_dir'].'/'.$data['old']['domain'].'.vhost';
			$app->system->unlink($vhost_file);
			$app->log('Removing file: '.$vhost_file, LOGLEVEL_DEBUG);
		}

		if($web_config['check_apache_config'] == 'y') {
			//* Test if nginx starts with the new configuration file
			$nginx_online_status_before_restart = $this->_checkTcp('localhost', 80);
			$app->log('nginx status is: '.($nginx_online_status_before_restart === true? 'running' : 'down'), LOGLEVEL_DEBUG);

			$retval = $app->services->restartService('nginx', 'restart'); // $retval['retval'] is 0 on success and > 0 on failure
			$app->log('nginx restart return value is: '.$retval['retval'], LOGLEVEL_DEBUG);

			// wait a few seconds, before we test the nginx status again
			sleep(2);

			//* Check if nginx restarted successfully if it was online before
			$nginx_online_status_after_restart = $this->_checkTcp('localhost', 80);
			$app->log('nginx online status after restart is: '.($nginx_online_status_after_restart === true? 'running' : 'down'), LOGLEVEL_DEBUG);
			if($nginx_online_status_before_restart && !$nginx_online_status_after_restart || $retval['retval'] > 0) {
				$app->log('nginx did not restart after the configuration change for website '.$data['new']['domain'].'. Reverting the configuration. Saved non-working config as '.$vhost_file.'.err', LOGLEVEL_WARN);
				if(is_array($retval['output']) && !empty($retval['output'])){
					$app->log('Reason for nginx restart failure: '.implode("\n", $retval['output']), LOGLEVEL_WARN);
					$app->dbmaster->datalogError(implode("\n", $retval['output']));
				} else {
					// if no output is given, check again
					exec('nginx -t 2>&1', $tmp_output, $tmp_retval);
					if($tmp_retval > 0 && is_array($tmp_output) && !empty($tmp_output)){
						$app->log('Reason for nginx restart failure: '.implode("\n", $tmp_output), LOGLEVEL_WARN);
						$app->dbmaster->datalogError(implode("\n", $tmp_output));
					}
					unset($tmp_output, $tmp_retval);
				}
				$app->system->copy($vhost_file, $vhost_file.'.err');

				if(is_file($vhost_file.'~')) {
					//* Copy back the last backup file
					$app->system->copy($vhost_file.'~', $vhost_file);
				} else {
					//* There is no backup file, so we create a empty vhost file with a warning message inside
					$app->system->file_put_contents($vhost_file, "# nginx did not start after modifying this vhost file.\n# Please check file $vhost_file.err for syntax errors.");
				}

				$app->services->restartService('nginx', 'restart');
			}
		} else {
			//* We do not check the nginx config after changes (is faster)
			$app->services->restartServiceDelayed('nginx', 'reload');
		}

		// Remove the backup copy of the config file.
		if(@is_file($vhost_file.'~')) $app->system->unlink($vhost_file.'~');

		//* Unset action to clean it for next processed vhost.
		$this->action = '';
	}

	private function rewrite_flag_apache_to_nginx($flag) {
		// Convert Apache RewriteRule Flags to Nginx RewriteRule Flags
		switch($flag) {
			case 'R':
				$flag = 'redirect';
				break;
			case 'L':
				$flag = 'break';
				break;
			case 'R=301,L':
				$flag = 'permanent';
				break;
			case 'R,L':	
				$flag = 'last';
				break;	
		}
		return $flag;
	}

	private function _rewrite_quote($string) {
		return str_replace(array('.', '*', '?', '+'), array('\\.', '\\*', '\\?', '\\+'), $string);
	}

	private function url_is_local($hostname, $domain_id){
		global $app;

		// ORDER BY clause makes sure wildcard subdomains (*) are listed last in the result array so that we can find direct matches first
		$webs = $app->db->queryAllRecords("SELECT * FROM web_domain WHERE active = 'y' ORDER BY subdomain ASC");
		if(is_array($webs) && !empty($webs)){
			foreach($webs as $web){
				// web domain doesn't match hostname
				if(substr($hostname, -strlen($web['domain'])) != $web['domain']) continue;
				// own vhost and therefore server {} container of its own
				//if($web['type'] == 'vhostsubdomain' || $web['type'] == 'vhostalias') continue;
				// alias domains/subdomains using rewrites and therefore a server {} container of their own
				//if(($web['type'] == 'alias' || $web['type'] == 'subdomain') && $web['redirect_type'] != '' && $web['redirect_path'] != '') continue;

				if($web['subdomain'] == '*'){
					$pattern = '/\.?'.str_replace('.', '\.', $web['domain']).'$/i';
				}
				if($web['subdomain'] == 'none'){
					if($web['domain'] == $hostname){
						if($web['domain_id'] == $domain_id || $web['parent_domain_id'] == $domain_id){
							// own vhost and therefore server {} container of its own
							if($web['type'] == 'vhostsubdomain' || $web['type'] == 'vhostalias') return false;
							// alias domains/subdomains using rewrites and therefore a server {} container of their own
							if(($web['type'] == 'alias' || $web['type'] == 'subdomain') && $web['redirect_type'] != '' && $web['redirect_path'] != '') return false;
							return true;
						} else {
							return false;
						}
					}
					$pattern = '/^'.str_replace('.', '\.', $web['domain']).'$/i';
				}
				if($web['subdomain'] == 'www'){
					if($web['domain'] == $hostname || $web['subdomain'].'.'.$web['domain'] == $hostname){
						if($web['domain_id'] == $domain_id || $web['parent_domain_id'] == $domain_id){
							// own vhost and therefore server {} container of its own
							if($web['type'] == 'vhostsubdomain' || $web['type'] == 'vhostalias') return false;
							// alias domains/subdomains using rewrites and therefore a server {} container of their own
							if(($web['type'] == 'alias' || $web['type'] == 'subdomain') && $web['redirect_type'] != '' && $web['redirect_path'] != '') return false;
							return true;
						} else {
							return false;
						}
					}
					$pattern = '/^(www\.)?'.str_replace('.', '\.', $web['domain']).'$/i';
				}
				if(preg_match($pattern, $hostname)){
					if($web['domain_id'] == $domain_id || $web['parent_domain_id'] == $domain_id){
						// own vhost and therefore server {} container of its own
						if($web['type'] == 'vhostsubdomain' || $web['type'] == 'vhostalias') return false;
						// alias domains/subdomains using rewrites and therefore a server {} container of their own
						if(($web['type'] == 'alias' || $web['type'] == 'subdomain') && $web['redirect_type'] != '' && $web['redirect_path'] != '') return false;
						return true;
					} else {
						return false;
					}
				}
			}
		}

		return false;
	}

	private function get_seo_redirects($web, $prefix = '', $force_subdomain = false){
		// $force_subdomain = 'none|www'
		$seo_redirects = array();

		if(substr($web['domain'], 0, 2) === '*.') $web['subdomain'] = '*';

		if(($web['subdomain'] == 'www' || $web['subdomain'] == '*') && $force_subdomain != 'www'){
			if($web['seo_redirect'] == 'non_www_to_www'){
				$seo_redirects[$prefix.'seo_redirect_origin_domain'] = $web['domain'];
				$seo_redirects[$prefix.'seo_redirect_target_domain'] = 'www.'.$web['domain'];
				$seo_redirects[$prefix.'seo_redirect_operator'] = '=';
			}
			if($web['seo_redirect'] == '*_domain_tld_to_www_domain_tld'){
				// ^(example\.com|(?!\bwww\b)\.example\.com)$
				// ^(example\.com|((?:\w+(?:-\w+)*\.)*)((?!www\.)\w+(?:-\w+)*)(\.example\.com))$
				$seo_redirects[$prefix.'seo_redirect_origin_domain'] = '^('.str_replace('.', '\.', $web['domain']).'|((?:\w+(?:-\w+)*\.)*)((?!www\.)\w+(?:-\w+)*)(\.'.str_replace('.', '\.', $web['domain']).'))$';
				$seo_redirects[$prefix.'seo_redirect_target_domain'] = 'www.'.$web['domain'];
				$seo_redirects[$prefix.'seo_redirect_operator'] = '~*';
			}
			if($web['seo_redirect'] == '*_to_www_domain_tld'){
				$seo_redirects[$prefix.'seo_redirect_origin_domain'] = 'www.'.$web['domain'];
				$seo_redirects[$prefix.'seo_redirect_target_domain'] = 'www.'.$web['domain'];
				$seo_redirects[$prefix.'seo_redirect_operator'] = '!=';
			}
		}
		if($force_subdomain != 'none'){
			if($web['seo_redirect'] == 'www_to_non_www'){
				$seo_redirects[$prefix.'seo_redirect_origin_domain'] = 'www.'.$web['domain'];
				$seo_redirects[$prefix.'seo_redirect_target_domain'] = $web['domain'];
				$seo_redirects[$prefix.'seo_redirect_operator'] = '=';
			}
			if($web['seo_redirect'] == '*_domain_tld_to_domain_tld'){
				// ^(.+)\.example\.com$
				$seo_redirects[$prefix.'seo_redirect_origin_domain'] = '^(.+)\.'.str_replace('.', '\.', $web['domain']).'$';
				$seo_redirects[$prefix.'seo_redirect_target_domain'] = $web['domain'];
				$seo_redirects[$prefix.'seo_redirect_operator'] = '~*';
			}
			if($web['seo_redirect'] == '*_to_domain_tld'){
				$seo_redirects[$prefix.'seo_redirect_origin_domain'] = $web['domain'];
				$seo_redirects[$prefix.'seo_redirect_target_domain'] = $web['domain'];
				$seo_redirects[$prefix.'seo_redirect_operator'] = '!=';
			}
		}
		return $seo_redirects;
	}

	function _create_web_folder_auth_configuration($website){
		global $app, $conf;
		//* Create the domain.auth file which is included in the vhost configuration file
		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');
		$basic_auth_file = $web_config['nginx_vhost_conf_dir'].'/'.$website['domain'].'.auth';
		//$app->load('tpl');
		//$tpl = new tpl();
		//$tpl->newTemplate('nginx_http_authentication.auth.master');
		$website_auth_locations = $app->db->queryAllRecords("SELECT * FROM web_folder WHERE active = 'y' AND parent_domain_id = ?", $website['domain_id']);
		$basic_auth_locations = array();
		if(is_array($website_auth_locations) && !empty($website_auth_locations)){
			foreach($website_auth_locations as $website_auth_location){
				if(substr($website_auth_location['path'], 0, 1) == '/') $website_auth_location['path'] = substr($website_auth_location['path'], 1);
				if(substr($website_auth_location['path'], -1) == '/') $website_auth_location['path'] = substr($website_auth_location['path'], 0, -1);
				if($website_auth_location['path'] != ''){
					$website_auth_location['path'] .= '/';
				}
				$basic_auth_locations[] = array('htpasswd_location' => '/'.$website_auth_location['path'],
					'htpasswd_path' => $website['document_root'].'/' . (($website['type'] == 'vhostsubdomain' || $website['type'] == 'vhostalias') ? $website['web_folder'] : 'web') . '/'.$website_auth_location['path']);
			}
		}
		return $basic_auth_locations;
		//$tpl->setLoop('basic_auth_locations', $basic_auth_locations);
		//file_put_contents($basic_auth_file,$tpl->grab());
		//$app->log('Writing the http basic authentication file: '.$basic_auth_file,LOGLEVEL_DEBUG);
		//unset($tpl);
		//$app->services->restartServiceDelayed('httpd','reload');
	}

	private function nginx_replace($matches){
		$location = 'location'.($matches[1] != '' ? ' '.$matches[1] : '').' '.$matches[2].' '.$matches[3];
		if($matches[4] == '##merge##' || $matches[7] == '##merge##') $location .= ' ##merge##';
		if($matches[4] == '##delete##' || $matches[7] == '##delete##') $location .= ' ##delete##';
		$location .= "\n";
		$location .= $matches[5]."\n";
		$location .= $matches[6];
		return $location;
	}

	private function nginx_merge_locations($vhost_conf) {
		global $app, $conf;

        if(preg_match('/##subroot (.+?)\s*##/', $vhost_conf, $subroot)) {
            if(!preg_match('/^(?:[a-z0-9\/_-]|\.(?!\.))+$/iD', $subroot[1])) {
                $app->log('Token ##subroot is unsecure (server ID: '.$conf['server_id'].').', LOGLEVEL_WARN);
            } else {
                $insert_pos = strpos($vhost_conf, ';', strpos($vhost_conf, 'root '));
                $vhost_conf = substr_replace($vhost_conf, ltrim($subroot[1], '/'), $insert_pos, 0);
            }
        }

		$lines = explode("\n", $vhost_conf);

		// if whole location block is in one line, split it up into multiple lines
		if(is_array($lines) && !empty($lines)){
			$linecount = sizeof($lines);
			for($h=0;$h<$linecount;$h++){
				// remove comments
				if(substr(trim($lines[$h]), 0, 1) == '#'){
					unset($lines[$h]);
					continue;
				}

				$lines[$h] = rtrim($lines[$h]);
				/*
				if(substr(ltrim($lines[$h]), 0, 8) == 'location' && strpos($lines[$h], '{') !== false && strpos($lines[$h], ';') !== false){
					$lines[$h] = str_replace("{", "{\n", $lines[$h]);
					$lines[$h] = str_replace(";", ";\n", $lines[$h]);
					if(strpos($lines[$h], '##merge##') !== false){
						$lines[$h] = str_replace('##merge##', '', $lines[$h]);
						$lines[$h] = substr($lines[$h],0,strpos($lines[$h], '{')).' ##merge##'.substr($lines[$h],strpos($lines[$h], '{')+1);
					}
				}
				if(substr(ltrim($lines[$h]), 0, 8) == 'location' && strpos($lines[$h], '{') !== false && strpos($lines[$h], '}') !== false && strpos($lines[$h], ';') === false){
					$lines[$h] = str_replace("{", "{\n", $lines[$h]);
					if(strpos($lines[$h], '##merge##') !== false){
						$lines[$h] = str_replace('##merge##', '', $lines[$h]);
						$lines[$h] = substr($lines[$h],0,strpos($lines[$h], '{')).' ##merge##'.substr($lines[$h],strpos($lines[$h], '{')+1);
					}
				}
				*/
				$pattern = '/^[^\S\n]*location[^\S\n]+(?:(.+)[^\S\n]+)?(.+)[^\S\n]*(\{)[^\S\n]*(##merge##|##delete##)?[^\S\n]*(.+)[^\S\n]*(\})[^\S\n]*(##merge##|##delete##)?[^\S\n]*$/';
				$lines[$h] = preg_replace_callback($pattern, array($this, 'nginx_replace') , $lines[$h]);
			}
		}
		$vhost_conf = implode("\n", $lines);
		unset($lines);
		unset($linecount);

		$lines = explode("\n", $vhost_conf);

		if(is_array($lines) && !empty($lines)){
			$locations = array();
			$locations_to_delete = array();
			$islocation = false;
			$linecount = sizeof($lines);
			$server_count = 0;

			for($i=0;$i<$linecount;$i++){
				$l = trim($lines[$i]);
				if(substr($l, 0, 8) == 'server {') $server_count += 1;
				if($server_count > 1) break;
				if(substr($l, 0, 8) == 'location' && !$islocation){

					$islocation = true;
					$level = 0;

					// Remove unnecessary whitespace
					$l = preg_replace('/\s\s+/', ' ', $l);

					$loc_parts = explode(' ', $l);
					// see http://wiki.nginx.org/HttpCoreModule#location
					if($loc_parts[1] == '=' || $loc_parts[1] == '~' || $loc_parts[1] == '~*' || $loc_parts[1] == '^~'){
						$location = $loc_parts[1].' '.$loc_parts[2];
					} else {
						$location = $loc_parts[1];
					}
					unset($loc_parts);

					if(!isset($locations[$location]['action'])) $locations[$location]['action'] = 'replace';
					if(substr($l, -9) == '##merge##') $locations[$location]['action'] = 'merge';
					if(substr($l, -10) == '##delete##') $locations[$location]['action'] = 'delete';

					if(!isset($locations[$location]['open_tag'])) $locations[$location]['open_tag'] = '        location '.$location.' {';
					if(!isset($locations[$location]['location']) || $locations[$location]['action'] == 'replace') $locations[$location]['location'] = '';
					if($locations[$location]['action'] == 'delete') $locations_to_delete[] = $location;
					if(!isset($locations[$location]['end_tag'])) $locations[$location]['end_tag'] = '        }';
					if(!isset($locations[$location]['start_line'])) $locations[$location]['start_line'] = $i;

					unset($lines[$i]);

				} else {

					if($islocation){
						$openingbracketpos = strrpos($l, '{');
						if($openingbracketpos !== false){
							$level += 1;
						}
						$closingbracketpos = strrpos($l, '}');
						if($closingbracketpos !== false && $level > 0 && $closingbracketpos >= intval($openingbracketpos)){
							$level -= 1;
							$locations[$location]['location'] .= $lines[$i]."\n";
						} elseif($closingbracketpos !== false && $level == 0 && $closingbracketpos >= intval($openingbracketpos)){
							$islocation = false;
						} else {
							$locations[$location]['location'] .= $lines[$i]."\n";
						}
						unset($lines[$i]);
					}

				}
			}

			if(is_array($locations) && !empty($locations)){
				if(is_array($locations_to_delete) && !empty($locations_to_delete)){
					foreach($locations_to_delete as $location_to_delete){
						if(isset($locations[$location_to_delete])) unset($locations[$location_to_delete]);
					}
				}

				foreach($locations as $key => $val){
					$new_location = $val['open_tag']."\n".$val['location'].$val['end_tag'];
					$lines[$val['start_line']] = $new_location;
				}
			}
			ksort($lines);
			$vhost_conf = implode("\n", $lines);
		}

		return trim($vhost_conf);
	}

	private function _checkTcp ($host, $port) {

		$fp = @fsockopen($host, $port, $errno, $errstr, 2);

		if ($fp) {
			fclose($fp);
			return true;
		} else {
			return false;
		}
	}

	/**
	 * ISPConfig delete hook.
	 *
	 * Called every time, a site get's removed.
	 *
	 * @uses update()
	 *
	 * @param string $event_name the event/action name
	 * @param array  $data       the vhost data
	 */
	function delete($event_name, $data) {
		global $app, $conf;

		// load the server configuration options
		$app->uses('getconf');
		$app->uses('system');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');

		if($data['old']['type'] == 'vhost' || $data['old']['type'] == 'vhostsubdomain' || $data['old']['type'] == 'vhostalias') $app->system->web_folder_protection($data['old']['document_root'], false);

		//* Check if nginx is using a chrooted setup
		if($web_config['website_basedir'] != '' && @is_file($web_config['website_basedir'].'/etc/passwd')) {
			$nginx_chrooted = true;
		} else {
			$nginx_chrooted = false;
		}

		if($data['old']['type'] != 'vhost' && $data['old']['type'] != 'vhostsubdomain' && $data['old']['type'] != 'vhostalias' && $data['old']['parent_domain_id'] > 0) {
			//* This is a alias domain or subdomain, so we have to update the website instead
			$parent_domain_id = intval($data['old']['parent_domain_id']);
			$tmp = $app->db->queryOneRecord("SELECT * FROM web_domain WHERE domain_id = ? AND active = 'y'", $parent_domain_id);
			$data['new'] = $tmp;
			$data['old'] = $tmp;
			$this->action = 'update';
			// just run the update function
			$this->update($event_name, $data);

		} else {
			//* This is a website
			// Deleting the vhost file, symlink and the data directory
			$vhost_file = $web_config['nginx_vhost_conf_dir'].'/'.$data['old']['domain'].'.vhost';

			$vhost_symlink = $web_config['nginx_vhost_conf_enabled_dir'].'/'.$data['old']['domain'].'.vhost';
			if(is_link($vhost_symlink)){
				$app->system->unlink($vhost_symlink);
				$app->log('Removing symlink: '.$vhost_symlink.'->'.$vhost_file, LOGLEVEL_DEBUG);
			}
			$vhost_symlink = $web_config['nginx_vhost_conf_enabled_dir'].'/900-'.$data['old']['domain'].'.vhost';
			if(is_link($vhost_symlink)){
				$app->system->unlink($vhost_symlink);
				$app->log('Removing symlink: '.$vhost_symlink.'->'.$vhost_file, LOGLEVEL_DEBUG);
			}
			$vhost_symlink = $web_config['nginx_vhost_conf_enabled_dir'].'/100-'.$data['old']['domain'].'.vhost';
			if(is_link($vhost_symlink)){
				$app->system->unlink($vhost_symlink);
				$app->log('Removing symlink: '.$vhost_symlink.'->'.$vhost_file, LOGLEVEL_DEBUG);
			}

			$app->system->unlink($vhost_file);
			$app->log('Removing vhost file: '.$vhost_file, LOGLEVEL_DEBUG);

			$app->services->restartServiceDelayed('nginx', 'reload');

		}

	}

}