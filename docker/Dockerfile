# Tiny scheduler for UK Grid: Live+. Every few minutes it asks the live
# site to fetch whatever data is due (cron/run-due.php). It holds no data
# and no database details - only the site address and the cron secret.
FROM alpine:3.20
RUN apk add --no-cache curl tzdata
COPY run.sh /run.sh
RUN chmod +x /run.sh && adduser -D -H scheduler
USER scheduler
CMD ["/run.sh"]
