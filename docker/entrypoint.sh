#!/bin/sh
set -e

# APP_KEY bootstrap: if APP_KEY is empty, try to load from persistent storage
# or generate a new one and save it there
if [ -z "$APP_KEY" ]; then
	KEY_FILE="/app/storage/app/app.key"

	# Ensure the storage directory exists and is writable
	mkdir -p "$(dirname "$KEY_FILE")"
	chmod 755 "$(dirname "$KEY_FILE")"

	if [ -f "$KEY_FILE" ]; then
		# Load existing key from storage
		APP_KEY=$(cat "$KEY_FILE")
		export APP_KEY
	else
		# Generate a new key, save it, and export it
		APP_KEY=$(php artisan key:generate --show)
		export APP_KEY
		echo "$APP_KEY" > "$KEY_FILE"
		chmod 600 "$KEY_FILE"
	fi
fi

# Run migrations and rebuild caches (web role only, which is always true now
# since queue and scheduler run under supervisord in the same container)
php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
