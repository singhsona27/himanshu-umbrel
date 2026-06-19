FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libsodium-dev \
        libxml2-dev \
        libxslt1-dev \
        libzip-dev \
        default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        mysqli \
        opcache \
        pdo_mysql \
        soap \
        sodium \
        xsl \
        zip \
    && a2enmod rewrite headers expires \
    && rm -rf /var/lib/apt/lists/*

RUN { \
      echo "memory_limit=512M"; \
      echo "upload_max_filesize=512M"; \
      echo "post_max_size=512M"; \
      echo "max_execution_time=300"; \
      echo "max_input_vars=5000"; \
      echo "open_basedir="; \
      echo "session.auto_start=0"; \
      echo "allow_url_fopen=On"; \
      echo "display_errors=Off"; \
      echo "log_errors=On"; \
    } > /usr/local/etc/php/conf.d/karacraft-hosting.ini

RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

WORKDIR /var/www/html
