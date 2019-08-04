#!/bin/sh
set -e

# # first arg is `-f` or `--some-option`
# if [ "${1#-}" != "$1" ]; then
# 	set -- php "$@"
# fi

CMD="${@}"
if [ "${CMD}" = "" ]; then
	php /dnsapi/admin/init.php && exec "apache2-foreground"
else
	exec "$@"
fi;
