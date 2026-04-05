FROM drupal:11-php8.3-fpm-alpine AS base

# Install nginx inside the container (combined php-fpm + nginx image)
RUN apk add --no-cache nginx

# Copy nginx config
COPY deploy/nginx-drupal.conf /etc/nginx/http.d/default.conf

WORKDIR /var/www/html

# Install Composer dependencies (production only)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy application code and config
COPY web/ web/
COPY config/ config/

# Ensure files directory exists with correct ownership
RUN mkdir -p web/sites/default/files web/sites/default/private \
    && chown -R www-data:www-data web/sites web/sites/default/files

# Expose port for host nginx to proxy to
EXPOSE 8080

# Entrypoint generates settings.php from env vars, then starts php-fpm + nginx
COPY deploy/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
