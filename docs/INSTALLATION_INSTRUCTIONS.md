# Installation Instructions — groups-on-d11

This document covers two things:

1. **Day-to-day operation** — how to trigger a build and deploy it to Spiderman (start here)
2. **One-time setup** — how all the pieces were wired up (do this once, then follow section 1 forever)

---

## Part 1 — Deploying to Spiderman (Day-to-Day)

### Trigger a new build

Every push to the `main` branch automatically triggers a GitHub Actions build that:

1. Builds a fresh Docker image from the repo
2. Pushes it to `ghcr.io/performant-labs/groups-on-d11:latest`

```bash
# On your local machine — merge/push to main to trigger
git checkout main
git merge aa/initial-plan   # or whatever branch has your changes
git push origin main
```

Watch the build at:
**https://github.com/Performant-Labs/groups-on-d11/actions**

A successful build takes approximately 4–8 minutes (1–2 minutes with layer caching).

### Pull and restart on Spiderman

Once the GitHub Actions build shows green:

```bash
ssh aangel@172.232.174.154

cd /opt/groups-on-d11

# Pull the new image and restart the web container only (zero DB downtime)
docker compose pull web
docker compose up -d --no-deps web

# Verify it came up
docker ps | grep groups-on-d11
docker logs groups-on-d11-web --tail 30
```

### Run Drush commands on Spiderman

```bash
ssh aangel@172.232.174.154
docker exec groups-on-d11-web drush status
docker exec groups-on-d11-web drush cr
docker exec groups-on-d11-web drush cim -y
```

---

## Part 2 — One-Time Setup

Do this once. After it is done, only Part 1 is needed for future deploys.

### 2a — Files to add to this repo

The following files need to be created and committed before the first build works.

#### `Dockerfile` (repo root)

Builds a combined php-fpm + nginx image. Pattern matches `groups-live-chat`.

```dockerfile
FROM drupal:11-php8.3-fpm-alpine AS base

RUN apk add --no-cache nginx

COPY deploy/nginx-drupal.conf /etc/nginx/http.d/default.conf

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY web/ web/
COPY config/ config/

RUN mkdir -p web/sites/default/files \
    && chown -R www-data:www-data web/sites/default/files

EXPOSE 8080

COPY deploy/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
```

> **Why `config/` is copied in**: The container reads `config/sync/` at runtime for `drush cim`. Keeping it in the image means the container is fully self-contained and config state matches the repo commit exactly.

#### `deploy/entrypoint.sh`

Generates `settings.php` from environment variables at startup, then launches php-fpm and nginx.

```sh
#!/bin/sh
set -e

SETTINGS="/var/www/html/web/sites/default/settings.php"

chmod 755 /var/www/html/web/sites/default 2>/dev/null || true

cat > "$SETTINGS" <<PHPEOF
<?php
\$databases['default']['default'] = [
  'database' => '${MYSQL_DATABASE}',
  'username' => '${MYSQL_USER}',
  'password' => '${MYSQL_PASSWORD}',
  'host'     => '${MYSQL_HOST}',
  'port'     => '3306',
  'driver'   => 'mysql',
  'prefix'   => '',
  'collation' => 'utf8mb4_general_ci',
];
\$settings['hash_salt'] = '${DRUPAL_HASH_SALT}';
\$settings['trusted_host_patterns'] = [
  '^groups\\.performantlabs\\.com$',
  '^localhost$',
];
\$settings['file_private_path'] = '/var/www/html/private';
\$settings['config_sync_directory'] = '/var/www/html/config/sync';
\$settings['reverse_proxy'] = TRUE;
\$settings['reverse_proxy_addresses'] = ['127.0.0.1'];
PHPEOF

echo "Generated settings.php"
php-fpm -D
nginx -g 'daemon off;'
```

#### `deploy/nginx-drupal.conf`

Routes HTTP requests from nginx → php-fpm inside the container. Copy from `groups-live-chat` — it is identical for any Drupal 11 site.

#### `.github/workflows/build.yml`

```yaml
name: Build and push Docker image

on:
  push:
    branches:
      - main

jobs:
  build-and-push:
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write        # required to push to ghcr.io

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Log in to GitHub Container Registry
        uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}   # built-in, no extra secret needed

      - name: Set up Docker Buildx (for layer caching)
        uses: docker/setup-buildx-action@v3

      - name: Build and push
        uses: docker/build-push-action@v5
        with:
          context: .
          push: true
          tags: ghcr.io/performant-labs/groups-on-d11:latest
          cache-from: type=gha
          cache-to: type=gha,mode=max
```

