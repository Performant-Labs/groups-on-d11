FROM drupal:11-php8.3-fpm-alpine AS base

# Add nginx to serve static files from the container webroot
RUN apk add --no-cache nginx

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY web/ web/
COPY config/ config/

RUN mkdir -p web/sites/default/files web/sites/default/private \
    && chown -R www-data:www-data web/sites web/sites/default/files \
    && mkdir -p /run/nginx

# Internal nginx config: nginx listens on 8080, proxies PHP to php-fpm on 9000
COPY deploy/nginx-drupal.conf /etc/nginx/http.d/default.conf

EXPOSE 8080

COPY deploy/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
