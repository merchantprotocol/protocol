# Architecture Overview

This document describes the internal architecture of Protocol, a PHP CLI tool for continuous deployment, configuration management, and secrets management for highly available applications.

## System Overview

Protocol is built on the Symfony Console framework and follows a modular command-based architecture. It manages four primary concerns:

1. **Release-Based Continuous Deployment** — Nodes track a release pointer (GitHub repository variable) and deploy specific git tags. Rollback is instant by changing the pointer.
2. **Configuration Management** — Environment-specific config files stored in a separate git repository, symlinked into the application. All config files are encrypted at rest.
3. **Secrets Management** — Secrets are encrypted with AES-256-GCM before being stored in the config repo. At deploy time, secrets are decrypted in memory and injected as Docker environment variables — never written to disk as plaintext.
4. **Docker Orchestration** — Container lifecycle management via docker-compose with runtime secret injection.

```
┌─────────────────────────────────────────────────────────────────┐
│                     protocol (CLI Entry Point)                  │
│                     Symfony Console Application                 │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────────────┐    │
│  │   Commands/   │  │   Helpers/   │  │      Utils/        │    │
│  │              │  │              │  │                    │    │
│  │  init        │  │  AuditLog    │  │  Config (base)     │    │
│  │  start/stop  │  │  Config      │  │  Json              │    │
│  │  deploy      │  │  Crontab     │  │  JsonLock           │    │
│  │  rollback    │  │  Dir         │  │  Yaml              │    │
│  │  releases    │  │  Docker      │  │                    │    │
│  │  config:*    │  │  Git         │  │                    │    │
│  │  secrets:*   │  │  GitHub      │  │                    │    │
│  │  docker:*    │  │  Release     │  │                    │    │
│  │  deploy:*    │  │  Secrets     │  │                    │    │
│  │  ...         │  │  Shell       │  │                    │    │
│  │              │  │  Str         │  │                    │    │
│  └──────────────┘  └──────────────┘  └────────────────────┘    │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

## Directory Structure

```
protocol/
├── protocol              # CLI entry point (PHP executable)
├── src/
│   ├── bootstrap.php     # Shared constants and autoload
│   ├── Commands/         # All CLI commands (auto-registered)
│   │   └── Init/         # Project initializer classes
│   ├── Helpers/          # Domain logic
│   │   ├── AuditLog.php  # Deployment audit trail
│   │   ├── Config.php    # Protocol config (global + local overlay)
│   │   ├── Crontab.php   # Crontab management
│   │   ├── Dir.php       # Path utilities
│   │   ├── Docker.php    # Docker and compose operations
│   │   ├── Git.php       # Git operations (branches, tags, fetch)
│   │   ├── GitHub.php    # GitHub API (variables, releases)
│   │   ├── Release.php   # Changelog parsing
│   │   ├── Secrets.php   # AES-256-GCM encryption/decryption
│   │   ├── Shell.php     # Shell command execution
│   │   └── Str.php       # String utilities
│   └── Utils/            # Data persistence layer
│       ├── Config.php    # Base config (PHP array files)
│       ├── Json.php      # JSON files (protocol.json)
│       ├── JsonLock.php  # Lock files (protocol.lock)
│       └── Yaml.php      # YAML files (docker-compose.yml)
├── bin/
│   ├── install               # Platform-aware installation script
│   ├── release-watcher.php   # Release polling daemon
│   ├── git-repo-watcher      # Branch polling daemon (dev mode)
│   ├── git-repo-watcher-hooks
│   └── composer.phar         # Bundled Composer
├── config/
│   ├── global.php        # Global defaults
│   └── config.php        # Local overrides (environment name)
├── templates/            # Template files for new projects
└── docs/                 # This documentation
```

## Entry Point and Bootstrap

The `protocol` file is the CLI entry point. It requires `src/bootstrap.php` which:

1. Requires the Composer autoloader
2. Defines global constants
3. Is shared between the main CLI and the `bin/release-watcher.php` daemon

The entry point then auto-discovers and registers all command classes from `src/Commands/` and runs the Symfony Console application.

### Global Constants

| Constant | Value | Purpose |
|---|---|---|
| `WORKING_DIR` | `getcwd()` | Where the user invoked protocol from |
| `WEBROOT_DIR` | Protocol install directory | Root of the protocol tool itself |
| `CONFIG_DIR` | `WEBROOT_DIR/config/` | Protocol's own configuration |
| `SRC_DIR` | `WEBROOT_DIR/src/` | Source code directory |
| `SCRIPT_DIR` | `WEBROOT_DIR/bin/` | Shell scripts directory |
| `TEMPLATES_DIR` | `WEBROOT_DIR/templates/` | Template files directory |
| `COMMANDS_DIR` | `SRC_DIR/Commands/` | Command classes directory |

## Namespace

All classes live under the `Gitcd\` namespace. PSR-4 autoloading maps `Gitcd\` to `src/`.

## Layer Architecture

### Commands Layer (`src/Commands/`)

Each command is a Symfony Console Command class. Commands are auto-registered by globbing `*.php` in the Commands directory. Commands handle:

- CLI argument/option parsing
- User interaction (prompts, confirmations)
- Orchestrating helper and utility calls
- Output formatting

Commands do NOT contain domain logic directly — they delegate to Helpers.

### Helpers Layer (`src/Helpers/`)

Helpers contain the domain logic. All methods are static.

| Helper | Responsibility |
|---|---|
| `AuditLog` | Writes and reads deployment audit trail entries |
| `Config` | Reads/writes protocol's own config (global + local overlay) |
| `Crontab` | Crontab manipulation (add/remove restart jobs) |
| `Dir` | Path utilities (realpath for non-existent paths, directory scanning) |
| `Docker` | Docker detection, container management, secrets-aware compose operations |
| `Git` | Git operations (clone, pull, push, branch, tag, fetch, checkout, clean) |
| `GitHub` | GitHub API interactions (repository variables, releases, remote URL parsing) |
| `Release` | Changelog parsing |
| `Secrets` | AES-256-GCM encryption/decryption, key management |
| `Shell` | Command execution (run, passthru, background), process management |
| `Str` | String utilities (slugify) |

### Utils Layer (`src/Utils/`)

Utils handle data persistence. They follow a singleton pattern with file-backed storage:

| Util | File Format | Default File | Purpose |
|---|---|---|---|
| `Config` | PHP array (`<?php return [...];`) | `config/global.php` | Protocol's own settings |
| `Json` | JSON | `protocol.json` | Project metadata and settings |
| `JsonLock` | JSON | `protocol.lock` | Runtime state (PIDs, releases, symlinks) |
| `Yaml` | YAML | `docker-compose.yml` | Docker compose configuration |

All utils extend `Config` and inherit its dot-notation property access (`get('docker.image')`).

## Deployment Architecture

Protocol supports two deployment strategies, configured per-project in `protocol.json`:

### Release Mode (`deployment.strategy: "release"`)

Used for production and staging. Nodes track a **GitHub repository variable** that identifies the active release tag. This is the recommended mode for any environment that requires SOC2 compliance.

```
                    GitHub Repository
                    ┌─────────────────────────────────────┐
                    │                                     │
                    │  Tags: v1.0.0, v1.1.0, v1.2.0      │
                    │                                     │
                    │  Repository Variable:                │
                    │  PROTOCOL_ACTIVE_RELEASE = "v1.2.0"  │
                    │         ▲                            │
                    │         │ (changed by operator       │
                    │         │  to deploy or rollback)    │
                    └─────────┼────────────────────────────┘
                              │
               polls via GitHub API every N seconds
                              │
         ┌────────────────────┼────────────────────┐
         ▼                    ▼                    ▼
    ┌─────────┐         ┌─────────┐         ┌─────────┐
    │ Node 1  │         │ Node 2  │         │ Node N  │
    │ v1.2.0  │         │ v1.2.0  │         │ v1.2.0  │
    └─────────┘         └─────────┘         └─────────┘
