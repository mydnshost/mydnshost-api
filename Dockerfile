FROM shanemcc/docker-apache-php-base:latest
MAINTAINER Shane Mc Cormack <dataforce@dataforce.org.uk>

COPY . /dnsapi

RUN \
  apt-get update && apt-get install -y bind9utils
  rm -Rfv /var/www/html && \
  ln -s /dnsapi/web /var/www/html
