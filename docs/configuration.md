# Configuration Reference

Protocol uses several configuration files, a separate git-based configuration repository, and encrypted secrets. This document covers all of them.

## Protocol's Own Configuration

### `config/global.php`

Global defaults for all projects. Located at `WEBROOT_DIR/config/global.php`.

```php
return [
    'shell' => [
        'outputfile' => '~/protocol_background_process.log',
    ],
    'repo_dir' => '/opt/public_html',
    'banner_file' => 'templates/banner/motd.sh'
];
```

### `config/config.php`

Local overrides specific to this machine.

```php
return [
    'env' => 'localhost-byrdziak',
];
```

Set with: `protocol config:env <name>`

**Naming conventions:**

| Pattern | Example | Use Case |
|---|---|---|
| `production` | `production` | Live production nodes |
| `staging` | `staging` | Pre-production testing |
| `localhost-<handle>` | `localhost-byrdziak` | Developer environments |
| `ci` | `ci` | CI/CD pipeline |

### Precedence

1. `config/global.php` — base defaults
2. `config/config.php` — local overrides (wins)

## Project Configuration: `protocol.json`

Each project has a `protocol.json` in its root. Created by `protocol init`. Committed to git.

### Full Schema

```json
{
    "name": "myapp",
    "project_type": "php81",
    "deployment": {
        "strategy": "release",
        "pointer": "github_variable",
        "pointer_name": "PROTOCOL_ACTIVE_RELEASE",
        "secrets": "encrypted",
        "auto_deploy": true
    },
    "docker": {
        "image": "registry/myapp:latest",
        "container_name": "myapp-web",
        "local": "../docker-source/"
    },
    "git": {
        "remote": "git@github.com:org/myapp.git",
        "remotename": "origin",
        "branch": "master"
    },
    "configuration": {
        "local": "../myapp-config",
        "remote": "git@github.com:org/myapp-config.git",
        "environments": ["production", "staging", "localhost"]
    }
}
```

**No credentials are stored in `protocol.json`.** Docker registry auth, GitHub tokens, and application secrets are handled through environment variables or encrypted config files.

### `deployment` Section

| Property | Default | Description |
|---|---|---|
| `deployment.strategy` | `"branch"` | `"release"` for tag-based deployment, `"branch"` for branch-tracking |
| `deployment.pointer` | `"github_variable"` | Source for the active release identifier |
| `deployment.pointer_name` | `"PROTOCOL_ACTIVE_RELEASE"` | GitHub repository variable name |
| `deployment.secrets` | `"file"` | `"encrypted"` for AES-256-GCM, `"file"` for plaintext `.env` |
| `deployment.auto_deploy` | `true` | Whether the watcher auto-deploys or just notifies |

### `docker` Section

| Property | Description |
|---|---|
| `docker.image` | Docker image tag for pull/push |
| `docker.container_name` | Container name for exec/logs |
| `docker.local` | Path to Dockerfile source for docker:build |

### `git` Section

| Property | Description |
|---|---|
| `git.remote` | Git remote URL |
| `git.remotename` | Remote name (default: `origin`) |
| `git.branch` | Branch to track (branch mode only) |

### `configuration` Section

| Property | Description |
|---|---|
| `configuration.local` | Relative path to config repo (default: `../project-config`) |
| `configuration.remote` | Git remote URL for config repo |
| `configuration.environments` | Array of available environment branch names |

### Example: Production

```json
{
    "name": "myapp",
    "project_type": "php81",
    "deployment": {
        "strategy": "release",
        "pointer": "github_variable",
        "pointer_name": "PROTOCOL_ACTIVE_RELEASE",
        "secrets": "encrypted",
        "auto_deploy": true
    },
    "docker": {
        "image": "registry/myapp:latest",
        "container_name": "myapp-web"
    },
    "git": {
        "remote": "git@github.com:org/myapp.git",
        "branch": "master"
    },
    "configuration": {
        "remote": "git@github.com:org/myapp-config.git"
    }
}
```

### Example: Local Development

```json
{
    "name": "myapp",
    "project_type": "php81",
    "deployment": {
        "strategy": "branch",
        "secrets": "file"
    },
    "docker": {
        "image": "registry/myapp:latest",
        "container_name": "myapp-web"
    },
    "git": {
        "remote": "git@github.com:org/myapp.git",
        "branch": "master"
    },
    "configuration": {
        "remote": "git@github.com:org/myapp-config.git"
    }
}
```

## Runtime State: `protocol.lock`

Gitignored. Not manually edited.

