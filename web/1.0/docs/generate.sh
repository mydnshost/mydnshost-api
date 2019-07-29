#!/bin/sh

DIR="$(dirname "$(readlink -f "$0")")"
cd "${DIR}"

if [ -e index.apib ]; then
	rm index.apib
fi;

cat *.apib >index.apib 2>/dev/null
aglio --theme-variables default --theme-full-width --no-theme-condense -i index.apib -o index.html
