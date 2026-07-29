FROM drupal:11-php8.3-fpm-alpine AS base

# nginx serves the docroot; bash runs the assemble/seed shell scripts;
# git + unzip let composer fetch/extract packages (some betas are source-only);
# mariadb-client provides the `mysql` binary used for the DB-readiness wait.
RUN apk add --no-cache nginx bash git unzip mariadb-client

# Raise PHP's memory limit above the 128M default (issue #284).
#
# The upstream drupal:*-fpm-alpine images ship NO active php.ini at all (only
# the unused php.ini-development / php.ini-production templates), so PHP runs
# on its hardcoded 128M default. That is not enough for this project's
# `drush config:import`: config/sync carries 239+ objects (custom fields,
# views, group roles, message templates, flags), and the import exhausted the
# limit partway through with
#   Fatal error: Allowed memory size of 134217728 bytes exhausted
#       ... in Drupal/Component/Serialization/PhpSerialize.php
# on a fresh-database production deploy (2026-07-29).
#
# That failure was silent in the worst way: deploy/entrypoint.sh decides
# whether to install/seed by checking `drush status --field=bootstrap`, which
# still reports success after a partially-applied config:import — so the
# container came up serving a half-configured site with no retry. Baking the
# limit into the image is what makes a fresh deploy reproducible; the
# hand-patched conf.d file used to recover that incident lived only in the
# running container and would not survive the next redeploy.
#
# 256M is headroom over the 160M that proved empirically sufficient. The `zz-`
# prefix sorts last in conf.d so this wins over anything set earlier.
RUN printf 'memory_limit = 256M\n' > /usr/local/etc/php/conf.d/zz-groups-on-d11.ini

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
RUN bash scripts/ci/assemble-config.sh \
 && bash scripts/ci/assemble-libraries.sh

RUN mkdir -p web/sites/default/files web/sites/default/private private \
    && chown -R www-data:www-data web/sites web/sites/default/files web/sites/default/private private \
    && mkdir -p /run/nginx

# Internal nginx config: nginx listens on 8080, proxies PHP to php-fpm on 9000
COPY deploy/nginx-drupal.conf /etc/nginx/http.d/default.conf

EXPOSE 8080

COPY deploy/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
