FROM php:7.1-apache
MAINTAINER Shane Mc Cormack <dataforce@dataforce.org.uk>

COPY . /dnsapi

WORKDIR /var/www

RUN \
  rm -Rfv /var/www/html && \
  ln -s /dnsapi/web /var/www/html && \
  a2enmod rewrite && \
  apt-get update && apt-get install -y libmcrypt-dev && \
  docker-php-source extract && \
  docker-php-ext-install bcmath && \
  docker-php-ext-install mcrypt && \
  docker-php-ext-install pdo_mysql && \
  docker-php-source delete

EXPOSE 80