> **`GITHUB_TOKEN`** is automatically provided by GitHub Actions — no secrets to configure in the repo settings.

---

### 2b — Spiderman: create `/opt/groups-on-d11/`

```bash
ssh aangel@172.232.174.154
sudo mkdir -p /opt/groups-on-d11/deploy
cd /opt/groups-on-d11
```

#### `/opt/groups-on-d11/.env`

```bash
MYSQL_DATABASE=groups_on_d11
MYSQL_USER=drupal
MYSQL_PASSWORD=<strong-random-password>
MYSQL_ROOT_PASSWORD=<strong-random-password>
DRUPAL_HASH_SALT=<64-char-random-string>
```

Generate the hash salt with:
```bash
openssl rand -base64 48
```

#### `/opt/groups-on-d11/docker-compose.yml`

```yaml
services:
  web:
    image: ghcr.io/performant-labs/groups-on-d11:latest
    container_name: groups-on-d11-web
    restart: unless-stopped
    ports:
      - "127.0.0.1:8084:8080"          # 8084 chosen — check no conflict with other projects
    environment:
      - MYSQL_HOST=db
      - MYSQL_DATABASE=${MYSQL_DATABASE}
      - MYSQL_USER=${MYSQL_USER}
      - MYSQL_PASSWORD=${MYSQL_PASSWORD}
      - DRUPAL_HASH_SALT=${DRUPAL_HASH_SALT}
    volumes:
      - drupal-files:/var/www/html/web/sites/default/files
      - drupal-private:/var/www/html/private
    depends_on:
      db:
        condition: service_healthy
    networks:
      - internal

  db:
    image: mariadb:10.11
    container_name: groups-on-d11-db
    restart: unless-stopped
    environment:
      - MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
      - MYSQL_DATABASE=${MYSQL_DATABASE}
      - MYSQL_USER=${MYSQL_USER}
      - MYSQL_PASSWORD=${MYSQL_PASSWORD}
    volumes:
      - db-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 5
    networks:
      - internal

volumes:
  drupal-files:
  drupal-private:
  db-data:

networks:
  internal:
    driver: bridge
```

#### `/etc/nginx/sites-available/groups-on-d11` (on Spiderman host)

```nginx
server {
    server_name groups.performantlabs.com;

    access_log /var/log/nginx/groups_on_d11_access.log;
    error_log  /var/log/nginx/groups_on_d11_error.log;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        proxy_pass http://127.0.0.1:8084;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;
        proxy_read_timeout 300s;
        proxy_connect_timeout 75s;
        proxy_buffering off;
        proxy_request_buffering off;
        client_max_body_size 64M;
    }

    listen 80;
    listen [::]:80;
}
```

Enable and reload:
```bash
sudo ln -s /etc/nginx/sites-available/groups-on-d11 /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

> **No login needed.** The `groups-on-d11` repo is public, so the `ghcr.io` image is also public. Spiderman can `docker compose pull` without any token or credentials.

---

### 2c — First install (run once after the first successful image build)

```bash
ssh aangel@172.232.174.154
cd /opt/groups-on-d11

# Pull the image (first time)
docker compose pull

# Start DB first, wait for healthy
docker compose up -d db
sleep 15

# Start web
docker compose up -d web

# Install Drupal (first time only — destroys any existing DB)
docker exec groups-on-d11-web drush site:install standard \
  --db-url=mysql://${MYSQL_USER}:${MYSQL_PASSWORD}@db/${MYSQL_DATABASE} \
  --site-name="Groups on Drupal" \
  --account-name=admin \
  --account-pass=<admin-password> \
  -y

# Import config from the image
docker exec groups-on-d11-web drush cim -y
docker exec groups-on-d11-web drush cr
```

---

## Port allocation on Spiderman

Verified live as of 2026-04-05:

| Port | Project |
|---|---|
| 8065 | Mattermost |
| 8081 | almond-tts-web |
| 8082 | LimeSurvey |
| 8083 | groups-live-chat |
| **8084** | **groups-on-d11 ← this project** |