```

**How it works:**

1. The `bin/release-watcher.php` daemon polls the GitHub API for the value of a repository variable (default: `PROTOCOL_ACTIVE_RELEASE`)
2. Compares the variable value to the currently deployed version in `protocol.lock`
3. If different (whether newer OR older — supports rollback):
   a. `git fetch --all --tags`
   b. `git checkout tags/<version>`
   c. Decrypt secrets from config repo, inject as Docker env vars
   d. `docker compose up --build -d`
   e. Update `protocol.lock` with new version, previous version, timestamp
   f. Write audit log entry
4. Sleeps for the configured interval

**Deploy flow:**

```
Developer: commit → PR → merge → tag v1.3.0 → create release
           (nothing deploys yet)

Staging:   protocol deploy v1.3.0    ← manual deploy on staging node
           (test it, QA passes)

Publish:   gh variable set PROTOCOL_ACTIVE_RELEASE --body "v1.3.0"
           (all production nodes deploy v1.3.0 within seconds)

Rollback:  gh variable set PROTOCOL_ACTIVE_RELEASE --body "v1.2.0"
           (all nodes revert within seconds)
```

**Why releases, not branches:**

- A release is an explicit approval decision — creating the tag is the gate
- Releases have version numbers, timestamps, and authors (audit trail)
- Tags are immutable — deploying v1.2.0 always means the same code
- Rollback is just deploying a previous tag — no git surgery needed
- SOC2 auditors can see exactly what was deployed and when

### Branch Mode (`deployment.strategy: "branch"`)

Used for local development. Nodes track a branch tip and auto-pull changes, preserving the current behavior for dev workflows.

```
┌─────────────┐     polls      ┌──────────────┐
│  Watcher    │ ──────────────> │  Remote Git  │
│  (daemon)   │                 │  Branch tip  │
│             │ <────────────── │              │
│  git:pull   │   new commits   │              │
└─────────────┘                 └──────────────┘
```

Uses the existing `bin/git-repo-watcher` bash script. Every commit to the tracked branch is deployed immediately. No audit trail, no rollback, no approval gate — appropriate for local dev only.

## Secrets Architecture

Protocol uses AES-256-GCM encryption for all secrets. Secrets are encrypted before being stored in the config repo, and decrypted in memory at deploy time.

### Encryption Model

```
┌─────────────────────────────────────────────────────┐
│                   Config Repo                        │
│                                                      │
│   .env.enc  ← AES-256-GCM encrypted                │
│   nginx.conf  ← non-secret, stored as plaintext     │
│   php.ini     ← non-secret, stored as plaintext     │
│                                                      │
└──────────────────────┬──────────────────────────────┘
                       │
                       │ at deploy time
                       ▼
