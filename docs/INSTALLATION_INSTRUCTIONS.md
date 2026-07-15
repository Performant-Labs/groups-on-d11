# Installation Instructions — groups-on-d11

This document covers two things:

1. **Day-to-day operation** — how to trigger a build and deploy it to Spiderman (start here)
2. **One-time setup** — how all the pieces were wired up (do this once, then follow section 1 forever)

### Architecture

```
groups.performantlabs.com
        │
   Host nginx on Spiderman  (nginx/1.24.0, shared across all projects)
   /etc/nginx/sites-enabled/groups-on-d11
        │  fastcgi_pass 127.0.0.1:8084
        ▼
   Container: groups-on-d11-web  (php-fpm only, port 9000 → 127.0.0.1:8084)
        │
   Container: groups-on-d11-db  (MariaDB, internal network only)
```

The container runs **php-fpm only** — there is no nginx inside the image. The shared host nginx on Spiderman handles TLS termination, routing, and static file serving via `fastcgi_pass`.

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

Builds a **php-fpm only** image. No nginx — the shared host nginx on Spiderman handles reverse proxying.

```dockerfile
FROM drupal:11-php8.3-fpm-alpine AS base

# php-fpm only — reverse proxying is handled by the shared host nginx on Spiderman

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY web/ web/
COPY config/ config/

RUN mkdir -p web/sites/default/files web/sites/default/private \
    && chown -R www-data:www-data web/sites web/sites/default/files

EXPOSE 9000

COPY deploy/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
```

> **Why `config/` is copied in**: The container reads `config/sync/` at runtime for `drush cim`. Keeping it in the image means the container is fully self-contained and config state matches the repo commit exactly.

#### `deploy/entrypoint.sh`

Generates `settings.php` from environment variables at startup, then starts php-fpm in the foreground.

```sh
#!/bin/sh
set -e

SETTINGS="/var/www/html/web/sites/default/settings.php"

chmod 755 /var/www/html/web/sites/default 2>/dev/null || true

cat > "$SETTINGS" <<PHPEOF
<?php
\$databases['default']['default'] = [
  'database'  => '${MYSQL_DATABASE}',
  'username'  => '${MYSQL_USER}',
  'password'  => '${MYSQL_PASSWORD}',
  'host'      => '${MYSQL_HOST}',
  'port'      => '3306',
  'driver'    => 'mysql',
  'prefix'    => '',
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

echo "Generated settings.php from environment variables"

# Start php-fpm in the foreground — host nginx on Spiderman proxies to this
exec php-fpm -F
```

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
      - "127.0.0.1:8084:9000"   # php-fpm port; host nginx connects via fastcgi_pass
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

Copy `deploy/nginx-host-site.conf` from this repo, then enable it:

```nginx
server {
    server_name groups.performantlabs.com;

    access_log /var/log/nginx/groups_on_d11_access.log;
    error_log  /var/log/nginx/groups_on_d11_error.log;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    root /var/www/html/web;   # path inside the container; used by fastcgi_param
    index index.php index.html;
    client_max_body_size 64M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ '\.php$|^/update.php' {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        # Forward directly to php-fpm in the container
        fastcgi_pass 127.0.0.1:8084;
        fastcgi_param SCRIPT_FILENAME /var/www/html/web$fastcgi_script_name;
        fastcgi_param SCRIPT_NAME $fastcgi_script_name;
        fastcgi_param SERVER_NAME $host;
        fastcgi_param HTTPS on;
        fastcgi_index index.php;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
        fastcgi_read_timeout 600;
        include fastcgi_params;
    }

    location ~* /\.(?!well-known\/) { deny all; }

    listen 80;
    listen [::]:80;
    # SSL added automatically by Certbot — see step below
}
```

Enable and get a certificate:
```bash
sudo cp /opt/groups-on-d11/deploy/nginx-host-site.conf /etc/nginx/sites-available/groups-on-d11
sudo ln -s /etc/nginx/sites-available/groups-on-d11 /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# Issue SSL certificate (Certbot will edit the vhost automatically)
sudo certbot --nginx -d groups.performantlabs.com
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
