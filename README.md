<p align="center">
  <h1 align="center">Protocol</h1>
  <p align="center">
    Deploy your Docker app with one command. Encrypted secrets, automatic rollback, zero build servers.
    <br />
    <a href="docs/getting-started.md"><strong>Getting Started</strong></a>
    &middot;
    <a href="docs/deployment-types.md"><strong>Deployment Strategies</strong></a>
    &middot;
    <a href="docs/secrets.md"><strong>Secrets</strong></a>
    &middot;
    <a href="docs/commands.md"><strong>Commands</strong></a>
    &middot;
    <a href="docs/configuration.md"><strong>Configuration</strong></a>
  </p>
</p>

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="MIT License"></a>
  <img src="https://img.shields.io/badge/php-8.1%2B-8892BF.svg" alt="PHP 8.1+">
  <img src="https://img.shields.io/badge/SOC2-Type%20II%20Ready-green.svg" alt="SOC 2 Type II Ready">
</p>

---

## Stupid Simple CI/CD. Deployed in Seconds. More Reliable Than Anything Else You've Tried.

Two commands. That's your entire deployment pipeline.

```bash
protocol init     # Set up your project (once)
protocol start    # Stay in sync with millisecond rollouts
```

Your Docker containers come up. Your `.env` files decrypt themselves. Your nginx and cron configs link into place. A watcher starts polling for new releases. Push a git tag, and every server in your fleet deploys it automatically. Roll back with one command if anything goes wrong.

No Jenkins. No GitHub Actions workflows. No webhook endpoints. No deploy scripts. No build servers. Just git, Docker, and Protocol.

### What you get out of the box

- **One-command deploy** to any number of servers
- **Encrypted secrets** that version-control your `.env` files safely in git
- **Instant rollback** to any previous release
- **Blue-green shadow deploys** — build in the background, swap in one second
- **Auto-restart** after server reboots
- **Zero coordination** between nodes — they all figure it out independently
- **SOC 2 Type II ready** with audit logging and encrypted credentials

---

## The Old Way vs. Protocol

Most teams go through the same painful evolution:

**Stage 1:** SSH into the server and `git pull`. It works until you forget which server you updated.

**Stage 2:** Set up a CI/CD pipeline. Now you're maintaining build runners, webhook endpoints, deploy scripts, artifact storage, and environment variables scattered across three different dashboards.

**Stage 3:** Add a second production node. Now your deploy scripts need to handle multiple targets, health checks, rolling deploys, and rollback logic. Your CI/CD config file is 200 lines long.

**Stage 4:** Your `.env` file on production gets overwritten during a deploy. The database password is gone. It was in a Slack DM from six months ago. Maybe.

Protocol skips all of that. Every node runs `protocol start` and manages itself. You tag a release, set a pointer, and every node picks it up. Your secrets are encrypted in git and decrypt themselves on arrival.

## Quick Start

### Install

```bash
curl -L "https://raw.githubusercontent.com/merchantprotocol/protocol/master/bin/install" | sudo bash
```

### Set Up Your Project

```bash
cd /path/to/your/project
protocol init
```

A wizard walks you through it — pick your Docker image, choose a deploy strategy, optionally encrypt your secrets. Arrow keys to navigate, Enter to confirm.

### Start Everything

```bash
protocol start
```

Docker containers come up. Config files get linked. Secrets get decrypted. You're running.

### Deploy to Production

On your production server:

```bash
git clone git@github.com:yourorg/yourapp.git /opt/yourapp
cd /opt/yourapp
protocol config:env production
protocol secrets:setup "your-encryption-key"
protocol start
```

From then on, deploying is:

```bash
# On your dev machine
protocol release:create
protocol deploy:push 1.0.0
```

Every node picks up the new release automatically. Something wrong? `protocol deploy:rollback` — instant.

## How Secrets Work

Your `.env` files are too important to pass around in Slack messages and too dangerous to commit in plain text. Protocol encrypts them with AES-256-GCM before they touch git.

