#!/usr/bin/env bash
# Smoke-check the packaged plugin against the real Joomla front end.
set -euo pipefail

cd "$(dirname "$0")/../.."

export COMPOSE_FILE="${COMPOSE_FILE:-tests/Integration/compose.yml}"
PORT="${CI_JOOMLA_PORT:-8280}"
BASE_URL="http://127.0.0.1:${PORT}"
HOST_HEADER='Host: dinkytags-ci.localhost'

fetch() {
    curl --silent --show-error --fail -H "$HOST_HEADER" "$BASE_URL$1"
}

require_match() {
    local body="$1"
    local pattern="$2"
    local message="$3"

    if ! grep -Eq -- "$pattern" <<< "$body"; then
        echo "::error::$message" >&2
        exit 1
    fi
}

require_count() {
    local body="$1"
    local pattern="$2"
    local expected="$3"
    local message="$4"
    local actual

    actual="$(grep -oE -- "$pattern" <<< "$body" | wc -l | tr -d '[:space:]')"
    if [[ "$actual" != "$expected" ]]; then
        echo "::error::$message (expected ${expected}, got ${actual})" >&2
        exit 1
    fi
}

echo "Checking the home page..."
home="$(fetch '/')"
require_match "$home" '<meta property="og:type" content="website"' 'Home page has no website og:type.'
require_match "$home" '<meta property="og:title" content="DinkyTags CI"' 'Home page title is missing or wrong.'
require_match "$home" '<meta property="og:locale" content="de_DE"' 'Home page locale is missing or wrong.'
require_count "$home" '<meta property="og:image" content="[^"]+"' 1 'Home page must emit exactly one og:image.'

echo "Checking an article and its social metadata..."
article="$(fetch '/index.php?option=com_content&view=article&id=1')"
require_match "$article" '<meta property="og:type" content="article"' 'Single article has no article og:type.'
require_match "$article" '<meta property="og:title" content="FX Fulltext Image"' 'Article title is missing or wrong.'
require_match "$article" '<meta property="og:image" content="http://dinkytags-ci\.localhost/images/fx-full\.jpg"' 'Article fulltext image was not selected.'
require_match "$article" '<meta property="article:tag" content="Test Tag"' 'Article tag metadata is missing.'
require_match "$article" '<meta name="twitter:card" content="summary_large_image"' 'Twitter card metadata is missing or wrong.'
require_count "$article" '<meta property="og:image" content="[^"]+"' 1 'Article must emit exactly one og:image.'

echo "Checking query filtering preserves unrelated parameters..."
tracked="$(fetch '/index.php?option=com_content&view=article&id=1&utm_source=ci&keepme=1')"
require_match "$tracked" '<meta property="og:url" content="[^"]*keepme=1' 'og:url did not preserve the non-tracking query parameter.'
if grep -q 'og:url" content="[^"]*utm_source' <<< "$tracked"; then
    echo '::error::og:url still contains the utm_source tracking parameter.' >&2
    exit 1
fi

echo "Checking a category page..."
category="$(fetch '/index.php?option=com_content&view=category&layout=blog&id=2')"
require_match "$category" '<meta property="og:type" content="website"' 'Category page has no website og:type.'
require_match "$category" '<meta property="og:title" content="Uncategorised"' 'Category title is missing or wrong.'
require_count "$category" '<meta property="og:image" content="[^"]+"' 1 'Category page must emit exactly one og:image.'

echo "Checking component template output is skipped..."
component="$(fetch '/index.php?option=com_content&view=article&id=1&tmpl=component')"
if grep -Eq '<meta (property="og:|name="twitter:)' <<< "$component"; then
    echo '::error::tmpl=component output contains Open Graph or Twitter tags.' >&2
    exit 1
fi

echo "Checking Joomla logs for PHP warnings..."
logs="$(docker compose logs --no-color joomla 2>&1)"
if grep -Ei 'PHP (Warning|Notice|Fatal error|Parse error|Deprecated)' <<< "$logs"; then
    echo '::error::Joomla logged a PHP warning, notice, fatal or deprecation.' >&2
    exit 1
fi

echo 'DinkyTags integration smoke checks passed.'
