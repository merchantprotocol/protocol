# Configuration

Protocol uses a few files to know what to do with your project. This guide explains what they are, where they live, and how they work together.

## The Short Version

There are four things that configure Protocol:

1. **`protocol.json`** — Lives in your project. Tells Protocol what Docker image to use, how to deploy, and where your config repo is.
2. **The config repo** — A separate git repo next to your project. Holds your `.env` files, nginx configs, cron schedules — anything that changes between environments.
3. **`~/.protocol/.node/key`** — Lives on each machine. The encryption key for your secrets.
4. **`~/.protocol/.node/nodes/`** — On slave nodes only. Stores per-node deployment settings that persist across blue-green directory swaps.

For most projects, you only need the first three. Node config is created automatically when you set up a slave node via `protocol init`.

---

## protocol.json

This is your project's identity card. Created by `protocol init`, committed to git, shared across all machines.

Here's what a typical one looks like:

```json
{
    "name": "myapp",
    "project_type": "php82",
    "deployment": {
        "strategy": "release",
        "pointer": "github_variable",
        "pointer_name": "PROTOCOL_ACTIVE_RELEASE",
        "secrets": "encrypted"
    },
    "docker": {
        "image": "registry/myapp:latest",
        "container_name": "myapp-web"
    },
    "git": {
        "remote": "git@github.com:org/myapp.git",
        "remotename": "origin",
        "branch": "master"
    },
    "configuration": {
        "local": "../myapp-config",
        "remote": "git@github.com:org/myapp-config.git"
    },
    "bluegreen": {
        "enabled": false,
        "auto_promote": false,
        "health_checks": []
    }
}
```

**No credentials go in this file.** It's committed to git. Docker passwords, API tokens, and encryption keys are handled through environment variables or the encrypted config repo.

### Project Settings

| Setting | What it means |
|---|---|
| `name` | Project identifier, used for naming and lookups |
| `project_type` | PHP version for the initializer: `php81`, `php82`, or `php82ffmpeg` |

### Deployment Settings

| Setting | What it means |
|---|---|
| `strategy: "release"` | Use versioned git tags. Nodes watch a GitHub variable for the active version. **Recommended.** |
| `strategy: "branch"` | Follow the tip of a git branch. Good for local dev. No rollback. |
| `pointer: "github_variable"` | How the active release version is stored. Currently only `github_variable` is supported. |
| `pointer_name` | The GitHub repository variable that stores the active release version. Default: `PROTOCOL_ACTIVE_RELEASE` |
| `secrets: "encrypted"` | `.env` files are encrypted in git and decrypted on arrival. **Recommended for production.** |
| `secrets: "file"` | `.env` files are used as-is. Fine for local dev. |

### Docker Settings

| Setting | What it means |
|---|---|
| `image` | The Docker image to pull/push |
| `container_name` | Which container to target for `docker:exec` and `docker:logs` |
| `local` | Path to your Dockerfile source (for `docker:build`) |

### Git Settings

| Setting | What it means |
|---|---|
| `remote` | Your project's git remote URL |
| `remotename` | Name of the git remote (default: `origin`) |
| `branch` | The branch to track (branch mode only) |

### Config Repo Settings

| Setting | What it means |
|---|---|
| `local` | Where the config repo lives relative to your project (default: `../myapp-config`) |
| `remote` | Git remote URL for the config repo |
| `environments` | List of environment names (branches) available |

### Blue-Green (Shadow) Settings

These settings control zero-downtime shadow deployments. See [Shadow Deployment](blue-green.md) for the full guide.

| Setting | What it means |
|---|---|
| `bluegreen.enabled` | Enable shadow deployment mode (`true`/`false`) |
| `bluegreen.auto_promote` | Automatically promote the shadow to production after health checks pass |
| `bluegreen.health_checks` | Array of health check definitions (see below) |

