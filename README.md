<p align="center">
  <h1 align="center">Protocol</h1>
  <p align="center">
    Release-based deployment & infrastructure management for Docker applications.
    <br />
    <a href="docs/architecture.md"><strong>Architecture</strong></a>
    &middot;
    <a href="docs/commands.md"><strong>Commands</strong></a>
    &middot;
    <a href="docs/configuration.md"><strong>Configuration</strong></a>
    &middot;
    <a href="docs/security.md"><strong>Security</strong></a>
    &middot;
    <a href="docs/migration.md"><strong>Migration Guide</strong></a>
  </p>
</p>

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="MIT License"></a>
  <a href="https://github.com/merchantprotocol/protocol/actions"><img src="https://github.com/merchantprotocol/protocol/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://github.com/merchantprotocol/protocol/actions"><img src="https://github.com/merchantprotocol/protocol/actions/workflows/security-scan.yml/badge.svg" alt="Security Scan"></a>
  <img src="https://img.shields.io/badge/php-8.1%2B-8892BF.svg" alt="PHP 8.1+">
  <img src="https://img.shields.io/badge/SOC2-Type%20II%20Ready-green.svg" alt="SOC2 Type II Ready">
</p>

---

## Why Protocol?

Most CI/CD pipelines are overkill. You configure webhooks, build runners, deploy scripts, artifact storage, rollback strategies — all before a single line of code reaches production. And when you scale to multiple nodes behind a load balancer, complexity explodes.

Protocol takes a different approach. Every node is a **follower** that watches for release changes and deploys automatically. No build server. No webhook endpoints. No deploy scripts. Just git tags and a single pointer variable.

```bash
# On any node, anywhere:
protocol start
```

That single command links your environment config, starts your Docker containers, and begins watching for release changes. Tag a release and push it to all nodes with one command.

## What It Does

**Release-Based Deployment** — Tag releases with semver, deploy to all nodes by setting a single GitHub variable. Instant rollback, full audit trail, SOC2-ready logging.

**Configuration Management** — Environment configs (`.env`, nginx, cron, etc.) live in a separate git repo. Each branch is an environment. Protocol symlinks the right config into your app at runtime. Production secrets are encrypted with AES-256-GCM.

**Docker Orchestration** — Manages the full container lifecycle through docker-compose. Build, pull, start, stop, rebuild — all through Protocol. Secrets are decrypted and injected at deploy time.

**Reboot Survival** — A single crontab entry ensures nodes come back online automatically after a reboot, fully configured and running.

## Quick Start

### Install

```bash
curl -L "https://raw.githubusercontent.com/merchantprotocol/protocol/master/bin/install" | sudo bash
```

Supports macOS (Homebrew), Ubuntu/Debian (apt), and Amazon Linux (yum). See [Installation Guide](docs/installation.md) for manual install and platform notes.

### Initialize a Project

```bash
cd /path/to/your/repo

# Interactive setup wizard
protocol init

# Or step by step:
protocol config:env production
protocol config:init
```

### Create & Deploy Releases

```bash
# Create your first release
protocol release:create 1.0.0

# Start a node
protocol start

# Deploy a release to all nodes
protocol deploy:push 1.0.0

# Check status
protocol status

# Something wrong? Instant rollback
protocol deploy:rollback
```

### Manage Configuration

```bash
# Move a config file into the config repo (creates symlink back)
protocol config:mv .env

# Switch environments
protocol config:switch staging

# Create a new environment
protocol config:new

# Save and push config changes
protocol config:save
```

### Encrypted Secrets

```bash
# Set up encryption (once per cluster)
protocol secrets:setup

# Encrypt .env to .env.enc
protocol secrets:encrypt

# Secrets are automatically decrypted and injected at deploy time
```

## How It Works

### Release-Based Deployment (Recommended)

```
┌──────────────────┐     ┌──────────────────────┐
│   GitHub Repo    │     │  GitHub Variable      │
│                  │     │                        │
│  Tags:           │     │  PROTOCOL_ACTIVE_      │
│    v1.0.0        │     │  RELEASE = "1.2.0"     │
│    v1.1.0        │     │                        │
│    v1.2.0        │     └──────────┬─────────────┘
└──────────────────┘                │
                                    │ polls every 60s
                         ┌──────────▼─────────────┐
                         │    Production Nodes     │
                         │                         │
                         │  deploy:slave watches   │
                         │  the variable and auto- │
                         │  deploys when it changes │
                         │                         │
                         │  Node 1: v1.2.0 ✓       │
                         │  Node 2: v1.2.0 ✓       │
                         │  Node N: v1.2.0 ✓       │
                         └─────────────────────────┘
```

