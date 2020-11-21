FROM php:7.4-apache

# Preparation
RUN apt-get update

# Tools installation
RUN apt-get install -y \
	sqlite3 \
	curl \
	nano \
	git \
	zip

RUN docker-php-ext-install \
  pcntl \ 
  pdo \ 
  pdo_pgsql \
  pgsql
  
RUN yes | pecl install xdebug-2.9.8 \
    && echo "zend_extension=$(find /usr/local/lib/php/extensions/ -name xdebug.so)" > /usr/local/etc/php/conf.d/xdebug.ini \
    && echo "xdebug.remote_enable=on" >> /usr/local/etc/php/conf.d/xdebug.ini \
    && echo "xdebug.remote_autostart=off" >> /usr/local/etc/php/conf.d/xdebug.ini

# Apache2
RUN a2enmod rewrite

# Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
COPY ./composer.json /var/www/html/
RUN composer install --no-interaction
