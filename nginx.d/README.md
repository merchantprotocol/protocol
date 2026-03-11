# nginx.d — Nginx Configuration Overrides

Drop Nginx configuration files here to customize the web server
inside the container.

## How it works

This directory is mounted at `/var/www/html/nginx.d/` inside the
container. The base Nginx config is baked into the Docker image —
files here override or extend it.

## Common uses

- **Custom virtual host**: Add a `.conf` file with a `server {}` block
- **SSL certificates**: Place cert files here and reference them in config
- **Rewrites / redirects**: Add rewrite rules in a `.conf` file

## Example

```nginx
# nginx.d/custom-headers.conf
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
```

Files are NOT auto-included. Include them from your main config
or replace the default site config entirely.