**Deploy flow:**
1. `protocol release:create` — Tag, push, create GitHub Release
2. `protocol deploy:push 1.2.0` — Set the pointer variable
3. All nodes detect the change and deploy automatically
4. `protocol deploy:rollback` — Instant rollback if needed

### Branch-Based Deployment (Legacy)

```
┌──────────────────┐          ┌──────────────────┐
│   GitHub/GitLab  │          │  Config Repo      │
│                  │          │  (private)         │
│  app repo        │          │  branch: prod      │
│  branch: master  │          │  branch: staging   │
└────────┬─────────┘          └────────┬───────────┘
         │ polls every 10s              │ polls every 10s
         ▼                              ▼
┌─────────────────────────────────────────────────────┐
│                  Production Node                     │
│                                                      │
│  protocol start                                      │
│  ├── git:slave ─── watches app repo, auto-pulls      │
│  ├── config:slave ─ watches config repo, auto-pulls  │
│  ├── config:link ── symlinks .env, nginx.conf, etc.  │
│  └── docker:compose ── runs containers               │
└──────────────────────────────────────────────────────┘
```

Still supported for local development and simpler setups. See [migration guide](docs/migration.md) to upgrade.

## Commands

| Command | Description |
|---|---|
| `protocol init` | Interactive project setup wizard |
| `protocol start` | Start all services on this node |
| `protocol stop` | Stop all services and watchers |
| `protocol restart` | Stop and re-start (designed for `@reboot` crontab) |
| `protocol status` | Show system health: strategy, release, watchers, Docker |
| `protocol docker:exec [cmd]` | Run command inside Docker container (default: bash) |
| `protocol migrate` | Migrate from branch-based to release-based deployment |

<details>
<summary><strong>Release Commands</strong></summary>

| Command | Description |
|---|---|
| `release:create [version]` | Tag a new release (auto-bumps patch if no version) |
| `release:list` | List all available releases |
| `release:changelog` | Generate CHANGELOG.md |

</details>

<details>
<summary><strong>Deployment Commands</strong></summary>

| Command | Description |
|---|---|
| `deploy:push <version>` | Deploy a release to ALL nodes (sets GitHub variable) |
| `deploy:rollback` | Roll back ALL nodes to previous release |
| `deploy:status` | Show active release pointer vs local version |
| `deploy:log` | View deployment audit log |
| `deploy:slave` | Start release watcher daemon |
| `deploy:slave:stop` | Stop release watcher daemon |
| `node:deploy <version>` | Deploy on THIS node only (staging/testing) |
| `node:rollback` | Roll back THIS node only |

</details>

<details>
<summary><strong>Secrets Commands</strong></summary>

| Command | Description |
|---|---|
| `secrets:setup [key]` | Generate or store encryption key |
| `secrets:encrypt [file]` | Encrypt `.env` to `.env.enc` |
| `secrets:decrypt [file]` | Decrypt and display `.env.enc` |

</details>

<details>
<summary><strong>Configuration Commands</strong></summary>

| Command | Description |
|---|---|
| `config:env <name>` | Set the global environment for this machine |
| `config:init` | Initialize the configuration repository |
| `config:cp <file>` | Copy a file into the config repo |
| `config:mv <file>` | Move a file to config repo + symlink back |
| `config:link` | Create symlinks for all config files |
| `config:unlink` | Remove all config symlinks |
| `config:switch <env>` | Switch to a different environment branch |
| `config:new` | Create a new environment |
| `config:save` | Commit and push config changes |
| `config:refresh` | Rebuild all symlinks |
| `config:slave` | Watch config repo for remote changes |
| `config:slave:stop` | Stop config repo watcher |

</details>

<details>
<summary><strong>Docker Commands</strong></summary>

| Command | Description |
|---|---|
| `docker:compose` | Start containers (`docker-compose up -d`) |
| `docker:compose:down` | Stop and remove containers |
| `docker:compose:rebuild` | Rebuild and restart containers |
| `docker:build` | Build image from Dockerfile |
| `docker:pull` | Pull image from registry |
| `docker:push` | Push image to registry |
| `docker:exec [cmd]` | Run command in container (default: bash) |
| `docker:logs` | Follow container logs |

</details>

<details>
<summary><strong>Git Commands</strong></summary>

| Command | Description |
|---|---|
| `git:pull` | Force-pull from remote (resets local) |
| `git:slave` | Start continuous deployment watcher (branch mode) |
| `git:slave:stop` | Stop the watcher |
| `git:clean` | Clean `.git` folder bloat |

</details>

<details>
<summary><strong>System Commands</strong></summary>

