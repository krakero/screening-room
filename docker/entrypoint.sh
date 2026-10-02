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

# Only run bootstrap (dump, migrate, caches) when starting supervisord
# For other commands (e.g. php artisan updater:run), just exec them
if [ "$1" != "supervisord" ]; then
	exec "$@"
fi

# State directory for updater
UPDATER_DIR="/app/storage/app/updater"
# The image ships the MariaDB client, which verifies server certificates by default and
# rejects the MySQL container's self-signed one. Traffic stays on the compose network.
MYSQL_CLIENT_OPTS="--skip-ssl"
mkdir -p "$UPDATER_DIR"
mkdir -p "/app/storage/app/backups"

INSTALLED_VERSION_FILE="$UPDATER_DIR/installed-version"
BOOT_JSON="$UPDATER_DIR/boot.json"
CURRENT_VERSION="${APP_VERSION:-dev}"

# Helper function to write boot.json
write_boot_json() {
	state="$1"
	message="$2"
	dump="$3"
	timestamp=$(date -u +%Y-%m-%dT%H:%M:%SZ)
	cat > "$BOOT_JSON" <<-EOF
	{"state":"$state","version":"$CURRENT_VERSION","message":"$message","dump":$dump,"at":"$timestamp"}
	EOF
}

# Helper function to sanitize version for filename
sanitize_version() {
	echo "$1" | sed 's/[^a-zA-Z0-9._-]/_/g'
}

# Pre-update MySQL dump if version changed and migrations table exists
DUMP_FILENAME="null"
if [ -f "$INSTALLED_VERSION_FILE" ]; then
	INSTALLED_VERSION=$(cat "$INSTALLED_VERSION_FILE")
	if [ "$INSTALLED_VERSION" != "$CURRENT_VERSION" ]; then
		# Check if migrations table exists (mysql client; no framework boot needed)
		MIGRATIONS_EXIST=$(mysql $MYSQL_CLIENT_OPTS -h"$DB_HOST" -P"${DB_PORT:-3306}" -u"$DB_USERNAME" -p"$DB_PASSWORD" -N -s \
			-e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_DATABASE' AND table_name = 'migrations'" "$DB_DATABASE" 2>/dev/null || echo 0)
		if [ "$MIGRATIONS_EXIST" = "1" ]; then MIGRATIONS_EXIST=yes; else MIGRATIONS_EXIST=no; fi

		if [ "$MIGRATIONS_EXIST" = "yes" ]; then
			write_boot_json "dumping" "Creating pre-update backup" "null"

			SANITIZED_VERSION=$(sanitize_version "$CURRENT_VERSION")
			TIMESTAMP=$(date -u +%Y-%m-%d_%H%M%S)
			DUMP_FILE="/app/storage/app/backups/pre-update-${SANITIZED_VERSION}-${TIMESTAMP}.sql.gz"

			# Create the dump; check mysqldump's own status before compressing so a failed
			# dump is never mistaken for a success (a pipeline would report gzip's status)
			if mysqldump $MYSQL_CLIENT_OPTS -h"$DB_HOST" -P"${DB_PORT:-3306}" -u"$DB_USERNAME" -p"$DB_PASSWORD" \
				--single-transaction --quick "$DB_DATABASE" > /tmp/pre-update.sql && gzip -c /tmp/pre-update.sql > "$DUMP_FILE"; then
				rm -f /tmp/pre-update.sql
				DUMP_FILENAME="\"$(basename "$DUMP_FILE")\""

				# Keep only the 5 newest pre-update dumps (subshell so the cwd stays /app)
				(cd /app/storage/app/backups && ls -t pre-update-*.sql.gz 2>/dev/null | tail -n +6 | xargs -r rm -f)
			else
				rm -f /tmp/pre-update.sql "$DUMP_FILE"
				write_boot_json "failed" "Failed to create pre-update backup" "null"
				exit 1
			fi
		fi
	fi
fi

# Run migrations
write_boot_json "migrating" "Running database migrations" "$DUMP_FILENAME"

# Run migrate without a pipeline so its exit status is the one we test (POSIX sh has no pipefail)
set +e
php artisan migrate --force > /tmp/migrate.log 2>&1
MIGRATE_STATUS=$?
set -e
cat /tmp/migrate.log

if [ "$MIGRATE_STATUS" -eq 0 ]; then
	echo "$CURRENT_VERSION" > "$INSTALLED_VERSION_FILE"
	write_boot_json "ready" "Application ready" "$DUMP_FILENAME"
else
	ERROR_TAIL=$(tail -n 10 /tmp/migrate.log | tr -d '\r' | sed 's/\\/\\\\/g; s/"/\\"/g' | tr '\n\t' '  ')
	write_boot_json "failed" "Migration failed: $ERROR_TAIL" "$DUMP_FILENAME"
	exit 1
fi

# Rebuild caches
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