```json
{
    "release": {
        "current": "v1.2.0",
        "previous": "v1.1.0",
        "deployed_at": "2024-01-15T10:30:01Z"
    },
    "release.slave": {
        "pid": 12345,
        "interval": 60
    },
    "slave": {
        "pid": null
    },
    "configuration": {
        "active": "production",
        "symlinks": ["/opt/myapp/nginx.conf"],
        "slave": { "pid": 12346 }
    }
}
```

## Configuration Repository

Stores environment-specific files in a separate git repo. Each branch = one environment.

### Directory Layout

```
/path/to/
├── myapp/                  # Application repository
│   ├── protocol.json
│   ├── src/
│   ├── nginx.conf -> ../myapp-config/nginx.conf  (symlink)
│   └── docker-compose.yml
│
└── myapp-config/           # Configuration repository
    ├── .env.enc            # Encrypted secrets
    ├── nginx.conf          # Non-secret config
    ├── php.ini             # Non-secret config
    ├── cron.d/             # Cron definitions
    └── .git/
        └── refs/heads/
            ├── production
            ├── staging
            └── localhost-dev
```

### What Goes Where

| File Type | Storage | Example |
|---|---|---|
| Application secrets | `.env.enc` (encrypted in config repo) | API keys, DB passwords, JWT secrets |
| Server configuration | Config repo (plaintext, committed) | nginx.conf, php.ini, cron schedules |
| Docker configuration | App repo (committed) | docker-compose.yml, Dockerfile |
| Project metadata | App repo (committed) | protocol.json |
| Runtime state | App directory (gitignored) | protocol.lock |

### Docker Volume Mounting

Mount the config repo as a Docker volume so relative symlinks resolve inside the container:

```yaml
services:
  web:
    volumes:
      - '.:/var/www/html:rw'
      - '../myapp-config/:/var/www/myapp-config:rw'
```

`.env.enc` is NOT symlinked — it's decrypted at deploy time and injected as Docker environment variables.

### Workflow

```bash
# Initial setup
protocol config:env production
protocol config:init

# Move config files into the config repo
protocol config:mv nginx.conf
protocol config:mv php.ini

# Encrypt secrets
vim ../myapp-config/.env           # edit secrets
protocol secrets:encrypt           # creates .env.enc
rm ../myapp-config/.env            # remove plaintext

# Save and push
protocol config:save

# Link non-secret configs into the app
protocol config:link

# Switch environments
protocol config:switch staging
```

## Secrets Configuration

### Setup

```bash
# Generate a new encryption key (first node)
protocol secrets:setup
# Output: key string to share with other nodes

# On other nodes, use the same key
protocol secrets:setup "base64-key-string"
```

### Encrypting

```bash
protocol secrets:encrypt                    # encrypts .env → .env.enc
protocol secrets:decrypt                    # displays decrypted (for debugging)
```

### How Secrets Reach Docker

1. `.env.enc` pulled from config repo (encrypted in git)
2. Decrypted in PHP memory using `~/.protocol/key`
3. Written to `/dev/shm/` (RAM-backed tmpfs) with `0600` permissions
4. `docker compose --env-file /dev/shm/protocol_env_XXXXX up -d`
5. Temp file immediately deleted
6. Secrets exist only in container RAM

### Local Dev (No Encryption)

With `"secrets": "file"`, `.env` in the config repo is used directly. No encryption, no tmpfs. This is the default for branch mode.

### Key Rotation

1. Generate new key: `protocol secrets:setup`
2. Re-encrypt: `protocol secrets:encrypt`
3. Commit: `protocol config:save`
4. Distribute new key to nodes: `protocol secrets:setup "new-key"` on each

## GitHub Repository Variables

### Setup

```bash
gh variable set PROTOCOL_ACTIVE_RELEASE --body "v1.0.0" --repo org/myapp
```

### Deploy

```bash
# Create release
git tag v1.2.0 && git push origin v1.2.0
gh release create v1.2.0 --title "v1.2.0"

# Test on staging
protocol deploy v1.2.0

# Publish to all nodes
gh variable set PROTOCOL_ACTIVE_RELEASE --body "v1.2.0" --repo org/myapp
```

### Rollback

```bash
gh variable set PROTOCOL_ACTIVE_RELEASE --body "v1.1.0" --repo org/myapp
```

### Authentication

1. **`gh` CLI** (preferred) — zero config if already authenticated
2. **`GITHUB_TOKEN` env var** — fallback for API calls via `knplabs/github-api`