```
Your Machine                    Git                     Production
─────────────                   ───                     ──────────
.env (plaintext)  ──encrypt──▶  .env.enc (encrypted)  ──▶  .env (plaintext)
```

The encryption key stays on your machines. Only encrypted data travels through git.

```bash
# Set up encryption (interactive wizard)
protocol config:init

# View your key and transfer options
protocol secrets:key

# Copy key to production server
protocol secrets:key --scp=deploy@production

# Or push to GitHub for CI/CD
protocol secrets:key --push
```

When `protocol start` runs on any node with the key, encrypted files are decrypted automatically. Your app just reads `.env` like it always has.

Full guide: [docs/secrets.md](docs/secrets.md)

## How Deployment Works

### Release-Based (Recommended)

You create versioned releases. A GitHub variable tells all nodes which version to run. Change the variable, every node deploys.

```
You run:                              Every node:
─────────                             ───────────
protocol release:create 1.2.0        (watching...)
protocol deploy:push 1.2.0     ───▶  "1.2.0? Deploying now."
                                      ✓ Node 1: v1.2.0
                                      ✓ Node 2: v1.2.0
                                      ✓ Node 3: v1.2.0
```

Rollback is instant — `protocol deploy:rollback` sets the pointer back to the previous version. Every node follows.

### Shadow Deploys (Zero Downtime)

For applications with long build times, enable shadow deployment. Each version gets its own self-contained sibling directory (`<project>-releases/v1.3.0/`) with a full git clone, config, and Docker containers named with the release tag. Build in the background, swap ports in under a second.

```bash
protocol shadow:init              # Configure shadow deployment (wizard)
protocol shadow:build v1.3.0     # Clone + build on shadow ports
protocol shadow:start             # Swap to production (~1 second)
protocol shadow:rollback          # Instant rollback if needed
```

Full guide: [docs/blue-green.md](docs/blue-green.md)

### Branch-Based (Simple)

Nodes watch a git branch and pull changes automatically. Good for local development and simple setups. No versioning, no rollback history.

## How Config Works

Your project has a sibling directory that holds environment-specific files:

```
your-project/           ← your code
your-project-config/    ← .env, nginx.conf, cron jobs (separate git repo)
```

Each environment is a branch: `localhost`, `staging`, `production`. When Protocol starts, it symlinks the right files into your project. When the config repo changes, the watcher picks it up automatically.

```bash
# Set up the config repo (interactive wizard)
protocol config:init

# Move a file into the config repo
protocol config:mv .env

# Switch environments
protocol config:switch staging
```

## Common Commands

| What you want to do | Command |
|---|---|
| Set up a new project | `protocol init` |
| Start everything | `protocol start` |
| Stop everything | `protocol stop` |
| Check what's running | `protocol status` |
| Set up configs & secrets | `protocol config:init` |
| Create a release | `protocol release:create` |
| Deploy to all nodes | `protocol deploy:push 1.2.0` |
| Roll back | `protocol deploy:rollback` |
| Run a command in Docker | `protocol docker:exec "php artisan migrate"` |
| View your encryption key | `protocol secrets:key` |
| Update Protocol itself | `protocol self:update` |

<details>
<summary><strong>All Commands</strong></summary>

**Releases & Deployment**

| Command | Description |
|---|---|
| `release:create [version]` | Tag a new release |
| `release:list` | List all releases |
| `deploy:push <version>` | Deploy a release to all nodes |
| `deploy:rollback` | Roll back all nodes |
| `deploy:status` | Show active vs local version |
| `deploy:log` | View deployment history |
| `node:deploy <version>` | Deploy on this node only |
| `node:rollback` | Roll back this node only |

**Secrets**

| Command | Description |
|---|---|
| `secrets:setup [key]` | Generate or store encryption key |
| `secrets:key` | View key and transfer options |
| `secrets:key --push` | Push key to GitHub as a secret |
| `secrets:key --scp=user@host` | Copy key to remote server |
| `secrets:encrypt [file]` | Encrypt a file |
| `secrets:decrypt [file]` | Decrypt a file |

