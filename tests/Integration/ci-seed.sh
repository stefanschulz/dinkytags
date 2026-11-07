#!/usr/bin/env bash
# Provisions an ephemeral Joomla stack and installs the exact ZIP built by CI.
set -euo pipefail

cd "$(dirname "$0")/../.."

export COMPOSE_FILE="${COMPOSE_FILE:-tests/Integration/compose.yml}"
PORT="${CI_JOOMLA_PORT:-8280}"
TIMEOUT="${CI_JOOMLA_TIMEOUT:-300}"
BASE_URL="http://127.0.0.1:${PORT}"
COMPOSE=(docker compose)
J() { "${COMPOSE[@]}" exec -T joomla sh -c "$1"; }

shopt -s nullglob
PACKAGES=(.releases/plg_system_dinkytags-*.zip)
if [[ ${#PACKAGES[@]} -ne 1 ]]; then
    echo "Expected one package in .releases/, found ${#PACKAGES[@]}." >&2
    exit 1
fi
PACKAGE="${PACKAGES[0]}"
PACKAGE_NAME="$(basename "$PACKAGE")"

"${COMPOSE[@]}" up -d

echo "Waiting for Joomla and Apache (up to ${TIMEOUT}s)..."
deadline=$((SECONDS + TIMEOUT))
until J '[ -f /var/www/html/installation/joomla.php ]' 2>/dev/null \
    && curl --silent --output /dev/null "$BASE_URL/"; do
    if (( SECONDS > deadline )); then
        echo "Joomla did not start in time." >&2
        "${COMPOSE[@]}" logs --tail=60 joomla >&2 || true
        exit 1
    fi
    sleep 2
done

echo "Installing Joomla (headless)..."
J 'cd /var/www/html && php installation/joomla.php install \
     --site-name="DinkyTags CI" \
     --admin-user="Admin User" --admin-username=admin --admin-password="admin1234secure" --admin-email=admin@example.com \
     --db-type=mysqli --db-host=db --db-user=joomla --db-pass=joomlapw --db-name=joomla --db-prefix=jos_ \
     -n'

echo "Fixing Joomla writable-directory ownership..."
J 'chown -R www-data:www-data /var/www/html/cache /var/www/html/administrator/cache /var/www/html/tmp /var/www/html/administrator/logs
   chown www-data:www-data /var/www/html/configuration.php'

echo "Generating placeholder images..."
docker compose exec -T joomla sh -c 'cd /var/www/html && php' <<'PHP'
<?php
foreach ([
    ['images/og-default.png', 1200, 630, [40, 80, 160]],
    ['images/fx-full.jpg', 1200, 675, [200, 60, 60]],
    ['images/fx-intro.jpg', 800, 600, [60, 160, 90]],
    ['images/fx-inline.jpg', 600, 400, [160, 120, 40]],
    ['images/fx-small.png', 200, 200, [120, 120, 120]],
] as [$path, $width, $height, $color]) {
    if (is_file($path)) {
        continue;
    }

    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, $color[0], $color[1], $color[2]));
    str_ends_with($path, '.png') ? imagepng($image, $path) : imagejpeg($image, $path, 85);
}
PHP
J 'chown -R www-data:www-data /var/www/html/images'

echo "Installing ${PACKAGE_NAME}..."
container_id="$("${COMPOSE[@]}" ps -q joomla)"
docker cp "$PACKAGE" "${container_id}:/tmp/${PACKAGE_NAME}"
J "cd /var/www/html && php cli/joomla.php extension:install --path=/tmp/${PACKAGE_NAME} -n"
J 'cd /var/www/html && php cli/joomla.php extension:list 2>/dev/null | grep -q plg_system_dinkytags' \
    || { echo "DinkyTags was not registered after installation." >&2; exit 1; }

# Force Joomla to rebuild the namespace map for the installed plugin before first dispatch.
J 'rm -f /var/www/html/administrator/cache/autoload_psr4.php'

echo "Loading article and tag fixtures..."
"${COMPOSE[@]}" exec -T db sh -c 'mariadb -ujoomla -pjoomlapw joomla' \
    < tests/Integration/fixtures.sql
J 'cd /var/www/html && php cli/joomla.php cache:clean all -n >/dev/null 2>&1 || true'

echo "Waiting for the frontend..."
deadline=$((SECONDS + TIMEOUT))
until curl --silent --fail --output /dev/null -H 'Host: dinkytags-ci.localhost' "$BASE_URL/"; do
    if (( SECONDS > deadline )); then
        echo "The Joomla frontend did not respond in time." >&2
        "${COMPOSE[@]}" logs --tail=100 >&2 || true
        exit 1
    fi
    sleep 3
done

echo "Ready: Joomla ${JOOMLA_TAG:-5}, DinkyTags installed from ${PACKAGE_NAME}."