┌──────────────────────────────────────────────────────┐
│                   Node                               │
│                                                      │
│   ~/.protocol/key  ← decryption key (set once)      │
│                                                      │
│   1. Read .env.enc from config repo                  │
│   2. Decrypt in memory using ~/.protocol/key         │
│   3. Pass as env vars to docker compose              │
│   4. Secrets exist only in container memory           │
│   5. Nothing written to disk as plaintext            │
│                                                      │
└──────────────────────────────────────────────────────┘
```

### Key Management

- **Key storage:** `~/.protocol/key` — a file containing a base64-encoded 256-bit key
- **Key permissions:** `~/.protocol/` directory is `0700`, key file is `0600`
- **Key provisioning:** Set once per node via `protocol secrets:setup`
- **Key rotation:** Re-encrypt config files with new key, distribute new key to nodes, push updated `.env.enc`

### Encrypted File Format

The `.env.enc` file contains: `base64(nonce[12 bytes] + tag[16 bytes] + ciphertext)`

PHP's built-in `openssl_encrypt()` with `aes-256-gcm` handles the encryption. No external dependencies needed.

### Docker Secret Injection

At deploy time, secrets are decrypted in memory and passed to Docker containers:

1. Read `.env.enc` from the config repo
2. Decrypt using `~/.protocol/key` — plaintext exists only in PHP memory
3. Write to a temporary file in `/dev/shm/` (RAM-backed tmpfs on Linux) with `0600` permissions
4. Run `docker compose --env-file /dev/shm/protocol_env_XXXXX up --build -d`
5. Immediately delete the temporary file
6. Register a shutdown handler to clean up on unexpected termination

On macOS (local dev with `secrets: "file"` mode), the `.env` file from the config repo is used directly — no encryption, no tmpfs.

### Secrets Modes

| Mode | Config Value | Behavior | Use Case |
|---|---|---|---|
| Encrypted | `"secrets": "encrypted"` | `.env.enc` decrypted at deploy time, injected as Docker env vars | Production, staging, any SOC2 environment |
| File | `"secrets": "file"` | `.env` read directly from config repo | Local development |

## Configuration Repository Pattern

The configuration repository stores environment-specific files in a separate git repo. Each branch represents a different environment.

```
myapp/                      myapp-config/
├── src/                    ├── .env.enc        (encrypted secrets)
├── public/                 ├── nginx.conf      (non-secret config)
├── nginx.conf → symlink ───┤── php.ini         (non-secret config)
├── protocol.json           ├── cron.d/         (cron definitions)
└── docker-compose.yml      ├── README.md
                            └── .git/
                                └── refs/heads/
                                    ├── production
                                    ├── staging
                                    └── localhost-dev
