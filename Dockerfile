FROM mydnshost/mydnshost-api-docker-base:latest
MAINTAINER Shane Mc Cormack <dataforce@dataforce.org.uk>

COPY . /dnsapi

RUN \
  rm -Rfv /var/www/html && \
  ln -s /dnsapi/web /var/www/html && \
  mkdir /bind && \
  chown -Rfv www-data: /dnsapi/ /var/www/ /bind && \
  su www-data --shell=/bin/bash -c "cd /dnsapi; /usr/bin/composer install" && \
  groupadd -for -g 999 docker && \
  usermod -aG docker www-data
