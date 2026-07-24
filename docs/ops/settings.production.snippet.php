<?php

/**
 * REL-4 (#213) — production Drupal settings snippet for observability.
 *
 * Append to `web/sites/default/settings.php` in the deployed image (Coolify
 * on Uranus). Not committed at that path here because production settings
 * live in the deployment image, not the source tree — this snippet is the
 * source of truth documented for ops to apply/verify.
 *
 * What it does:
 *   1. Routes Drupal's core `syslog` module output to stdout via PHP's
 *      `error_log()` (which the CLI/PHP-FPM SAPI writes to fd 2). Coolify
 *      captures the container's stdout/stderr; alloy on Uranus tails and
 *      forwards them to Loki.
 *   2. Sets a stable `syslog_identity` so Loki labels are grep-able per
 *      environment.
 *
 * Requires:
 *   - Core `syslog` module enabled in production (`drush en syslog -y`).
 *     Not added to the assembled `core.extension.yml` in this repo because
 *     CI's throwaway sites do not need it (kernel/functional tests would
 *     write extra noise); it is a production-only enable.
 */

// Route syslog to PHP's error log (stdout in containerized FPM/CLI).
$config['syslog.settings']['facility'] = LOG_LOCAL0;
$config['syslog.settings']['identity'] = 'groups-on-d11-' . ($_ENV['DDEV_PROJECT'] ?? 'prod');
// Newer core syslog exposes a format token — keep the default level+ip+message
// shape so the Loki parser in alloy stays stable.
// $config['syslog.settings']['format'] = '!base_url|!timestamp|!type|!ip|!request_uri|!referer|!uid|!link|!message';