```

Non-secret config files (nginx, php.ini, cron) are symlinked into the app directory. Secret files (`.env.enc`) are decrypted and injected as Docker environment variables at deploy time.

### Config Lifecycle

1. `config:init` — Creates the config repo, sets environment branch
2. `config:cp` / `config:mv` — Moves files into the config repo
3. `secrets:encrypt` — Encrypts `.env` into `.env.enc` before committing
4. `config:link` — Creates symlinks for non-secret config files
5. `config:save` — Commits and pushes config changes (secrets already encrypted)
6. `config:switch` — Switches environment (unlinks, changes branch, re-links)
7. `config:slave` — Watches config repo for remote changes
8. `config:unlink` — Removes all symlinks

## Command Lifecycle

### `protocol start` (Release Mode)

1. Detect deployment strategy from `protocol.json`
2. `config:init` — Ensure config repo exists
3. `config:link` — Symlink non-secret config files
4. `config:slave` — Start config repo watcher
5. `deploy:slave` — Start release watcher (polls GitHub variable)
6. Decrypt secrets + `docker:compose:rebuild` — Rebuild containers with injected secrets
7. `composer:install` — Install PHP dependencies
8. `cron:add` — Ensure reboot recovery
9. `status` — Display final system state

### `protocol start` (Branch Mode — Local Dev)

1. `git:pull` — Pull latest code
2. `config:init` — Ensure config repo exists
3. `config:link` — Symlink config files (including plaintext `.env`)
4. `config:slave` — Start config repo watcher
5. `git:slave` — Start branch watcher
6. `docker:compose:rebuild` — Rebuild containers (reads `.env` directly)
7. `composer:install` — Install PHP dependencies
8. `status` — Display final system state

### `protocol stop`

1. Stop release watcher OR git slave (depending on strategy)
2. Stop config slave
3. Unlink config symlinks
4. Bring down Docker containers
5. Remove crontab entry

## Deployment Audit Log

Every deployment action writes to a persistent log file for SOC2 evidence.

**Default location:** `~/.protocol/deployments.log` (falls back if `/var/log/protocol/` is not writable)

**Format:**
```
2024-01-15T10:30:01Z deploy repo=/opt/myapp from=v1.1.0 to=v1.2.0 status=success
2024-01-15T10:30:05Z config repo=/opt/myapp env=production files=3 status=success
2024-01-15T10:30:08Z docker repo=/opt/myapp image=registry/app:latest action=rebuild status=success
2024-03-01T14:22:00Z rollback repo=/opt/myapp from=v1.3.0 to=v1.2.0 status=success
```

Viewable via `protocol deploy:log`.

## Data Files

### `protocol.json` (per-project, committed to git)

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
        "image": "registry/image:tag",
        "container_name": "myapp-web"
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

No credentials are stored in `protocol.json`. Docker registry credentials, GitHub tokens, and application secrets are handled through environment variables or encrypted config files.

### `protocol.lock` (per-project, gitignored)

Runtime state:

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
        "symlinks": ["/opt/myapp/nginx.conf"],
        "slave": { "pid": 12346 }
    }
}
```

### `~/.protocol/key` (per-node, never in git)

The AES-256-GCM decryption key. Base64-encoded, 256-bit. Set once during node provisioning.

### `~/.protocol/deployments.log` (per-node)

Append-only audit log of all deployment actions.

## Project Initializers

The `init` command uses a strategy pattern for project-type-specific initialization:

```
ProjectInitializerInterface
        │
  BaseInitializer (abstract)
        │
      Php81
      (future: Php82, Node18, etc.)
```

Each initializer handles:
- Creating project-specific directories and config files
- Copying template files (nginx configs, docker-compose, php.ini)
- Setting up docker-compose volumes
- Creating `protocol.json` with deployment strategy configuration

## Dependencies

| Package | Version | Purpose |
|---|---|---|
| symfony/console | ^5.4 | CLI framework |
| symfony/lock | ^5.4 | Process locking (LockableTrait) |
| symfony/yaml | ^5.4 | YAML parsing for docker-compose files |
| knplabs/github-api | ^3.0 | GitHub API for releases and repository variables |
| guzzlehttp/guzzle | ^7.0 | HTTP client (GitHub API transport) |
| http-interop/http-factory-guzzle | ^1.0 | PSR-17 HTTP factory |

No additional dependencies are needed for encryption — PHP's built-in OpenSSL extension provides AES-256-GCM.