**Configuration**

| Command | Description |
|---|---|
| `config:init` | Config repo wizard (create, encrypt, decrypt) |
| `config:env <name>` | Set this machine's environment name |
| `config:mv <file>` | Move file to config repo + symlink |
| `config:link` | Create all config symlinks |
| `config:unlink` | Remove all config symlinks |
| `config:switch <env>` | Switch environment branch |
| `config:save` | Commit and push config changes |

**Shadow Deployment**

| Command | Description |
|---|---|
| `shadow:init` | Configure shadow deployment (wizard) |
| `shadow:build <version>` | Build a release in a version-named slot |
| `shadow:start` | Promote shadow to production (~1s) |
| `shadow:rollback` | Revert to previous version (~1s) |
| `shadow:status` | Show version slots and states |

**Docker**

| Command | Description |
|---|---|
| `docker:compose` | Start containers |
| `docker:compose:down` | Stop containers |
| `docker:compose:rebuild` | Rebuild and restart |
| `docker:exec [cmd]` | Run command in container |
| `docker:logs` | Follow container logs |

**System**

| Command | Description |
|---|---|
| `self:update` | Update to latest release |
| `self:update --nightly` | Update to latest commit |
| `cron:add` | Auto-restart on reboot |
| `key:generate` | Generate SSH deploy key |

</details>

Full reference: [docs/commands.md](docs/commands.md)

## Production Setup

Every production node follows the same recipe:

```bash
# 1. Install Protocol
curl -L "https://raw.githubusercontent.com/merchantprotocol/protocol/master/bin/install" | sudo bash

# 2. Clone your app
git clone git@github.com:yourorg/yourapp.git /opt/yourapp
cd /opt/yourapp

# 3. Set environment and encryption key
protocol config:env production
protocol secrets:setup "your-64-char-hex-key"

# 4. Start
protocol start

# 5. Survive reboots
protocol cron:add
```

Repeat on every node. They're all identical. Scale up by adding nodes, scale down by stopping them.

## The Big Picture

```
                          GitHub
                    ┌──────────────────┐
                    │  App Repo        │
                    │  Config Repo     │
                    │  Encrypted .env  │
                    │  Release Tags    │
                    └────────┬─────────┘
                             │
              ┌──────────────┼──────────────┐
              ▼              ▼              ▼
         Dev Machine    Staging Node   Prod Nodes
         (localhost)    (staging)      (production)

         protocol       protocol       protocol
         start          start          start

         Own branch     Own branch     Own branch
         in config      in config      in config
         repo           repo           repo
```

Each machine runs `protocol start`. Each gets its own config branch. They all share the same encryption key. Push code, tag a release, set the pointer — done.

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.1+ |
| Git | 2.x+ |
| Docker + Compose | 20.x+ / v2+ |
| GitHub CLI (`gh`) | For release-based deployment |

Composer is bundled — no separate install needed.

## Documentation

| Guide | What it covers |
|---|---|
| [Getting Started](docs/getting-started.md) | Full walkthrough from install to production |
| [Deployment Strategies](docs/deployment-types.md) | Branch, release, and shadow mode compared |
| [Secrets Management](docs/secrets.md) | Encryption, key distribution, GitHub Actions |
| [Commands](docs/commands.md) | Every command with options and examples |
| [Configuration](docs/configuration.md) | protocol.json, config repos, environments |
| [Architecture](docs/architecture.md) | System design and data flow |
| [SOC 2 Ready](docs/soc2.md) | Trust Service Criteria mapping, readiness checks, auditor guide |
| [Security & Hardening](docs/security.md) | Security controls, encryption internals, vulnerability scanning |
| [Blue-Green Deploys](docs/blue-green.md) | Shadow deployments with instant rollback |
| [Migration](docs/migration.md) | Upgrade from branch-based to release-based |
| [Troubleshooting](docs/troubleshooting.md) | Common issues and fixes |

## License

MIT License. Copyright (c) 2019 [Merchant Protocol, LLC](https://merchantprotocol.com/)
