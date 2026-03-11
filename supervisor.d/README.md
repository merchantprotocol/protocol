# supervisor.d — Supervisor Program Configs

Add Supervisor configuration files here to run additional
background processes inside the container.

## How it works

This directory is mounted at `/etc/supervisor/conf.d/custom/`
inside the container. Supervisor automatically picks up `.conf`
files and manages the processes.

## Built-in programs

The Docker image already runs these via Supervisor:
- `php-fpm` — PHP FastCGI process manager
- `nginx` — Web server
- `cron` — Cron daemon

## Adding a worker

Create a `.conf` file following Supervisor's format:

```ini
; supervisor.d/queue-worker.conf
[program:queue-worker]
command=php /var/www/html/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
numprocs=1
stdout_logfile=/var/log/queue-worker.log
stderr_logfile=/var/log/queue-worker-error.log
```

## Common uses

- Queue workers (Laravel, Symfony Messenger, etc.)
- WebSocket servers
- Long-running daemons
- Log processors
