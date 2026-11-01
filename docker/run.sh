#!/bin/sh
# Calls the site's cron/run-due.php every INTERVAL_SECONDS (default 300).
# The site decides what is due, so calling it more often than a source
# needs is harmless: it simply answers "Nothing due".
set -u
: "${SITE_URL:?Set SITE_URL in docker/.env}"
: "${CRON_SECRET:?Set CRON_SECRET in docker/.env}"
INTERVAL_SECONDS="${INTERVAL_SECONDS:-300}"
URL="${SITE_URL%/}/cron/run-due.php?caller=docker"

echo "Scheduler started: calling ${SITE_URL%/}/cron/run-due.php every ${INTERVAL_SECONDS}s"
while true; do
  echo "--- $(date -u '+%Y-%m-%d %H:%M:%S') UTC"
  code=$(curl --silent --show-error --max-time 120 -o /tmp/response.txt -w "%{http_code}" \
    -H "X-Cron-Secret: ${CRON_SECRET}" \
    -H "User-Agent: gridpulse-scheduler/docker" \
    --data-urlencode "secret=${CRON_SECRET}" \
    "$URL") || code="000"
  cat /tmp/response.txt 2>/dev/null; echo
  case "$code" in
    200) grep -q "^done in" /tmp/response.txt || echo "The run started but did not finish (see above). Upload the whole includes/ folder from the latest zip." ;;
    000) echo "HTTP $code: could not reach the site. Trying again next time." ;;
    403) echo "HTTP 403: the secret in .env does not match cron_http_secret in config.php (or it is not set there)." ;;
    404) echo "HTTP 404: cron/run-due.php is not on the server yet, or SITE_URL is wrong." ;;
    *)   echo "HTTP $code: unexpected response. Trying again next time." ;;
  esac
  sleep "$INTERVAL_SECONDS"
done
