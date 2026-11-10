#!/bin/sh
set -eu

# Laravel passes sslmode to PDO, but not channel_binding. libpq reads this env.
# URL query options take precedence, just as they do in Laravel's URL parser.
PGCHANNELBINDING="$(php -r '
    $url = getenv("DATABASE_URL") ?: getenv("DB_URL") ?: "";
    parse_str(parse_url($url, PHP_URL_QUERY) ?: "", $query);
    $binding = $query["channel_binding"] ?? (getenv("PGCHANNELBINDING") ?: "prefer");
    if (!in_array($binding, ["require", "prefer", "disable"], true)) {
        fwrite(STDERR, "Invalid PostgreSQL channel_binding setting.\n");
        exit(1);
    }
    echo $binding;
')"
export PGCHANNELBINDING

# No migrations, key generation or build-time configuration cache.
exec docker-php-entrypoint "$@"
