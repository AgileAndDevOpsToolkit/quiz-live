#!/bin/sh
set -eu
mkdir -p /var/www/storage
php /var/www/html/bin/init-db.php
chown -R www-data:www-data /var/www/storage
exec "$@"