Note: `releases_dir` and `git_remote` are stored in NodeConfig under the `release.*` namespace, not in `protocol.json`. See [NodeConfig (Runtime State)](#nodeconfig-runtime-state) above.

Health check format:

```json
{
    "bluegreen": {
        "health_checks": [
            {"type": "http", "path": "/health", "expect_status": 200},
            {"type": "command", "command": "curl -s localhost:8080/ping", "expect_exit": 0}
        ]
    }
}
```

---

## The Config Repository

This is where the real magic happens. Your project has a sibling directory — a completely separate git repo — that stores everything specific to an environment.

```
/opt/
├── myapp/                    ← your code (one git repo)
│   ├── protocol.json
│   ├── src/
│   ├── nginx.conf → ../myapp-config/nginx.conf   (symlink!)
│   └── docker-compose.yml
│
└── myapp-config/             ← your configs (separate git repo)
    ├── .env.enc              ← encrypted secrets
    ├── nginx.conf            ← server config
    ├── php.ini               ← PHP settings
    └── cron.d/               ← cron schedules
```

### Why a Separate Repo?

Because your production `.env` and your dev `.env` are totally different files. Your production nginx config listens on port 443 with SSL. Your dev one listens on port 80 with no SSL.

If you put these in your main repo, you'd need conditionals, templating, or a directory full of variations. With Protocol, each environment is just a **branch** in the config repo.

### Branches = Environments

```
myapp-config/
└── .git/
    └── refs/heads/
        ├── production         ← production .env, nginx.conf, etc.
        ├── staging            ← staging .env, nginx.conf, etc.
        └── localhost-sarah    ← Sarah's laptop .env, nginx.conf, etc.
```

When you run `protocol config:env production`, Protocol knows to check out the `production` branch of your config repo. When you run `protocol start`, it symlinks those files into your project.

### What Goes Where

| Type of file | Where it lives | Examples |
|---|---|---|
| Application secrets | Config repo (encrypted) | `.env`, database passwords, API keys |
| Server configuration | Config repo (plaintext) | nginx.conf, php.ini, cron schedules |
| Docker configuration | App repo | docker-compose.yml, Dockerfile |
| Project metadata | App repo | protocol.json |
| Runtime state | NodeConfig + per-release `.protocol/deployment.json` | Active version, ports, container names |

### Setting It Up

```bash
# Create the config repo (the wizard handles everything)
protocol config:init

# Move files from your project to the config repo
protocol config:mv .env
protocol config:mv nginx.conf

# Encrypt your secrets
protocol config:init    # → choose "Encrypt secrets"

# Push to remote
protocol config:save
```

### Switching Environments

```bash
protocol config:switch staging
```

This saves any changes, removes the old symlinks, switches to the `staging` branch, and creates new symlinks. Your app instantly has staging configs.

### Docker Volume Mounting

For symlinks to work inside Docker, the config repo needs to be mounted as a volume:

```yaml
services:
  web:
    volumes:
      - '.:/var/www/html:rw'
      - '../myapp-config/:/var/www/myapp-config:rw'
```

This way, the relative symlinks resolve correctly inside the container.

---

## NodeConfig (Runtime State)

Runtime state is stored in NodeConfig (`~/.protocol/.node/nodes/<project>.json`) rather than in the project directory. This ensures state persists across blue-green directory swaps and isn't accidentally committed to git.

NodeConfig uses two namespaces for release and bluegreen state:

**`release.*`** — Shared release tracking (used by both release and bluegreen strategies):

| Key | Type | Description |
|---|---|---|
| `release.releases_dir` | string | Absolute path to the releases directory |
| `release.git_remote` | string | Git URL to clone releases from |
| `release.active` | string | Currently serving version |
| `release.previous` | string | Previous version (rollback target) |
| `release.target` | string | Version being built or promoted |
| `release.versions` | array | All known version tags |

**`bluegreen.*`** — Bluegreen-specific settings:

| Key | Type | Description |
|---|---|---|
| `bluegreen.shadow_version` | string | Version currently building in the shadow |
| `bluegreen.auto_promote` | boolean | Auto-promote after successful build |
| `bluegreen.health_checks` | array | Health check definitions |
| `bluegreen.promoted_at` | string | Timestamp of last promotion |

You never edit NodeConfig directly. Protocol manages it. The `release.previous` version is how `protocol deploy:rollback` knows where to go back to.

### Per-Release State (deployment.json)

Each release directory stores its own runtime state in `<release_dir>/<version>/.protocol/deployment.json`:

```json
{
    "port": 80,
    "status": "serving",
    "container_name": "myapp-v1_2_0",
    "deployed_at": "2024-01-15T10:30:01Z",
    "watcher_pid": 12345
}
```

This file is auto-generated during `shadow:build` and updated during promotions and rollbacks.

---

## Machine-Level Config

Each machine has several things set at the Protocol level (not per-project):

### Environment Name

Set once per machine:

```bash
protocol config:env production
```

Stored in Protocol's own config at `config/config.php`. This tells Protocol which config repo branch to use.

Common patterns:
- `production` — live servers
- `staging` — pre-production
- `localhost-sarah` — Sarah's laptop
- `ci` — CI/CD pipeline

### Encryption Key

Set once per machine:

```bash
protocol secrets:setup "your-64-char-hex-key"
```

Stored at `~/.protocol/.node/key` with `0600` permissions. This is the key that decrypts your `.env.enc` files. Same key on every machine.

### Node Config (`~/.protocol/.node/nodes/`)

When a server is set up as a slave/deployment node via `protocol init`, its configuration is stored in `~/.protocol/.node/nodes/<project>.json`. This is separate from the project's `protocol.json` so that blue-green deployments can swap directories without losing track of settings.

```json
{
    "name": "myapp",
    "node_type": "slave",
    "environment": "production",
    "repo_dir": "/opt/myapp",
    "git": {
        "remote": "git@github.com:org/myapp.git"
    },
    "deployment": {
        "strategy": "release",
        "pointer": "github_variable",
        "pointer_name": "PROTOCOL_ACTIVE_RELEASE"
    },
    "release.releases_dir": "/opt/myapp-releases",
    "release.git_remote": "git@github.com:org/myapp.git",
    "release.active": "v1.2.0",
    "release.previous": "v1.1.0",
    "release.target": null,
    "release.versions": ["v1.1.0", "v1.2.0"],
    "bluegreen.enabled": true,
    "bluegreen.shadow_version": null,
    "bluegreen.auto_promote": true,
    "bluegreen.health_checks": [
        {"type": "http", "path": "/health", "expect_status": 200}
    ],
    "bluegreen.promoted_at": "2026-03-10T21:00:00+00:00"
}
```

Node config files are created with `0600` permissions and the directory with `0700`. They persist across deploys and directory swaps.

---

## Putting It All Together

Here's what happens when `protocol start` runs on a production node:

1. Reads `protocol.json` → knows the Docker image, deploy strategy, and config repo URL
2. Reads `config/config.php` → knows this machine is `production`
3. Checks out the `production` branch of the config repo
4. Sees `.env.enc` → reads `~/.protocol/.node/key` → decrypts to `.env`
5. Symlinks `nginx.conf`, `php.ini`, etc. into the project
6. Starts Docker with the decrypted secrets
7. Starts watching for new releases

Every machine runs the same code but gets different configs because each one checks out a different branch. That's the whole trick.
