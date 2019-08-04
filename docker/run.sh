#!/bin/sh
set -e

php /dnsapi/admin/init.php && exec "apache2-foreground"
