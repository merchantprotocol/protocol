# Command Reference

Complete reference for all Protocol CLI commands. Run `protocol <command> --help` for usage details on any command.

## Core Commands

### `init`

Interactive setup wizard for new or existing projects.

```
protocol init [environment] [--dir=PATH] [--with-config]
```

| Argument/Option | Description |
|---|---|
| `environment` | (Optional) Environment name |
| `--dir`, `-d` | Directory path (defaults to current git repo root) |
| `--with-config`, `-c` | Also initialize the configuration repository |

**What it does:**

1. **Compatibility check** — Verifies git, docker-compose, GitHub CLI availability
2. **Existing project detection** — If `protocol.json` exists, offers update/strategy/secrets/config options
3. **Project type selection** — Currently PHP 8.1 available
4. **Deployment strategy** — Choose release-based (recommended) or branch-based (legacy)
5. **Secrets management** — Optionally set up AES-256-GCM encrypted secrets
6. **Configuration repository** — Optionally initialize a config repo

Safe to re-run on existing projects — detects current state and offers updates.

---

### `start`

Starts a node — deploys the active release (or pulls latest branch in dev mode), links config, injects secrets, boots Docker containers.

```
protocol start [environment] [--dir=PATH]
```

**Release mode** (`deployment.strategy: "release"`):

1. `config:init` — Ensures config repo is initialized
2. `config:link` — Symlinks non-secret config files
3. `config:slave` — Starts config repo watcher
4. `deploy:slave` — Starts release watcher (polls GitHub variable)
5. Decrypts secrets + `docker:compose:rebuild` — Rebuilds containers with injected secrets
6. `composer:install` — Installs PHP dependencies
7. `cron:add` — Ensures reboot recovery
8. `status` — Displays system state

**Branch mode** (`deployment.strategy: "branch"`, local dev):

1. `git:pull` — Force-pulls latest code
2. `config:init` — Ensures config repo is initialized
3. `config:link` — Symlinks config files (including plaintext `.env`)
4. `config:slave` — Starts config repo watcher
5. `git:slave` — Starts branch watcher
6. `docker:compose:rebuild` — Rebuilds containers
7. `composer:install` — Installs PHP dependencies
8. `status` — Displays system state

---

### `stop`

Stops all running watchers and Docker containers.

```
protocol stop [--dir=PATH]
```

1. Stops release watcher OR git slave (depending on strategy)
2. Stops config slave
3. Unlinks config symlinks
4. Brings down Docker containers (`docker:compose:down`)
5. Removes crontab restart entry (`cron:remove`)

---

### `restart`

Stops and re-starts a node. Designed for use in crontab `@reboot` entries.

```
protocol restart [local] [--dir=PATH]
```

Uses `LockableTrait` to prevent concurrent restarts.

**Crontab usage:**
```
@reboot /path/to/protocol restart /path/to/repo
```

---

### `status`

Displays a system health overview.

```
protocol status [--dir=PATH]
```

**Output includes:**

| Field | Description |
|---|---|
| Deployment Strategy | `release` or `branch` |
| Current Release | Deployed version tag (release mode) |
| Last Deployed | Timestamp of last deployment |
| Release Watcher | Running/stopped + PID (release mode) |
| Git Slave | Running/stopped + PID (branch mode) |
| Config Slave | Running/stopped + PID |
| Secrets Mode | `encrypted` or `file` |
| Decryption Key | Present/missing (encrypted mode) |
| Environment | Current environment name |
| Config Branch | Active branch in the config repo |
| Docker Services | List of running containers |
| Crontab | Whether restart cron entry exists |

---

### `docker:exec`

Opens a shell or runs a command inside the Docker container.

```
protocol docker:exec [cmd] [--dir=PATH]
```

| Argument | Description |
|---|---|
| `cmd` | (Optional) Command to run, defaults to `/bin/bash` |

---

### `migrate`

Interactive migration wizard for converting branch-based projects to release-based deployment.

```
protocol migrate [--secrets-only] [--dir=PATH]
```

| Option | Description |
|---|---|
| `--secrets-only` | Only set up encrypted secrets (skip strategy migration) |

**Migration steps:**

1. Detects current deployment strategy
2. Sets `deployment.strategy` to `release`
3. Creates initial release tag from current HEAD
4. Sets the GitHub release pointer variable
5. Optionally sets up encrypted secrets
6. Starts the release watcher

See [migration.md](migration.md) for a full guide.

---

## Release Commands

### `release:create`

Creates a new release: writes VERSION file, tags, pushes, and creates a GitHub Release.

```
protocol release:create [version] [--major] [--minor] [--draft] [--no-push] [--dir=PATH]
```