| Command | Description |
|---|---|
| `self:update` | Update Protocol to latest version |
| `self:global` | Install Protocol as global command (`--force` to replace) |
| `key:generate` | Generate SSH deploy key |
| `cron:add` | Add `@reboot` restart to crontab |
| `cron:remove` | Remove crontab entry |

</details>

Full reference: [docs/commands.md](docs/commands.md)

## Configuration

Protocol uses a `protocol.json` file in each project root:

```json
{
    "name": "myapp",
    "project_type": "php81",
    "git": {
        "remote": "git@github.com:org/myapp.git",
        "branch": "master"
    },
    "docker": {
        "image": "registry/myapp:latest",
        "container_name": "myapp-web"
    },
    "configuration": {
        "local": "../myapp-config",
        "remote": "git@github.com:org/myapp-config.git"
    },
    "deployment": {
        "strategy": "release",
        "pointer": "github_variable",
        "pointer_name": "PROTOCOL_ACTIVE_RELEASE",
        "secrets": "encrypted"
    }
}
```

Full schema and configuration patterns: [docs/configuration.md](docs/configuration.md)

## Production Deployment

### Single Node

```bash
# 1. Clone your app
git clone git@github.com:org/myapp.git /opt/myapp && cd /opt/myapp

# 2. Copy the encryption key from another node
protocol secrets:setup "your-hex-key-here"

# 3. Set environment and start
protocol config:env production
protocol start
```

### Multi-Node Cluster

Repeat the same steps on every node. Each node independently watches the GitHub release variable and deploys when it changes. No coordination required — deploy once, update everywhere.

```bash
# Node 1, Node 2, Node 3, ... Node N — all identical:
git clone git@github.com:org/myapp.git /opt/myapp && cd /opt/myapp
protocol secrets:setup "your-hex-key-here"
protocol config:env production
protocol start
```

Scale up by launching new nodes with the same setup. Scale down by running `protocol stop`. Auto-scaling groups can use the `@reboot` crontab entry to self-configure on launch.

### Deploying a Release

```bash
# On your development machine:

# 1. Create a release
protocol release:create        # Auto-bumps patch version
protocol release:create 2.0.0  # Or specify version

# 2. Deploy to all nodes
protocol deploy:push 2.0.0

# 3. Something wrong?
protocol deploy:rollback
```

## Migrating from v1

If you're using branch-based deployment (Protocol v1), run the interactive migration wizard:

```bash
protocol migrate
```

Or see the full [Migration Guide](docs/migration.md) for manual steps.

## Security & Compliance

Protocol is designed with SOC2 Type II compliance in mind:

- **Encrypted secrets** — AES-256-GCM encryption for `.env` files. Keys stored with 0600 permissions, decrypted to RAM (`/dev/shm/`) and deleted immediately after injection
- **Deployment audit log** — Every deploy, rollback, and config change logged to `~/.protocol/deployments.log`
- **Git-based audit trail** — Every code and config change tracked in git history
- **Secrets isolation** — Configuration files live in a separate, access-controlled repository
- **Environment separation** — Branch-based isolation between production, staging, and development
- **Automated security scanning** — CI pipeline includes dependency audits, secret scanning, and static analysis

See [docs/security.md](docs/security.md) for the full SOC2 mapping, hardening checklist, and audit logging guidance.

## System Requirements

| Requirement | Version |
|---|---|
| PHP | 8.1+ |
| Git | 2.x+ |
| Docker | 20.x+ |
| Docker Compose | v2+ |
| GitHub CLI (`gh`) | Required for release-based deployment |

Composer is bundled with Protocol — no separate installation needed.

## Documentation

| Document | Description |
|---|---|
| [Architecture](docs/architecture.md) | System design, components, data flow, known issues |
| [Installation](docs/installation.md) | Install guide, platform notes, production setup |
| [Commands](docs/commands.md) | Complete CLI reference |
| [Configuration](docs/configuration.md) | `protocol.json` schema, config repos, environments |
| [Migration](docs/migration.md) | Migrate from branch-based to release-based deployment |
| [Security & SOC2](docs/security.md) | Compliance mapping, hardening, audit logging |
| [Troubleshooting](docs/troubleshooting.md) | Common issues and fixes |
| [Contributing](CONTRIBUTING.md) | Development setup, coding standards, PR process |

## Contributing

We welcome contributions. Please read [CONTRIBUTING.md](CONTRIBUTING.md) before submitting a pull request. For security issues, follow the disclosure process in [SECURITY.md](SECURITY.md).

## License

Protocol is open-source software licensed under the [MIT License](LICENSE).

Copyright (c) 2019 [Merchant Protocol, LLC](https://merchantprotocol.com/)
