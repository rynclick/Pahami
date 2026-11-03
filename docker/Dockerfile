FROM php:8.3-apache

# System libs for intl/gd/zip, poppler-utils (pdftoppm) for the PDF→PNG fallback,
# and ffmpeg to turn WhatsApp voice notes (Ogg/Opus) into the WAV that Muse Voice Transcribe accepts
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev fonts-dejavu-core unzip git poppler-utils ffmpeg \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install intl mysqli pdo_mysql zip gd \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# CI4 serves from /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && echo "upload_max_filesize=20M\npost_max_size=25M\nmemory_limit=256M\ndate.timezone=Asia/Jakarta" > /usr/local/etc/php/conf.d/pahami.ini

WORKDIR /var/www/html

# First run: composer install and migrations, then Apache (see docker/start.sh in the project).
CMD ["sh", "/var/www/html/docker/start.sh"]
