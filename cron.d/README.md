# cron.d — Cron Job Scripts

Place shell scripts here to run as scheduled tasks inside the
container.

## How it works

This directory is mounted at `/var/www/html/cron.d/` inside the
container. The Docker image includes a crontab that runs
`/opt/scripts/runcron.sh` which executes scripts from this
directory.

## Adding a cron job

1. Create a `.sh` script in this directory
2. Make it executable: `chmod +x cron.d/myscript.sh`
3. The container's crontab will pick it up automatically

## Example

```bash
#!/bin/bash
# cron.d/clear-cache.sh
cd /var/www/html && php artisan cache:clear
```