| Argument/Option | Description |
|---|---|
| `version` | (Optional) Semver version (e.g. `1.2.3`). Auto-bumps patch if omitted. |
| `--major` | Bump major version instead of patch |
| `--minor` | Bump minor version instead of patch |
| `--draft` | Create as a draft GitHub Release |
| `--no-push` | Tag locally without pushing or creating GitHub Release |

**What it does:**

1. Validates semver format
2. Ensures working tree is clean
3. Checks tag doesn't already exist
4. Writes `VERSION` file
5. Commits and tags
6. Pushes tag and commit
7. Creates a GitHub Release (requires `gh` CLI)

---

### `release:list`

Lists available releases for this repository.

```
protocol release:list [--dir=PATH]
```

Displays a table of all release tags. The currently deployed version is marked with a `*`. Falls back to local git tags if GitHub releases are unavailable.

---

### `release:changelog`

Creates a CHANGELOG.md file for your application.

```
protocol release:changelog [--dir=PATH]
```

---

### `release:prepare`

Prepares the codebase for the next release.

```
protocol release:prepare [--dir=PATH]
```

---

## Deployment Commands

### `deploy:push`

Deploys a release to ALL nodes by updating the GitHub release pointer variable.

```
protocol deploy:push <version> [--dir=PATH]
```

Sets the `PROTOCOL_ACTIVE_RELEASE` GitHub repository variable. All nodes running `deploy:slave` will detect the change and auto-deploy.

**Requires:** GitHub CLI (`gh`) authenticated with repo access.

---

### `deploy:rollback`

Rolls back ALL nodes to the previous release.

```
protocol deploy:rollback [--dir=PATH]
```

Reads `release.previous` from `protocol.lock` and runs `deploy:push` with that version. Fails if no previous release is recorded.

---

### `deploy:status`

Shows the current active release pointer and this node's deployed version.

```
protocol deploy:status [--dir=PATH]
```

Displays:
- Active release (from GitHub variable)
- Locally deployed version
- Whether the node is in sync

---

### `deploy:log`

Displays the deployment audit log.

```
protocol deploy:log [--limit=20] [--dir=PATH]
```

Shows the last N entries from `~/.protocol/deployments.log` with version transitions, timestamps, and outcomes.

---

### `deploy:slave`

Starts the release watcher daemon that polls for the active release.

```
protocol deploy:slave [--increment=60] [--no-daemon] [--dir=PATH]
```

| Option | Description |
|---|---|
| `--increment` | Seconds between polls (default: 60) |
| `--no-daemon` | Run in foreground instead of background |

Launches `bin/release-watcher.php` as a background process. The watcher:

1. Reads the `PROTOCOL_ACTIVE_RELEASE` GitHub repository variable
2. Compares to currently deployed version
3. If different, deploys the target version (forward or rollback)
4. Logs every deployment to the audit log

Stores the watcher PID in `protocol.lock`.

---

### `deploy:slave:stop`

Stops the release watcher daemon.

```
protocol deploy:slave:stop [--dir=PATH]
```

---

## Node Commands

### `node:deploy`

Deploy a specific release on THIS node only (for staging/testing).

```
protocol node:deploy <version> [--dir=PATH]
```

**What it does:**

1. Validates the tag exists
2. `git fetch --all --tags`
3. `git checkout tags/<version>`
4. Decrypts secrets if `deployment.secrets` is `encrypted`
5. Rebuilds Docker containers with injected secrets
6. Updates `protocol.lock`: `release.current`, `release.previous`, `release.deployed_at`
7. Writes an audit log entry

Use for manual deployments to staging before publishing to all nodes via `deploy:push`.

---

### `node:rollback`

Roll back THIS node only to the previous release.

```
protocol node:rollback [--dir=PATH]
```

Reads `release.previous` from `protocol.lock` and runs `node:deploy` with that version.

---

## Secrets Commands

### `secrets:setup`

Generate or store the encryption key on this node.

```
protocol secrets:setup [key]
```

| Argument | Description |
|---|---|
| `key` | (Optional) Hex-encoded key from another node. If omitted, generates a new key. |

Creates `~/.protocol/` (permissions `0700`) and writes the key to `~/.protocol/key` (permissions `0600`).

Run once per node. The same key must be shared across all nodes that need to decrypt the same config repo.

---

### `secrets:encrypt`

Encrypts a `.env` file into `.env.enc`.

```
protocol secrets:encrypt [file] [--dir=PATH] [--output=PATH]
```

| Argument | Description |
|---|---|
| `file` | (Optional) Input file, defaults to `.env` in the config repo |
| `--output` | (Optional) Output path, defaults to `.env.enc` alongside input |

