#!/bin/sh
# The scheduled jobs of docs/INSTALL.md ("Scheduled jobs"), run from one container instead of cron.
# Each job is safe to run as often as you like, and prints one line and stops when its module is off.
set -eu

APP=/var/www/html
job() { timeout 300 runuser -u www-data -- php "$APP/bin/$1.php" || echo "$(date '+%F %T') $1 failed or timed out" >&2; }

echo "Scheduler started ($(date '+%F %T'), time zone ${TZ:-UTC})."
last_day=""
while :; do
  # sleep until the start of the next minute
  sleep $((60 - $(date +%S | sed 's/^0*//; s/^$/0/')))
  min=$(( $(date +%s) / 60 ))
  hm=$(date +%H%M)
  today=$(date +%F)

  job check_endpoints &
  job kuma_poll &
  [ $((min % 3)) -eq 0 ] && job poll_inbox &
  [ $((min % 5)) -eq 0 ] && job sync_connectors &

  # daily jobs, each once per day at its time
  if [ "$hm" = "0315" ] && [ "$last_day" != "$today" ]; then last_day="$today"; job purge & fi
  [ "$hm" = "0600" ] && job run_recurring &
  [ "$hm" = "0605" ] && job run_contracts &
  wait
done
