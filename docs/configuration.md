# Configuration

Protocol uses a few files to know what to do with your project. This guide explains what they are, where they live, and how they work together.

## The Short Version

There are three things that configure Protocol:

1. **`protocol.json`** — Lives in your project. Tells Protocol what Docker image to use, how to deploy, and where your config repo is.
2. **The config repo** — A separate git repo next to your project. Holds your `.env` files, nginx configs, cron schedules — anything that changes between environments.
3. **`~/.protocol/key`** — Lives on each machine. The encryption key for your secrets.

That's it. Everything else is derived from these three.

---

## protocol.json

This is your project's identity card. Created by `protocol init`, committed to git, shared across all machines.

Here's what a typical one looks like:

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
        "local": "../myapp-config",
        "remote": "git@github.com:org/myapp-config.git"
    }
}
```

**No credentials go in this file.** It's committed to git. Docker passwords, API tokens, and encryption keys are handled through environment variables or the encrypted config repo.

### Deployment Settings

| Setting | What it means |
|---|---|
| `strategy: "release"` | Use versioned git tags. Nodes watch a GitHub variable for the active version. **Recommended.** |
| `strategy: "branch"` | Follow the tip of a git branch. Good for local dev. No rollback. |
| `secrets: "encrypted"` | `.env` files are encrypted in git and decrypted on arrival. **Recommended for production.** |
| `secrets: "file"` | `.env` files are used as-is. Fine for local dev. |
| `pointer_name` | The GitHub repository variable that stores the active release version. Default: `PROTOCOL_ACTIVE_RELEASE` |
| `auto_deploy: true` | Nodes deploy automatically when they detect a new release. |

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
| `branch` | The branch to track (branch mode only) |

### Config Repo Settings

| Setting | What it means |
|---|---|
| `local` | Where the config repo lives relative to your project (default: `../myapp-config`) |
| `remote` | Git remote URL for the config repo |
| `environments` | List of environment names (branches) available |

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
| Runtime state | App directory (gitignored) | protocol.lock |

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

## protocol.lock

This is Protocol's scratchpad. It tracks what's happening right now — which version is deployed, what processes are running, which files are symlinked.

```json
{
    "release": {
        "current": "v1.2.0",
        "previous": "v1.1.0",
        "deployed_at": "2024-01-15T10:30:01Z"
    },
    "release.slave": {
        "pid": 12345
    },
    "configuration": {
        "active": "production",
        "symlinks": ["/opt/myapp/nginx.conf"]
    }
}
```

You never edit this file. Protocol manages it. It's gitignored because it's different on every machine.

The `previous` version is how `protocol deploy:rollback` knows where to go back to.

---

## Machine-Level Config

Each machine has two things set at the Protocol level (not per-project):

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

Stored at `~/.protocol/key` with `0600` permissions. This is the key that decrypts your `.env.enc` files. Same key on every machine.

---

## Putting It All Together

Here's what happens when `protocol start` runs on a production node:

1. Reads `protocol.json` → knows the Docker image, deploy strategy, and config repo URL
2. Reads `config/config.php` → knows this machine is `production`
3. Checks out the `production` branch of the config repo
4. Sees `.env.enc` → reads `~/.protocol/key` → decrypts to `.env`
5. Symlinks `nginx.conf`, `php.ini`, etc. into the project
6. Starts Docker with the decrypted secrets
7. Starts watching for new releases

Every machine runs the same code but gets different configs because each one checks out a different branch. That's the whole trick.
