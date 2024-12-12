#!/bin/bash

# Short-Description: LETSENCRYPT RENEW HOOK SCRIPT
# Description:  Taken from LE4ISPC code. To be used to update ispserver.pem automatically after ISPConfig LE SSL certs are renewed and to reload / restart important ISPConfig server services

## If you need a custom hook file, create a file with the same name in
## /usr/local/ispconfig/server/conf-custom/scripts/
##
## End the file with 'return 124' to signal that this script should not terminate.
if [ -e "/usr/local/ispconfig/server/conf-custom/scripts/letsencrypt_renew_hook.sh" ]; then
  . /usr/local/ispconfig/server/conf-custom/scripts/letsencrypt_renew_hook.sh
  ret=$?
  if [ $ret != 124 ]; then exit $ret; fi
fi

hostname=$(hostname -f)

# If you want to manually execute letsencrypt_renew_hook.sh, call it with the SUCCESS environment variable set.
# E.g. like this: "SUCCESS=1 letsencrypt_renew_hook.sh"
# Then we assume that the certificate is there and do the post-processing.
SUCCESS=${SUCCESS:-}

# acme.sh defines/exports the environment variables
# CERT_PATH, CERT_KEY_PATH, CA_CERT_PATH, CERT_FULLCHAIN_PATH and Le_Domain (main cert domain)
# for all hooks
if [ -f "$CERT_KEY_PATH" ] && [[ "${Le_Domain:-}" == "$hostname" ]]; then
  SUCCESS=acme.sh
  echo "$(/bin/date)" "Reconfigure and reload services after $hostname certificate issuing/renewal via acme.sh" >>/var/log/ispconfig/ispconfig.log
# certbot defines/exports the environment variables
# RENEWED_DOMAINS (all cert domains space separated) and RENEWED_LINEAGE (directory in /etc/letsencrypt/live)
# for the renew/deploy hook
elif [ -d "$RENEWED_LINEAGE" ] && [[ "$RENEWED_DOMAINS " == "$hostname "* ]]; then
  SUCCESS=certbot
  echo "$(/bin/date)" "Reconfigure and reload services after $hostname certificate issuing/renewal via certbot" >>/var/log/ispconfig/ispconfig.log
fi

if [ -n "$SUCCESS" ]; then
  if cd /usr/local/ispconfig/interface/ssl; then
    ipem=ispserver.pem
    icrt=ispserver.crt
    ikey=ispserver.key
    if ls ispserver.*.bak &>/dev/null; then
      rm ispserver.*.bak
    fi
    if [ -e "$ipem" ]; then
      mv $ipem "$ipem-$(date +"%y%m%d%H%M%S").bak"
      cat $ikey $icrt >$ipem
      chmod 600 $ipem
    fi
  fi
  pureftpdpem=/etc/ssl/private/pure-ftpd.pem
  if [ -e "$pureftpdpem" ]; then chmod 600 $pureftpdpem; fi
  # For Red Hat, Centos or derivatives
  if which yum &>/dev/null 2>&1; then
    if rpm -q pure-ftpd; then service pure-ftpd restart; fi
    if rpm -q monit; then service monit restart; fi
    if rpm -q postfix; then service postfix restart; fi
    if rpm -q dovecot; then service dovecot restart; fi
    if rpm -q mysql-server; then service mysqld restart; fi
    if rpm -q mariadb-server; then service mariadb restart; fi
    if rpm -q MariaDB-server; then service mysql restart; fi
    if rpm -q nginx; then service nginx restart; fi
    if rpm -q httpd; then service httpd restart; fi
  # For Debian, Ubuntu or derivatives
  elif apt-get -v >/dev/null 2>&1; then
    if [ "$(dpkg-query -W -f='${Status}' pure-ftpd-mysql 2>/dev/null | grep -c "ok installed")" -eq 1 ]; then service pure-ftpd-mysql restart; fi
    if [ "$(dpkg-query -W -f='${Status}' monit 2>/dev/null | grep -c "ok installed")" -eq 1 ]; then service monit restart; fi
    if [ "$(dpkg-query -W -f='${Status}' postfix 2>/dev/null | grep -c "ok installed")" -eq 1 ]; then service postfix restart; fi
    if [ "$(dpkg-query -W -f='${Status}' dovecot-imapd 2>/dev/null | grep -c "ok installed")" -eq 1 ]; then service dovecot restart; fi
    if [ "$(dpkg-query -W -f='${Status}' mysql 2>/dev/null | grep -c "ok installed")" -eq 1 ]; then service mysql restart; fi
    if [ "$(dpkg-query -W -f='${Status}' mariadb 2>/dev/null | grep -c "ok installed")" -eq 1 ]; then service mysql restart; fi
    if [ "$(dpkg-query -W -f='${Status}' nginx 2>/dev/null | grep -c "ok installed")" -eq 1 ]; then service nginx restart; fi
    if [ "$(dpkg-query -W -f='${Status}' apache2 2>/dev/null | grep -c "ok installed")" -eq 1 ]; then service apache2 restart; fi
  fi
else
  echo "$(/bin/date)" "Your Lets Encrypt SSL certs path for your ISPConfig server FQDN is missing." >>/var/log/ispconfig/ispconfig.log
fi
