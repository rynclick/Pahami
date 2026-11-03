#!/bin/sh
# Container start for the app: first-run setup, then Apache.
cd /var/www/html

# The bind-mounted writable/ is root-owned on Windows hosts; Apache (and the background
# WhatsApp worker it starts) runs as www-data. Upload folders are created up front so a
# root-run `docker exec` (tests, spark) cannot create them with root-only permissions.
mkdir -p writable/uploads/demo writable/uploads/whatsapp writable/uploads/simulator
chmod -R 777 writable

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --no-progress
fi

if [ -f .env ]; then
    php spark migrate || echo "Pahami: database migration failed, check the database settings in .env"
else
    echo "Pahami: no .env found. Run: cp env.example .env (then fill in META_API_KEY) and restart."
fi

exec apache2-foreground
