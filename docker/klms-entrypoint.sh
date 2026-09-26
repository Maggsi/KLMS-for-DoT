#!/bin/sh
# Three processes:
#   1. php-fpm (background) — handles PHP via fastcgi (not used directly by the built-in server,
#      but kept for parity with the VM's FPM setup and for the messenger's FPM-less needs)
#   2. PHP built-in server (background) — serves /app/public on port 8094
#   3. messenger worker (foreground) — the main process; when it exits, the container exits

php-fpm -D &
php -S 0.0.0.0:8094 -t /app/public /app/public/index.php &
exec php bin/console messenger:consume async --time-limit=3600
