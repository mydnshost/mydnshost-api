FROM shanemcc/docker-apache-php-base:latest
MAINTAINER Shane Mc Cormack <dataforce@dataforce.org.uk>

RUN \
  apt-get update && apt-get install -y bind9utils sudo libgearman-dev && \
  docker-php-source extract && \
  docker-php-ext-install pcntl && \
  mkdir /tmp/gearman && \
  curl -Lo /tmp/gearman.tar.gz https://github.com/wcgallego/pecl-gearman/archive/gearman-2.0.3.tar.gz && \
  tar -xvf /tmp/gearman.tar.gz --strip 1 --directory /tmp/gearman && \
  cd /tmp/gearman && \
  phpize && ./configure && make && make install && \
  rm -Rf /tmp/gearman /tmp/gearman.tar.gz && \
  echo extension=gearman.so >> /usr/local/etc/php/conf.d/gearman.ini && \
  docker-php-source delete && \
  echo "www-data  ALL=NOPASSWD: /usr/sbin/rndc" >> /etc/sudoers.d/99_rndc && \
  chmod 0440 /etc/sudoers.d/99_rndc

COPY . /dnsapi

RUN \
  rm -Rfv /var/www/html && \
  ln -s /dnsapi/web /var/www/html && \
  mkdir /bind && \
  chown -Rfv www-data: /dnsapi/ /var/www/ /bind && \
  su www-data --shell=/bin/bash -c "cd /dnsapi; /usr/bin/composer install"
