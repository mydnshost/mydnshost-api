FROM shanemcc/docker-apache-php-base:latest
MAINTAINER Shane Mc Cormack <dataforce@dataforce.org.uk>

COPY . /dnsapi

RUN \
  apt-get update && apt-get install -y bind9utils sudo && \
  echo "www-data  ALL=NOPASSWD: /usr/sbin/rndc" >> /etc/sudoers.d/99_rndc && \
  chmod 0440 /etc/sudoers.d/99_rndc && \
  rm -Rfv /var/www/html && \
  ln -s /dnsapi/web /var/www/html
