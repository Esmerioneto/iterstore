#!/bin/bash
# Runs bin/mercadopago-sync.php every 5 seconds for 55 seconds (cron can't go below a minute).
# flock: a sync still running when the next minute starts skips that minute instead of doubling up.
#
#     * * * * * /caminho/do/iterstore/bin/mercadopago-sync-loop.sh
#
# The output goes to var/log/mercadopago-sync.log.

DIR="$(cd "$(dirname "$0")/.." && pwd)"
exec >> "$DIR/var/log/mercadopago-sync.log" 2>&1
exec 9> /tmp/mercadopago-sync.lock
flock -n 9 || exit 0

end=$((SECONDS + 55))
while [ $SECONDS -lt $end ]; do
    php -d xdebug.mode=off "$DIR/bin/mercadopago-sync.php"
    sleep 5
done
