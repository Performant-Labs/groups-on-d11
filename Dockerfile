FROM drupal:11-php8.3-fpm-alpine AS base

# php-fpm only — reverse proxying is handled by the shared host nginx on Spiderman

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

# Expose php-fpm port
EXPOSE 9000

# Entrypoint generates settings.php from env vars, then starts php-fpm
COPY deploy/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
