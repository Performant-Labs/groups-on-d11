#!/bin/sh
set -e

# ---------------------------------------------------------------------------
# entrypoint.sh — boot the groups-on-d11 demo container.
#
# 1. Generate settings.php from the MYSQL_* / DRUPAL_* environment variables.
# 2. On a FRESH database, install Drupal, import the assembled config, enable
#    the custom do_* modules, and seed the drupal.org-themed demo data. On a
#    database that already carries an installed site, this is skipped — so the
#    container is safe to restart and can be pointed at a persistent volume.
# 3. Serve the docroot (php-fpm + nginx).
#
# The install sequence mirrors the one proven in CI (.github/workflows/test.yml
# e2e job): `site:install standard` -> `config:import` -> `drush en` -> seed.
# The previous "serve returns 302 -> /core/install.php" blocker (TODO #33) does
# not apply here: install and serving share the SAME settings.php on disk, so
# php-fpm resolves the installed database connection.
# ---------------------------------------------------------------------------

APP_DIR="/var/www/html"
SETTINGS="${APP_DIR}/web/sites/default/settings.php"
SEED_SCRIPT="${APP_DIR}/docs/groups/scripts/step_700_demo_data.php"
# vendor/bin/drush is an sh launcher — invoke it directly, NOT via `php`.
DRUSH="${APP_DIR}/vendor/bin/drush"
ADMIN_PASS="${DRUPAL_ADMIN_PASS:-admin}"

cd "${APP_DIR}"

# --- 1. Generate settings.php from environment variables --------------------
chmod 755 "${APP_DIR}/web/sites/default" 2>/dev/null || true

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
\$settings['file_private_path'] = '${APP_DIR}/private';
\$settings['config_sync_directory'] = '${APP_DIR}/config/sync';
\$settings['reverse_proxy'] = TRUE;
\$settings['reverse_proxy_addresses'] = ['127.0.0.1'];
PHPEOF

echo "[entrypoint] Generated settings.php from environment variables"

# --- 2. Wait for the database ----------------------------------------------
# Use the mysql client (PDO-independent) so this works before the site exists.
echo "[entrypoint] Waiting for database at ${MYSQL_HOST}..."
db_ready=0
i=0
while [ "$i" -lt 60 ]; do
  if mariadb --skip-ssl -h"${MYSQL_HOST}" -u"${MYSQL_USER}" -p"${MYSQL_PASSWORD}" \
       "${MYSQL_DATABASE}" -e 'SELECT 1' >/dev/null 2>&1; then
    db_ready=1
    echo "[entrypoint] Database reachable"
    break
  fi
  i=$((i + 1))
  sleep 2
done
if [ "$db_ready" -ne 1 ]; then
  echo "[entrypoint] ERROR: database not reachable after 120s" >&2
  exit 1
fi

# --- 3. Install + seed only on a fresh database (idempotent) ----------------
if $DRUSH status --field=bootstrap 2>/dev/null | grep -qi 'successful'; then
  echo "[entrypoint] Existing installed site detected — skipping install/seed"
else
  echo "[entrypoint] Fresh database — installing site, importing config, seeding demo data"

  $DRUSH site:install standard \
    --account-name=admin --account-pass="${ADMIN_PASS}" \
    --site-name='Groups on D11' -y

  # Adopt the assembled config's site UUID so config:import does not reject on
  # a UUID mismatch against the freshly installed site.
  CFG_UUID="$(awk '/^uuid:/{print $2; exit}' "${APP_DIR}/config/sync/system.site.yml" 2>/dev/null || true)"
  if [ -n "$CFG_UUID" ]; then
    $DRUSH config:set system.site uuid "$CFG_UUID" -y
  fi

  $DRUSH config:import -y

  # Belt-and-suspenders: ensure the custom modules are enabled even if the
  # imported core.extension did not turn them all on.
  $DRUSH en -y \
    do_group_extras do_group_language do_group_mission do_group_pin \
    do_multigroup do_notifications do_profile_stats do_discovery || true

  # Seed the demo data (idempotent; the "no comment field on forum" notice is
  # expected and non-fatal). The seed runs with the current user switched to
  # uid 1 so do_group_extras' entity_presave hook — which unpublishes groups
  # created by non-admins — leaves the seeded groups published. Otherwise the
  # /all-groups directory renders empty. (drush 13 has no --uid flag, so we
  # switch the account inside a small wrapper rather than on the CLI.)
  if [ -f "$SEED_SCRIPT" ]; then
    cat > /tmp/seed-as-admin.php <<PHP
<?php
\$admin = \Drupal\user\Entity\User::load(1);
if (\$admin) { \Drupal::currentUser()->setAccount(\$admin); }
require '${SEED_SCRIPT}';
PHP
    $DRUSH php:script /tmp/seed-as-admin.php || echo "[entrypoint] WARNING: seed script returned non-zero (continuing)"
  else
    echo "[entrypoint] WARNING: seed script not found at $SEED_SCRIPT" >&2
  fi

  # Provision PUBLIC group-view access. The assembled config/sync ships the
  # community_group roles with scope=individual (never auto-applied), so out of
  # the box only uid 1 can view groups. Group 3.x grants non-member/member
  # permissions via SCOPED synchronized roles, so we add three that carry
  # "view group" (+ the group_node view perms):
  #   - outsider / anonymous     -> anonymous visitors
  #   - outsider / authenticated -> logged-in non-members
  #   - insider  / authenticated -> members
  # Published groups become publicly browsable; unpublished/archived groups
  # (e.g. "Legacy Infrastructure") stay hidden (needs "view any unpublished
  # group", which these roles do not grant).
  cat > /tmp/provision-access.php <<'PHP'
<?php
use Drupal\group\Entity\GroupRole;
$perms = [
  'view group',
  'view group_node:forum entity',
  'view group_node:event entity',
  'view group_node:documentation entity',
  'view group_node:post entity',
  'view group_node:page entity',
];
$roles = [
  ['community_group-anon_view',     'Anonymous viewer', 'outsider', 'anonymous'],
  ['community_group-outsider_view', 'Outsider viewer',  'outsider', 'authenticated'],
  ['community_group-insider_view',  'Member viewer',    'insider',  'authenticated'],
];
foreach ($roles as [$id, $label, $scope, $global]) {
  if (GroupRole::load($id)) { continue; }
  GroupRole::create([
    'id' => $id, 'label' => $label, 'group_type' => 'community_group',
    'scope' => $scope, 'global_role' => $global, 'permissions' => $perms,
  ])->save();
  echo "provisioned group role $id ($scope/$global)\n";
}
PHP
  $DRUSH php:script /tmp/provision-access.php || echo "[entrypoint] WARNING: could not provision view-access roles (continuing)"

  $DRUSH cr
  echo "[entrypoint] Install + seed complete"
fi

# --- 4. Serve ---------------------------------------------------------------
mkdir -p /run/nginx
php-fpm -D
exec nginx -g 'daemon off;'
