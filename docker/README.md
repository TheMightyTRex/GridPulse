# Data scheduler (Docker)

The hosting package has no cron, so this small container asks the live
site to fetch whatever data is due, every 5 minutes. The site does the
fetching itself and writes to its own database. The container holds no
data and no database details.

It runs alongside the GitHub Actions workflow
(`.github/workflows/refresh-data.yml`) and the site's own page-load
refresh. They stay in sync because all three:

- check the same record (`ingest_log`) to see what is due, so whoever
  goes first does the work and the others find nothing to do;
- use the same database locks, so two can never fetch the same source at
  once;
- save every reading by its own timestamp, so fetching the same half hour
  twice changes nothing.

If this computer is off, GitHub Actions and page loads carry on.

## 1. Choose a secret (once)

In PowerShell on your PC, make a long random value:

    -join ((48..57)+(65..90)+(97..122) | Get-Random -Count 48 | ForEach-Object {[char]$_})

Put it in `includes/config.php` on the server (File Manager or FTP):

    'cron_http_secret' => 'the-value-you-made',

Use the same value in step 2 and in the GitHub secret `UKGRID_CRON_SECRET`.
Keep it private: anyone with it can make the site fetch data (nothing more).

## 2. Start the container

In this `docker` folder:

    copy .env.example .env      (then edit .env: paste the secret)
    docker compose up -d --build

## 3. Check it is working

    docker compose logs -f

Every 5 minutes you should see a block ending `done in ...s`, listing each
source that ran (for example `ELEXON: OK, 120 rows, 2.1s`) or
`Nothing due.` A `Forbidden` line means the secret in `.env` does not
match `cron_http_secret` in config.php.

To stop it: `docker compose down`.
