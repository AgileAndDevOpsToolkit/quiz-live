FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        pkg-config \
    && docker-php-ext-install pdo_sqlite \
    && a2enmod headers \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache.conf /etc/apache2/conf-available/quizlive.conf
RUN a2enconf quizlive
WORKDIR /var/www/html
COPY . .
RUN chmod +x /var/www/html/docker/start.sh

ENTRYPOINT ["/var/www/html/docker/start.sh"]
CMD ["apache2-foreground"]
