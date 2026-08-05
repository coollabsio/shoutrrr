#!/bin/bash
# Queue worker launcher for supervisord ([program:worker] in supervisord.conf).
#
# supervisord tokenizes its `command=` with Python's shlex rather than running a
# shell, so a multi-branch inline command (nested quotes, $(), bash `%` param
# expansions, empty-string case patterns) fails to parse and the worker never
# spawns. Keeping the logic in a real script lets bash — not shlex — parse it.
#
# Derives `queue:work --memory` from PHP's actual memory_limit so the between-jobs
# recycle threshold sits below the real limit (queue:work is one long-lived process
# decoding images via GD across many jobs; a threshold at or above memory_limit
# never fires because PHP fatals first — the SHOUTRRR-D OOM). Reserve ~160MB
# headroom for one max-size decode (the 16M-pixel upload ceiling peaks at
# ~2 x 16M x 4B ~= 128MB plus buffers), floored at 64MB. Override with
# QUEUE_WORKER_MEMORY_MB. Idles when QUEUE_WORKER_ENABLED=false so a cloud
# deployment can run the worker as a separate service instead.
set -euo pipefail

if [ "${QUEUE_WORKER_ENABLED:-}" = "false" ]; then
    echo "[worker] disabled (QUEUE_WORKER_ENABLED=false); idling"
    exec sleep infinity
fi

RAW=$(php -r 'echo trim(ini_get("memory_limit"));')
case "$RAW" in
    ""|-1)  MB=512 ;;
    *[Gg])  MB=$(( ${RAW%[Gg]} * 1024 )) ;;
    *[Mm])  MB=${RAW%[Mm]} ;;
    *[Kk])  MB=$(( ${RAW%[Kk]} / 1024 )) ;;
    *)      MB=$(( RAW / 1048576 )) ;;
esac

MEM=${QUEUE_WORKER_MEMORY_MB:-$(( MB > 224 ? MB - 160 : MB * 2 / 3 ))}
if [ "$MEM" -lt 64 ]; then
    MEM=64
fi

echo "[worker] php memory_limit=${RAW}; queue:work --memory=${MEM}MB"
exec php /var/www/html/artisan queue:work --tries=3 --max-time=3600 --memory="$MEM"
