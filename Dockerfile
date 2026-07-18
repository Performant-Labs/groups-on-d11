FROM drupal:11-php8.3-fpm-alpine AS base

# nginx serves the docroot; bash runs the assemble/seed shell scripts;
# git + unzip let composer fetch/extract packages (some betas are source-only);
# mariadb-client provides the `mysql` binary used for the DB-readiness wait.
RUN apk add --no-cache nginx bash git unzip mariadb-client

WORKDIR /var/www/html

# Install PHP dependencies first for better layer caching.
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Application code + the runbook assets the assemble/seed steps rely on.
COPY web/ web/
COPY config/ config/
COPY scripts/ scripts/
COPY docs/groups/ docs/groups/

# Assemble the phase-2..7 config + custom do_* modules into config/sync and
# web/modules/custom. Single source of truth shared with the RUNBOOK and CI
# (.github/workflows/test.yml). Without it the image ships only the Phase-1
# baseline config and an empty web/modules/custom, so the seeded site cannot be
# reconstructed on a fresh database.
RUN bash scripts/ci/assemble-config.sh

RUN mkdir -p web/sites/default/files web/sites/default/private private \
    && chown -R www-data:www-data web/sites web/sites/default/files web/sites/default/private private \
    && mkdir -p /run/nginx

# Internal nginx config: nginx listens on 8080, proxies PHP to php-fpm on 9000
COPY deploy/nginx-drupal.conf /etc/nginx/http.d/default.conf

EXPOSE 8080

COPY deploy/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
