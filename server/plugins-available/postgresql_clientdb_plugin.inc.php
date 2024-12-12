<?php

/*
Copyright (c) 2024, Till Brehm, ISPConfig UG
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

class postgresql_clientdb_plugin {

	var $plugin_name = 'postgresql_clientdb_plugin';
	var $class_name = 'postgresql_clientdb_plugin';

	var $denylist_user = ['root', 'postgres','pg_signal_backend','pg_read_all_data','pg_write_all_data','pg_monitor','pg_read_all_settings','pg_read_all_stats','pg_execute_server_program','pg_stat_statements','pg_database_owner'];
	var $denylist_database = ['postgres', 'template0', 'template1','pg_catalog','information_schema','pg_toast'];

	/** @var postgres|null */
	var $link = null;
	/** @var string  */
	var $clientdb_host = 'localhost';
	/** @var string  */
	var $clientdb_user;
	/** @var string  */
	var $clientdb_password;

	var $psql_error = '';

	var $pg_min_version = 14;

	//* This function is called during ISPConfig installation to determine
	//  if a symlink shall be created for this plugin.
	function onInstall() {
		global $conf;

		if($conf['services']['db']) {
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

		//* Databases
		$app->plugins->registerEvent('database_insert', $this->plugin_name, 'dbInsert');
		$app->plugins->registerEvent('database_update', $this->plugin_name, 'dbUpdate');
		$app->plugins->registerEvent('database_delete', $this->plugin_name, 'dbDelete');

		//* Database users
		// $app->plugins->registerEvent('database_user_insert', $this->plugin_name, 'dbUserInsert'); <- stale user accounts are useless ;)
		$app->plugins->registerEvent('database_user_update', $this->plugin_name, 'dbUserUpdate');
		//$app->plugins->registerEvent('database_user_delete', $this->plugin_name, 'dbUserDelete');
	}

	function dbInsert($event_name, $data) {
		global $app;

		if($data['new']['type'] != 'postgresql') {
			return;
		}

		// Create the database
		$db_create_success = $this->createDatabase($data['new']);

		// Create the database users if database is active
		if($db_create_success) {
			if($data['new']['active'] == 'y') {
				$db_user = $app->db->queryOneRecord('SELECT * FROM `web_database_user` WHERE `database_user_id` = ?', $data['new']['database_user_id']);
				$db_ro_user = $app->db->queryOneRecord('SELECT * FROM `web_database_user` WHERE `database_user_id` = ?', $data['new']['database_ro_user_id']);
				$host_list = $this->getHostList($data['new']);

				$this->createDBUser($data['new']['database_name'],$db_user,$host_list,'rw');
				if(!empty($db_ro_user)) $this->createDBUser($data['new']['database_name'],$db_ro_user,$host_list,'r');
			}

			// Reload postgres
			$this->pg_reload_service();
		}

	}

	function dbUpdate($event_name, $data) {
		global $app;

		if($data['new']['type'] != 'postgresql') {
			return;
		}

		// skip processing if database was and is inactive
		if($data['new']['active'] == 'n' && $data['old']['active'] == 'n') {
			return;
		}

		$postgres_reload = false;

		// Database name changed
		if($data['new']['database_name'] != $data['old']['database_name']) {
			// Terminate connections
			$sql = 'SELECT pg_terminate_backend(pg_stat_activity.pid)
			FROM pg_stat_activity
			WHERE pg_stat_activity.datname = '.$this->pg_escape_string($data['old']['database_name']).'
			AND pid <> pg_backend_pid();';
			if($this->pg_query($sql)) {
				$app->log('PostgreSQL terminate connections: ' . $sql, LOGLEVEL_DEBUG);
			} else {
				$app->log('PostgreSQL terminate connections failed: ' . $this->psql_error, LOGLEVEL_WARN);
			}

			// Rename database
			$sql = 'ALTER DATABASE '.$data['old']['database_name'].' RENAME TO '.$data['new']['database_name'].';';
			if($this->pg_query($sql)) {
				$app->log('PostgreSQL rename DB: ' . $sql, LOGLEVEL_DEBUG);
			} else {
				$app->log('PostgreSQL rename DB failed: ' . $this->psql_error, LOGLEVEL_WARN);
			}
		}


		// RO user has been changed
		if($data['new']['database_ro_user_id'] != $data['old']['database_ro_user_id']) {
			// Remove old ro user, if there is one
			$db_ro_user = $app->db->queryOneRecord('SELECT * FROM `web_database_user` WHERE `database_user_id` = ?', $data['old']['database_ro_user_id']);
			if(!empty($db_ro_user)) $this->deleteDBUser($data['new'], $db_ro_user);
			if($data['new']['database_ro_user_id'] > 0) {
				$host_list = $this->getHostList($data['new']);
				$db_ro_user = $app->db->queryOneRecord('SELECT * FROM `web_database_user` WHERE `database_user_id` = ?', $data['new']['database_ro_user_id']);
				$this->createDBUser($data['new']['database_name'],$db_ro_user,$host_list,'r');
			}
			$postgres_reload = true;
		}

		// RW user has been changed
		if($data['new']['database_user_id'] != $data['old']['database_user_id']) {

			// create new user first
			$host_list = $this->getHostList($data['new']);
			$db_user = $app->db->queryOneRecord('SELECT * FROM `web_database_user` WHERE `database_user_id` = ?', $data['new']['database_user_id']);
			$old_db_user = $app->db->queryOneRecord('SELECT * FROM `web_database_user` WHERE `database_user_id` = ?', $data['old']['database_user_id']);
			$this->createDBUser($data['new']['database_name'],$db_user,$host_list,'rw');

			// Assign content of old user to new user
			$sql = 'REASSIGN OWNED BY '.$old_db_user['database_user'].' TO '.$db_user['database_user'].';';
			if($this->pg_query($sql)) {
				$app->log('PostgreSQL reassigning data: ' . $sql, LOGLEVEL_DEBUG);
			} else {
				$app->log('PostgreSQL reassigning data ' . $this->psql_error, LOGLEVEL_WARN);
			}

			// Remove old user
			$this->deleteDBUser($data['new'], $old_db_user);
			$postgres_reload = true;
		}

		// Database has been activated
		if($data['new']['active'] == 'y' && $data['old']['active'] == 'n') {
			if($data['new']['active'] == 'y') {
				$db_user = $app->db->queryOneRecord('SELECT * FROM `web_database_user` WHERE `database_user_id` = ?', $data['new']['database_user_id']);
				$db_ro_user = $app->db->queryOneRecord('SELECT * FROM `web_database_user` WHERE `database_user_id` = ?', $data['new']['database_ro_user_id']);
				$host_list = $this->getHostList($data['new']);

				$this->createDBUser($data['new']['database_name'],$db_user,$host_list,'rw');
				if(!empty($db_ro_user)) $this->createDBUser($data['new']['database_name'],$db_ro_user,$host_list,'r');
			}

			$postgres_reload = true;
		}

		// Database has been deactivated
		if($data['new']['active'] == 'n' && $data['old']['active'] == 'y') {
			// Get record for db and ro user
			$db_user = $app->db->queryOneRecord('SELECT * FROM `web_database_user` WHERE `database_user_id` = ?', $data['new']['database_user_id']);
			$db_ro_user = $app->db->queryOneRecord('SELECT * FROM `web_database_user` WHERE `database_user_id` = ?', $data['new']['database_ro_user_id']);

			// Delete database user
			if(!empty($db_user)) $this->deleteDBUser($data['new'], $db_user);
			if(!empty($db_ro_user)) $this->deleteDBUser($data['new'], $db_ro_user);
			$postgres_reload = true;
		}


		// hosts list has been changed or database has been changed so we must update pg_hba.conf
		if($data['new']['remote_access'] != $data['old']['remote_access'] ||
		   $data['new']['remote_ips'] != $data['old']['remote_ips'] ||
		   $data['new']['database_name'] != $data['old']['database_name']) {

			$host_list = $this->getHostList($data['new']);

			$db_user = $app->db->queryOneRecord('SELECT * FROM `web_database_user` WHERE `database_user_id` = ?', $data['new']['database_user_id']);
			$db_ro_user = $app->db->queryOneRecord('SELECT * FROM `web_database_user` WHERE `database_user_id` = ?', $data['new']['database_ro_user_id']);

			$this->pg_hba_user_del($db_user['database_user'],$data['new']['database_name']);
			if(!empty($db_ro_user)) $this->pg_hba_user_del($db_ro_user['database_user'],$data['new']['database_name']);
			if($data['new']['database_name'] != $data['old']['database_name']) {
				$this->pg_hba_user_del($db_user['database_user'],$data['old']['database_name']);
				if(!empty($db_ro_user)) $this->pg_hba_user_del($db_ro_user['database_user'],$data['old']['database_name']);
			}

			// Configure hosts for the user
			if(is_array($host_list)) {
				foreach($host_list as $host) {
					$this->pg_hba_user_add($db_user['database_user'],$data['new']['database_name'],$host);
					if(!empty($db_ro_user)) $this->pg_hba_user_add($db_ro_user['database_user'],$data['new']['database_name'],$host);
				}
			}

			$postgres_reload = true;
		}

		// Reload postgres
		if($postgres_reload) {
			$this->pg_reload_service();
		}
	}

	function dbDelete($event_name, $data) {
		global $app;

		if($data['old']['type'] != 'postgresql') {
			return;
		}

		// Delete the PostgreSQL database
		$this->deleteDatabase($data['old']);

		// Get record for db and ro user
		$db_user = $app->db->queryOneRecord('SELECT * FROM `web_database_user` WHERE `database_user_id` = ?', $data['old']['database_user_id']);
		$db_ro_user = $app->db->queryOneRecord('SELECT * FROM `web_database_user` WHERE `database_user_id` = ?', $data['old']['database_ro_user_id']);

		// Delete database user
		if(!empty($db_user)) $this->deleteDBUser($data['old'], $db_user);
		if(!empty($db_ro_user)) $this->deleteDBUser($data['old'], $db_ro_user);

		// Reload postgres
		$this->pg_reload_service();
		
	}

	function dbUserUpdate($event_name, $data) {
		global $app;

		// nothing to do when username and password are the same. No need to check postgresql password since it is always in sync with database_password
		if($data['old']['database_user'] == $data['new']['database_user'] && ($data['old']['database_password'] == $data['new']['database_password'] || $data['new']['database_password'] == '')) {
			return;
		}

		// get all databases this user was active for
		$user_id = intval($data['old']['database_user_id']);
		$db_list = $app->db->queryAllRecords('SELECT `remote_access`, `remote_ips` FROM `web_database` WHERE (`database_user_id` = ? OR database_ro_user_id = ?) AND `type` = "postgresql"', $user_id, $user_id);
		// nothing to do on this server for this db user
		if(empty($db_list)) {
			return;
		}

		// Update the password
		if($data['old']['database_password'] != $data['new']['database_password']) {
			$sql = 'ALTER ROLE '.$data['old']['database_user'].' WITH ENCRYPTED PASSWORD '.$this->pg_escape_string($data['new']['database_password_postgres']).';';
			if($this->pg_query($sql)) {
				$app->log('PostgreSQL user password changed: ' . $sql, LOGLEVEL_DEBUG);
			} else {
				$app->log('PostgreSQL user password changed ' . $this->psql_error, LOGLEVEL_WARN);
			}
		}

		// update the username
		if($data['old']['database_user'] != $data['new']['database_user']) {
			$sql = 'ALTER ROLE '.$data['old']['database_user'].' RENAME TO '.$data['new']['database_user'].';';
			if($this->pg_query($sql)) {
				$app->log('PostgreSQL username changed: ' . $sql, LOGLEVEL_DEBUG);
			} else {
				$app->log('PostgreSQL username changed ' . $this->psql_error, LOGLEVEL_WARN);
			}
			// get database name
			$databases = $app->db->queryAllRecords('SELECT * FROM `web_database` WHERE `database_user_id` = ? OR `database_ro_user_id` = ?',$data['new']['database_user_id'],$data['new']['database_user_id']);
			if(is_array($databases)) {
				foreach($databases as $database) {
					$this->pg_hba_user_del($data['old']['database_user'],$database['database_name']);
					$host_list = $this->getHostList($database);
					// Configure hosts for the user
					if(is_array($host_list)) {
						foreach($host_list as $host) {
							$this->pg_hba_user_add($data['new']['database_user'],$database['database_name'],$host);
						}
					}
				}
			}

			// Reload postgres
			$this->pg_reload_service();
		}
		
	}

	/**
	 * Creates a PostgreSQL database
	 *
	 * @param array $record
	 * @return bool
	 */
	private function createDatabase($record) {
		global $app;

		if(in_array(strtolower($record['database_name']), $this->denylist_database)) {
			$app->log('Refuse to create database ' . $record['database_name'], LOGLEVEL_WARN);
			return false;
		}

		if(!$this->pg_connect()) return false;

		// Charset for the new table
		$query_charset_table = '';
		/*
		if($record['database_charset'] != '') {
			if($record['database_charset'] == 'utf8mb4') $record['database_charset'] = 'utf8';
			$query_charset_table = "  WITH ENCODING " . $this->pg_escape_string(strtoupper($record['database_charset']))."";
		} else {
			$query_charset_table = '';
		}
		*/

		//* Create the new database
		$sql = 'CREATE DATABASE ' . $record['database_name'] . '' . $query_charset_table.';';
		if($this->pg_query($sql)) {
			$app->log('Created PostgreSQL database: ' . $sql, LOGLEVEL_DEBUG);
			return true;
		} else {
			$app->log('Unable to create the database: ' . $this->psql_error, LOGLEVEL_WARN);
			return false;
		}
	}

	/**
	 * Drops a PostgeSQL database
	 *
	 * @param array $record
	 * @return bool
	 */
	private function deleteDatabase($record) {
		global $app;

		if(in_array(strtolower($record['database_name']), $this->denylist_database)) {
			$app->log('Refuse to delete database ' . $record['database_name'], LOGLEVEL_WARN);
			return false;
		}

		$sql = 'DROP DATABASE IF EXISTS ' . $record['database_name'] . ' WITH (FORCE);';
		if($this->pg_query($sql)) {
			$app->log('Dropping PostgreSQL database: ' . $sql, LOGLEVEL_DEBUG);
			return true;
		} else {
			$app->log('Error while dropping PostgreSQL database: ' . $record['database_name'] . ' ' . $this->psql_error, LOGLEVEL_WARN);
			return false;
		}
	}


	function createDBUser($database, $db_user, $host_list, $mode) {
		global $app;

		if(in_array(strtolower($db_user['database_user']), $this->denylist_user)) {
			$app->log('Refuse to set password for user ' . $db_user['database_user'], LOGLEVEL_WARN);
			return false;
		}

		$username = $db_user['database_user'];
		$password = !empty($db_user['database_password_postgres']) ? $db_user['database_password_postgres'] : '';

		// Create the user
		$sql = 'DO $$
BEGIN
   IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '.$this->pg_escape_string($username).') THEN
      CREATE ROLE '.$username.' WITH LOGIN ENCRYPTED PASSWORD '.$this->pg_escape_string($password).';
   END IF;
END $$;';

		if($this->pg_query($sql)) {
			$app->log('Created PostgreSQL user: ' . $sql, LOGLEVEL_DEBUG);
		} else {
			$app->log('Unable to create PostgreSQL user: '.$sql. ' --- ' . $this->psql_error, LOGLEVEL_WARN);
			return false;
		}

		// Grant permissions
		$sql = 'GRANT CONNECT ON DATABASE '.$database.' TO '.$username.';';
		if($this->pg_query($sql)) {
			$app->log('Created PostgreSQL user: ' . $sql, LOGLEVEL_DEBUG);
		} else {
			$app->log('Unable to create PostgreSQL user: '.$sql. ' --- ' . $this->psql_error, LOGLEVEL_WARN);
			return false;
		}

		if($mode == 'r') {
			$sql = 'GRANT pg_read_all_data TO '.$username.';';
			if($this->pg_query($sql)) {
				$app->log('Created PostgreSQL user: ' . $sql, LOGLEVEL_DEBUG);
			} else {
				$app->log('Unable to create PostgreSQL user: '.$sql. ' --- ' . $this->psql_error, LOGLEVEL_WARN);
				return false;
			}
		} else {
			$sql = 'ALTER DATABASE '.$database.' OWNER TO '.$username.';';
			if($this->pg_query($sql)) {
				$app->log('Created PostgreSQL user: ' . $sql, LOGLEVEL_DEBUG);
			} else {
				$app->log('Unable to create PostgreSQL user: '.$sql. ' --- ' . $this->psql_error, LOGLEVEL_WARN);
				return false;
			}
		}

		// Configure hosts for the user
		if(is_array($host_list)) {
			foreach($host_list as $host) {
				$this->pg_hba_user_add($username,$database,$host);
			}
		}
	}

	function deleteDBUser($db_rec, $db_user) {
		global $app;

		if(in_array(strtolower($db_user['database_user']), $this->denylist_user)) {
			$app->log('Refuse to set password for user ' . $db_user['database_user'], LOGLEVEL_WARN);
			return false;
		}

		if(!is_array($db_rec) || !is_array($db_user)) {
			$app->log('ÜostgreSQL DB or user record is not an array.', LOGLEVEL_DEBUG);
			return false;
		}

		$username = $db_user['database_user'];
		$database = $db_rec['database_name'];

		// Delete user if no other database is using it
		$sql = "SELECT * FROM `web_database` WHERE (`database_user_id` = ? OR `database_ro_user_id` = ?) AND `type` = 'postgresql' AND `database_id` != ?";
		$tmp = $app->db->queryAllRecords($sql,$db_user['database_user_id'],$db_user['database_user_id'],$db_rec['database_id']);
		if(empty($tmp)) {
			$sql = 'DROP USER IF EXISTS '.$username.';';
			if($this->pg_query($sql)) {
				$app->log('Drop PostgreSQL user: ' . $sql, LOGLEVEL_DEBUG);
			} else {
				$app->log('Unable to drop PostgreSQL user: '.$sql. ' --- ' . $this->psql_error, LOGLEVEL_WARN);
				return false;
			}
		}


		// Delete user permissions in pg_hba.conf
		$this->pg_hba_user_del($username,$database);
	}

	/**
	 * Get list of hosts that this database is accessible from.
	 *
	 * @param array $db_record
	 * @return array
	 */
	private function getHostList($db_record) {
		$host_list = [];
		if($db_record['remote_access'] == 'y') {
			$host_list = array_filter(array_map(function($ip) {
				return filter_var(trim($ip), FILTER_VALIDATE_IP);
			}, explode(',', $db_record['remote_ips'] ?: '')));
			if(empty($host_list)) {
				$host_list[] = '0.0.0.0/0';
			}
		}
		$host_list[] = '127.0.0.1/32';
		$host_list = array_values(array_unique($host_list));
		sort($host_list);

		return $host_list;
	}


	/**
	 * Get host list of all other databases of the user.
	 *
	 * @param int $database_id
	 * @param int $user_id
	 * @return array
	 */
	private function getOtherHostList($database_id, $user_id) {
		global $app;

		$db_user_host_list = [];
		$other_user_databases = $app->db->queryAllRecords("SELECT `remote_access`, `remote_ips` FROM web_database WHERE (database_user_id = ? OR database_ro_user_id = ?) AND active = 'y' AND database_id != ?",
			$user_id, $user_id, $database_id);
		foreach($other_user_databases as $record) {
			$db_user_host_list = array_merge($db_user_host_list, $this->getHostList($record));
		}

		return array_values(array_unique($db_user_host_list));
	}

	private function pg_connect() {
		global $app;

		if($this->link) {
			return true;
		} else {

			if(!function_exists('pg_connect')) {
				$app->log('PostgreSQL functions in PHP missing. Please install PHP postgresql extension.', LOGLEVEL_WARN);
				return false;
			}
			
			$clientdb_host = '';
			$clientdb_user = '';
			$clientdb_password = '';

			// Check if we have a ISPConfig postgres config file
			if(!file_exists(ISPC_LIB_PATH . '/postgresql_clientdb.conf')) {
				// Create a new ispconfig posgres users and config file
				$clientdb_host = 'localhost';
				$clientdb_user = 'ispconfig';
				$clientdb_password = $this->generatePassword();

				$config_file_content = '<?php

$clientdb_host                  = \'localhost\';
$clientdb_user                  = \'ispconfig\';
$clientdb_password              = \''.$clientdb_password.'\';
';
				file_put_contents(ISPC_LIB_PATH . '/postgresql_clientdb.conf',$config_file_content);
				chmod(ISPC_LIB_PATH . '/postgresql_clientdb.conf',0700);
				chown(ISPC_LIB_PATH . '/postgresql_clientdb.conf','ispconfig');
				chgrp(ISPC_LIB_PATH . '/postgresql_clientdb.conf','ispconfig');

				$this->psql("CREATE ROLE ispconfig WITH SUPERUSER CREATEDB CREATEROLE LOGIN PASSWORD '$clientdb_password';");

				$pg_hba_path = $this->get_pg_hba_path();
				$config = "local   all             ispconfig                               scram-sha-256\nhost    all             ispconfig          127.0.0.1/32         scram-sha-256";
				file_put_contents($pg_hba_path,$config,FILE_APPEND);

				$this->pg_reload_service();

			} else {
				if(!include ISPC_LIB_PATH . '/postgresql_clientdb.conf') {
					$app->log('Unable to open' . ISPC_LIB_PATH . '/postgresql_clientdb.conf', LOGLEVEL_WARN);
					return false;
				}
			}

			$this->clientdb_host = $clientdb_host;
			$this->clientdb_user = $clientdb_user;
			$this->clientdb_password = $clientdb_password;
			
			$this->link = pg_connect("host=".$this->clientdb_host." dbname=postgres user=".$this->clientdb_user." password=".$this->clientdb_password);
			
			if(!$this->link) {
				$app->log("PostgreSQL Login failed.", LOGLEVEL_WARN);
				return false;
			} else {
				// Check Postgres version
				if($this->pg_version_check()) {
					return true;
				} else {
					$app->log("Minimum PostgreSQL version is: ".$this->pg_min_version, LOGLEVEL_WARN);
					return false;
				}
			}
		}
	}
	
	private function pg_escape_string($string) {
		if(!$this->pg_connect()) return false;
		return pg_escape_literal($this->link,$string);
	}

	private function pg_query($sql) {
		if(!$this->pg_connect()) return false;
		return pg_query($this->link,$sql);
	}

	private function pg_query_one_record($sql) {
		$result = $this->pg_query($sql);
		return pg_fetch_array($result, NULL, PGSQL_ASSOC);
	}

	private function pg_disconnect() {
		if ($this->link) {
			pg_close($this->link);
			$this->link = null;
		}
	}

	private function generatePassword($length = 16) {
		// Define character sets
		$lowercase = 'abcdefghijklmnopqrstuvwxyz';
		$uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$digits = '0123456789';
		$specialChars = '!@#$%^&*()_-+=<>?{}[]|';
	
		// Ensure the password contains at least one character from each set
		$password = '';
		$password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
		$password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
		$password .= $digits[random_int(0, strlen($digits) - 1)];
		$password .= $specialChars[random_int(0, strlen($specialChars) - 1)];
	
		// Create a pool of all characters and add more to meet the required length
		$allChars = $lowercase . $uppercase . $digits . $specialChars;
		for ($i = 4; $i < $length; $i++) {
			$password .= $allChars[random_int(0, strlen($allChars) - 1)];
		}
	
		// Shuffle the password to randomize the order of characters
		return str_shuffle($password);
	}

	// Run postgres sql commands via command_line as postgres user
	private function psql($query) {
		// Sanitize and escape the query to avoid shell injection
		$escapedQuery = escapeshellarg($query);
	
		// Build the command using `sudo -u postgres` to execute as the `postgres` user
		$command = "sudo -u postgres psql -c $escapedQuery 2>&1";
	
		// Execute the command using exec()
		exec($command, $output, $returnVar);
	
		// Check if the execution was successful
		if ($returnVar !== 0) {
			// If there was an error, return false and the error message
			$this->psql_error = implode("\n", $output);
			return false;
		}
	
		// Return the output if the query executed successfully
		return implode("\n", $output);
	}

	private function get_pg_hba_path() {
		$files = glob('/etc/postgresql/*/main/pg_hba.conf');
		if(is_array($files) && !empty($files[0])) {
			return $files[0];
		} else {
			return false;
		}
	}

	private function pg_hba_user_del ($username,$database = '',$host = '') {
		$file_path = $this->get_pg_hba_path();
		if(!empty($file_path)) {
			$lines = explode("\n",file_get_contents($file_path));
			$out_array = [];
			$regex = '/^(local|host)\s+(\S+)\s+(\S+)(\s+(\S+\/\d+|\S+))?\s+(peer|scram-sha-256)$/';
			foreach($lines as $line) {
				$line = trim($line);
				if(!empty($line) && substr($line,0,1) != '#') {
					if(preg_match($regex,$line,$match)) {
						list($full_match, $m_connection_type, $m_database, $m_username, $m_host, $m_auth_method) = $match;
						$m_connection_type = trim($m_connection_type);
						$m_database = trim($m_database);
						$m_username = trim($m_username);
						$m_host = trim($m_host);
						$m_auth_method = trim($m_auth_method);
						if($database == '' && $host == '') {
							if($m_username == $username) continue;
						} elseif ($database != '' && $host == '') {
							if($m_username == $username && $m_database == $database) continue;
						} else {
							if($m_username == $username && $m_database == $database && $m_host == $host) continue;
						}
					}
				}
				$out_array[] = $line;
			}
			file_put_contents($file_path,implode("\n",$out_array));
		}
	}

	private function pg_hba_user_add ($username,$database,$host) {
		$file_path = $this->get_pg_hba_path();
		if(!empty($file_path)) {
			
			if($host == 'local') {
				$config = "local    $database             $username                   scram-sha-256";
			} else {
				$config = "host    $database             $username          $host         scram-sha-256";
			}
			$lines = explode("\n",file_get_contents($file_path));
			$lines[] = $config;
			file_put_contents($file_path,implode("\n",$lines));
		}
	}

	private function pg_reload_service() {
		exec('systemctl reload postgresql');
	}

	private function pg_version_check() {
		$rec = $this->pg_query_one_record("SELECT current_setting('server_version');");
		if(is_array($rec)) {
			$version = $rec['current_setting'];
			$version = explode(' ',$version);
			if(version_compare($version[0],$this->pg_min_version) >= 0) {
				return true;
			} else {
				return false;
			}
		} else {
			return false;
		}


	}


} // end class