**Typical workflow:**
```bash
# Edit secrets locally
vim ../myapp-config/.env

# Encrypt before committing
protocol secrets:encrypt

# Remove plaintext
rm ../myapp-config/.env

# Commit encrypted version
protocol config:save
```

---

### `secrets:decrypt`

Decrypts a `.env.enc` file and displays the plaintext.

```
protocol secrets:decrypt [file] [--dir=PATH]
```

Outputs decrypted contents to stdout. For debugging only — secrets are decrypted automatically at deploy time.

---

## Configuration Commands

### `config:env`

Sets the global environment name for this machine.

```
protocol config:env [environment]
```

---

### `config:init`

Initializes the configuration repository.

```
protocol config:init [environment] [--dir=PATH]
```

Creates a sibling directory (`appname-config/`), initializes a git repo, sets the environment as the branch name, and prompts for a remote URL.

**Requires:** `protocol.json` must exist.

---

### `config:cp`

Copies a file into the configuration repository.

```
protocol config:cp <path> [--dir=PATH]
```

---

### `config:mv`

Moves a file to the config repo, deletes from the app repo, creates a symlink back.

```
protocol config:mv <path> [--dir=PATH]
```

Also adds the file to `.gitignore`.

---

### `config:link`

Creates symlinks for non-secret config files.

```
protocol config:link [--dir=PATH]
```

Excludes `.gitignore`, `README.md`, `.git`, and `.env.enc` from symlinking. Stores the symlink list in `protocol.lock`.

---

### `config:unlink`

Removes all config symlinks.

```
protocol config:unlink [--dir=PATH]
```

---

### `config:switch`

Switches to a different environment branch.

```
protocol config:switch [environment] [--dir=PATH]
```

Saves changes, unlinks, switches branch, re-links.

---

### `config:new`

Creates a new environment branch.

```
protocol config:new [--dir=PATH]
```

---

### `config:refresh`

Rebuilds all symlinks.

```
protocol config:refresh [--dir=PATH]
```

---

### `config:save`

Commits and pushes config changes.

```
protocol config:save [--dir=PATH]
```

Prompts for a commit message.

---

### `config:slave` / `config:slave:stop`

Starts/stops the config repo watcher.

```
protocol config:slave [--increment=10] [--no-daemon] [--dir=PATH]
protocol config:slave:stop [--dir=PATH]
```

---

## Git Commands

### `git:pull`

Force-pulls from remote (branch mode only). Destructive.

```
protocol git:pull [local] [--dir=PATH]
```

---

### `git:slave` / `git:slave:stop`

Starts/stops branch tracking (dev mode only).

```
protocol git:slave [--increment=10] [--no-daemon] [--dir=PATH]
protocol git:slave:stop [--dir=PATH]
```

---

### `git:clean`

Cleans git repository bloat.

```
protocol git:clean [local] [--dir=PATH]
```

---

## Docker Commands

### `docker:compose`

Brings up Docker containers. In encrypted mode, decrypts and injects secrets.

```
protocol docker:compose [--dir=PATH]
```

---

### `docker:compose:rebuild`

Rebuilds and restarts containers with secrets injection.

```
protocol docker:compose:rebuild [--dir=PATH]
```

---

### `docker:compose:down`

Stops and removes containers.

```
protocol docker:compose:down [--dir=PATH]
```

---

### `docker:build` / `docker:pull` / `docker:push`

Build, pull, or push Docker images.

```
protocol docker:build [local] [image] [--dir=PATH]
protocol docker:pull [image] [--dir=PATH]
protocol docker:push [image] [--dir=PATH]
```

Registry credentials are read from environment variables, not `protocol.json`.

---

### `docker:logs`

Follows Docker container logs.

```
protocol docker:logs [--dir=PATH]
```

---

## System Commands

| Command | Description |
|---|---|
| `self:update` | Update Protocol to the latest version |
| `self:global` | Install Protocol as a global command (`--force` to replace existing) |
| `key:generate` | Generate SSH deploy key |
| `cron:add` | Add `@reboot` restart to crontab |
| `cron:remove` | Remove crontab entry |
| `nginx:logs` | Tail nginx/PHP-FPM logs from container |

---

## Hidden/Internal Commands

| Command | Purpose |
|---|---|
| `git:clone` | Clones a repo with setup (used by config:init) |
| `composer:install` | Runs bundled composer.phar install |
| `env:default` | Sets environment variables for the current process |
| `security:changedfiles` | Lists files changed in the last 15 days |
| `security:trojansearch` | Scans for suspicious code patterns |
| `ssh:banner` | Configures SSH MOTD banner (requires sudo) |
| `completion` | Shell completion override (no-op) |
