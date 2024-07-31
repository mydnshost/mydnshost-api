#!/bin/sh

DIR="$(dirname "$(readlink -f "$0")")"
cd "${DIR}"

if [ -e index.apib ]; then
	rm index.apib
fi;

cat *.apib >index.apib 2>/dev/null
aglio --theme-variables default --theme-full-width --no-theme-condense -i index.apib -o index.html

# Also attempt-to-generate a swagger version.
# This is not perfect, but probably good enough.
docker run --user $(id -u) -it --rm -v $(pwd):/docs ghcr.io/kminami/apib2swagger -i /docs/index.apib -o /docs/swagger.yaml --open-api-3 --yaml

# Fix security options in swagger version.
sed -i '/^components:$/,+1d' swagger.yaml
cat swagger-auth.yaml >> swagger.yaml

# Fix relative url in swagger
sed -i 's#https://api.mydnshost.co.uk/#/#g' swagger.yaml
