<p align="center">
  <h1 align="center">Protocol</h1>
  <p align="center">
    Zero-complexity continuous deployment and configuration management for PHP applications.
    <br />
    <a href="docs/architecture.md"><strong>Architecture</strong></a>
    &middot;
    <a href="docs/commands.md"><strong>Commands</strong></a>
    &middot;
    <a href="docs/configuration.md"><strong>Configuration</strong></a>
    &middot;
    <a href="docs/security.md"><strong>Security</strong></a>
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

Protocol takes a different approach. Every node is a **follower** that watches its upstream branch and pulls changes automatically. No build server. No webhook endpoints. No deploy scripts. Just git.

```bash
# On any node, anywhere:
protocol start
```

That single command pulls your code, links your environment config, starts your Docker containers, and begins watching for changes. Push to your branch, and every node updates within seconds.

## What It Does

**Continuous Deployment** — Each node polls its remote branch and auto-deploys changes. No webhooks, no build servers, no agents. Push to git, nodes update.

**Configuration Management** — Environment configs (`.env`, nginx, cron, etc.) live in a separate git repo. Each branch is an environment. Protocol symlinks the right config into your app at runtime. Production secrets never touch your application repo.

**Docker Orchestration** — Manages the full container lifecycle through docker-compose. Build, pull, start, stop, rebuild — all through Protocol.

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

# Create protocol.json
protocol init

# Set your environment
protocol config:env production

# Initialize config repo (stores .env, nginx configs, etc. separately)
protocol config:init
```

### Deploy

```bash
# Start everything: pull code, link config, start Docker, enable auto-deploy
protocol start

# Check system state
protocol status

# Stop everything
protocol stop
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

## How It Works

```
┌──────────────────┐          ┌──────────────────┐
│   GitHub/GitLab  │          │  Config Repo      │
│                  │          │  (private)         │
│  app repo        │          │  branch: prod      │
│  branch: master  │          │  branch: staging   │
│                  │          │  branch: local-dev  │
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
│                                                      │
│  ┌─────────────┐    ┌──────────────────┐             │
│  │ myapp/      │    │ myapp-config/    │             │
│  │ ├── src/    │    │ ├── .env         │             │
│  │ ├── .env →──┼────┼─┘                │             │
│  │ └── ...     │    │ └── nginx.conf   │             │
│  └─────────────┘    └──────────────────┘             │
└──────────────────────────────────────────────────────┘
```

**App Repo** contains your code and `protocol.json`. Commit, push, and every follower node updates automatically.

**Config Repo** is a sibling directory (`myapp-config/`) with a branch per environment. Protocol symlinks config files into your app directory so they work seamlessly — including inside Docker containers.

## Commands

| Command | Description |
|---|---|
| `protocol init` | Initialize project, create `protocol.json` |
| `protocol start` | Pull code, link config, start Docker, enable auto-deploy |
| `protocol stop` | Stop auto-deploy, unlink config, stop Docker |
| `protocol restart` | Stop and re-start (designed for `@reboot` crontab) |
| `protocol status` | Show system health: slaves, Docker, config, cron |
| `protocol exec [cmd]` | Run command inside Docker container (default: bash) |

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
| `docker:logs` | Follow container logs |

</details>

<details>
<summary><strong>Git Commands</strong></summary>

| Command | Description |
|---|---|
| `git:pull` | Force-pull from remote (resets local) |
| `git:slave` | Start continuous deployment watcher |
| `git:slave:stop` | Stop the watcher |
| `git:clean` | Clean `.git` folder bloat |

</details>

<details>
<summary><strong>System Commands</strong></summary>

| Command | Description |
|---|---|
| `self:update` | Update Protocol to latest version |
| `self:global` | Install Protocol as global command |
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
    }
}
```

Full schema and configuration patterns: [docs/configuration.md](docs/configuration.md)

## Production Deployment

### Single Node

```bash
# 1. Clone your app
git clone git@github.com:org/myapp.git /opt/myapp && cd /opt/myapp

# 2. Set environment and start
protocol config:env production
protocol start

# 3. Survive reboots
protocol cron:add
```

### Multi-Node Cluster

Repeat the same steps on every node. Each node independently watches the remote and pulls changes. No coordination required — push once, deploy everywhere.

```bash
# Node 1, Node 2, Node 3, ... Node N — all identical:
git clone git@github.com:org/myapp.git /opt/myapp && cd /opt/myapp
protocol config:env production
protocol start
protocol cron:add
```

Scale up by launching new nodes with the same setup. Scale down by running `protocol stop`. Auto-scaling groups can use the `@reboot` crontab entry to self-configure on launch.

## Security & Compliance

Protocol is designed with SOC2 Type II compliance in mind:

- **Git-based audit trail** — Every code and config change is tracked in git history
- **Secrets isolation** — Configuration files live in a separate, access-controlled repository
- **Environment separation** — Branch-based isolation between production, staging, and development
- **Automated security scanning** — CI pipeline includes dependency audits, secret scanning, and static analysis
- **Vulnerability disclosure** — Responsible disclosure process documented in [SECURITY.md](SECURITY.md)

See [docs/security.md](docs/security.md) for the full SOC2 mapping, hardening checklist, and audit logging guidance.

## System Requirements

| Requirement | Version |
|---|---|
| PHP | 8.1+ |
| Git | 2.x+ |
| Docker | 20.x+ |
| Docker Compose | v2+ |

Composer is bundled with Protocol — no separate installation needed.

## Documentation

| Document | Description |
|---|---|
| [Architecture](docs/architecture.md) | System design, components, data flow, known issues |
| [Installation](docs/installation.md) | Install guide, platform notes, production setup |
| [Commands](docs/commands.md) | Complete CLI reference |
| [Configuration](docs/configuration.md) | `protocol.json` schema, config repos, environments |
| [Security & SOC2](docs/security.md) | Compliance mapping, hardening, audit logging |
| [Troubleshooting](docs/troubleshooting.md) | Common issues and fixes |
| [Contributing](CONTRIBUTING.md) | Development setup, coding standards, PR process |

## Contributing

We welcome contributions. Please read [CONTRIBUTING.md](CONTRIBUTING.md) before submitting a pull request. For security issues, follow the disclosure process in [SECURITY.md](SECURITY.md).

## License

Protocol is open-source software licensed under the [MIT License](LICENSE).

Copyright (c) 2019 [Merchant Protocol, LLC](https://merchantprotocol.com/)
