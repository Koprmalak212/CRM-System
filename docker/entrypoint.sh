#!/bin/sh
# Container start: wait for the database, create it on the first start, bring it up to date on every later start, then run Apache.
# The database administrator (root) is used only here, never by the web app, which connects as the limited ops_console_app account.
set -eu

: "${CRM_DB_HOST:?CRM_DB_HOST is not set}"
: "${CRM_DB_PASS:?CRM_DB_PASS is not set}"
: "${CRM_APP_KEY:?CRM_APP_KEY is not set (generate one with: openssl rand -base64 32)}"
: "${DB_ROOT_PASSWORD:?DB_ROOT_PASSWORD is not set}"

APP=/var/www/html
as_www() { runuser -u www-data -- "$@"; }

# The storage volume is created by Docker as root: the web server's user must own it.
mkdir -p "$APP/storage"
chown -R www-data:www-data "$APP/storage"
chmod 750 "$APP/storage"
# Same for the profile pictures volume: people upload to it through the web server
chown www-data:www-data "$APP/assets/avatars"

if [ "${1:-}" = "apache2-foreground" ]; then
  export SETUP_DB_HOST="$CRM_DB_HOST" SETUP_DB_USER=root SETUP_DB_PASS="$DB_ROOT_PASSWORD" SETUP_APP_HOST='%'
  export MIGRATE_DB_HOST="$CRM_DB_HOST" MIGRATE_DB_USER=root MIGRATE_DB_PASS="$DB_ROOT_PASSWORD"

  echo "Waiting for the database at $CRM_DB_HOST ..."
  i=0
  until php -r 'try { new PDO("mysql:host=" . getenv("CRM_DB_HOST"), "root", getenv("DB_ROOT_PASSWORD")); } catch (Throwable $e) { exit(1); }' 2>/dev/null; do
    i=$((i + 1))
    if [ "$i" -gt 60 ]; then echo "The database did not answer after 2 minutes." >&2; exit 1; fi
    sleep 2
  done

  # Is the application database already installed?
  installed=$(php -r '$p = new PDO("mysql:host=" . getenv("CRM_DB_HOST"), "root", getenv("DB_ROOT_PASSWORD")); echo (int) (bool) $p->query("SELECT 1 FROM information_schema.tables WHERE table_schema = \"ops_console\" AND table_name = \"admins\"")->fetchColumn();')
  if [ "$installed" = "1" ]; then
    echo "Database found: bringing it up to date."
    as_www php "$APP/database/migrate.php"
  else
    echo "First start: creating the database. The first admin password is printed ONCE below (find it later with: docker compose logs web)."
    as_www php "$APP/bin/setup.php"
  fi

  # Visitors' real addresses behind a proxy you control (rate limiter and sign-in lockout)
  if [ -n "${CRM_TRUSTED_PROXY:-}" ]; then
    printf 'RemoteIPHeader X-Forwarded-For\nRemoteIPInternalProxy %s\n' "$CRM_TRUSTED_PROXY" > /etc/apache2/conf-available/crm-remoteip.conf
    a2enconf crm-remoteip >/dev/null
  fi
fi

exec docker-php-entrypoint "$@"
