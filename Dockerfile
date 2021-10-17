FROM mydnshost/mydnshost-api-base AS api-base

FROM mydnshost/mydnshost-api-docker-base:latest
MAINTAINER Shane Mc Cormack <dataforce@dataforce.org.uk>

COPY --from=api /dnsapi /dnsapi

COPY ./web ./templates ./docker /dnsapi/

RUN \
  rm -Rfv /var/www/html && \
  ln -s /dnsapi/web /var/www/html && \
  mkdir /bind && \
  chown -Rfv www-data: /dnsapi/ /var/www/ /bind && \
  su www-data --shell=/bin/bash -c "cd /dnsapi; /usr/bin/composer install" && \
  groupadd -for -g 999 docker && \
  usermod -aG docker www-data

ENTRYPOINT ["/dnsapi/docker/run.sh"]
