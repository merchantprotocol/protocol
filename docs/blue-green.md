# Shadow Deployment

Shadow deployment builds each release version into its own self-contained directory with a full git clone, config, and Docker containers named with the release tag. One version serves live traffic (active), the other builds in the background (shadow). When the shadow is verified, you swap the ports — a sub-second operation that gives you zero-downtime deploys with instant rollback.

## Why Shadow Deployment?

Standard deployment rebuilds containers in place. For applications with long build times (Magento, large Laravel apps, compiled assets), this means:

- Minutes of downtime while containers rebuild
- No way to test the new version before it serves traffic
- Rollback requires another full rebuild

Shadow deployment solves all three:

| | Standard | Shadow |
|---|---|---|
| Build time impact | Downtime during build | Zero — build happens on shadow ports |
| Pre-production testing | None | Test on shadow port before promoting |
| Rollback speed | Full rebuild (minutes) | Port swap (~1 second) |
| Risk | Deploy and pray | Verify, then promote |

## How It Works

```
                    Port 80/443 (production traffic)
                            │
                    ┌───────┴────────┐
                    │                │
               ┌────▼───┐      ┌────▼───┐
               │ v1.2.0 │      │ v1.3.0 │
               │ active │      │ shadow │
               │  :80   │      │ :8080  │
               └────────┘      └────────┘

1. v1.2.0 serves traffic on port 80
2. v1.3.0 builds in myapp-releases/v1.3.0/ on port 8080
3. Health checks pass on :8080
4. Swap: v1.3.0 gets :80, v1.2.0 goes to standby
5. v1.3.0 is now serving. v1.2.0 is available for instant rollback.
6. Something wrong? Swap back in 1 second.
```

The key insight: **changing port mappings on a Docker container doesn't rebuild the image.** It only recreates the container (~1 second) because the image is already built and cached.

Each version gets its own Docker containers named with the release tag (e.g., `myapp-v1.2.0`), so they never collide.

## Quick Start

### 1. Initialize Shadow Deployment

```bash
protocol shadow:init
```

An interactive wizard walks you through configuration — releases directory, auto-promote preferences, health checks. This enables `bluegreen.enabled: true` in protocol.json.

Or, for a fresh production server without an existing clone:

```bash
mkdir /opt/myapp && cd /opt/myapp
protocol init
# Select: "Configure as a shadow deployment node"
# Enter your GitHub repo URL
# Choose your releases directory
```

### 2. Build a Release in the Shadow

```bash
protocol shadow:build v1.3.0
```

This creates a version-named release directory:
1. Clones your repo into `<project>-releases/v1.3.0/`
2. Checks out the `v1.3.0` tag
3. Patches docker-compose.yml for parameterized ports
4. Builds and starts containers on shadow ports (8080/8443)
5. Runs health checks against the shadow port
6. Marks the version as "ready"

This is the slow step — it can take as long as it needs. Production traffic is unaffected.

### 3. Promote to Production

```bash
protocol shadow:start
```

This is the fast step (~1 second):
1. Stops the active version's containers
2. Rewrites port assignments (shadow → production ports)
3. Starts the shadow version on production ports (image already built = instant)
4. Updates state tracking

The old active version stays in "standby" with its containers stopped but image cached.

### 4. Rollback (Instant)

```bash
protocol shadow:rollback
```

Swaps back to the previous version. Same ~1 second operation — the standby version's image is still cached, so starting it is near-instant.

## Commands

| Command | Description | Speed |
|---|---|---|
| `shadow:init` | Configure shadow deployment (interactive wizard) | Once |
| `shadow:build <version>` | Build a release in a version-named directory | Slow (minutes) |
| `shadow:start` | Promote shadow to production (swap ports) | Fast (~1s) |
| `shadow:rollback` | Revert to previous version | Fast (~1s) |
| `shadow:status` | Show all releases, states, and health | Instant |

### shadow:init

```bash
protocol shadow:init
```

Interactive wizard that configures shadow deployment in protocol.json. Sets the releases directory path, auto-promote preferences, and health checks. Run this once before using other shadow commands.

Release directories are created automatically when you run `shadow:build`.

### shadow:build

```bash
protocol shadow:build <version> [--skip-health-check]
```

Creates `<project>-releases/<version>/` with a full git clone, checks out the tag, and builds Docker containers on shadow ports (8080/8443).

**Options:**
- `--skip-health-check` — Skip post-build health checks

**What it does:**
1. Validates the version tag exists
2. Clones the repo into the releases directory
3. Checks out the tag
4. Patches docker-compose.yml for parameterized ports
5. Builds and starts containers on shadow ports
6. Runs health checks (unless skipped)
7. Sets version status to "ready"

### shadow:start

```bash
protocol shadow:start
```

Promotes the shadow version to production. The shadow version must have status "ready" (from a successful `shadow:build`).

**What happens:**
1. Stops active version's containers
2. Rewrites env files with production port assignments
3. Starts shadow version on production ports (80/443) — near-instant since image is cached
4. Runs post-promote health check
5. Updates state: shadow becomes "serving", old active becomes "standby"

If the start fails, the original active version is automatically restored.

### shadow:rollback

```bash
protocol shadow:rollback
```

Swaps back to the previous active version. Requires the standby version to have status "standby" (meaning it was previously serving and can resume).

Same port-swap mechanism as `shadow:start` — approximately 1 second.

### shadow:status

```bash
protocol shadow:status [--json]
```

Displays the current state of all release versions:

```
Shadow Deployment Status
-------------------------------------------------------

  Active:   v1.2.0
  Previous: v1.1.0 (rollback available)
  Shadow:   v1.3.0 (ready to promote)
  Promoted: 2026-03-10T21:00:00+00:00
  Releases: /opt/myapp-releases/

      Version          Port     Status       Running
      -------          ----     ------       -------
  *   v1.2.0           80       serving      yes
      v1.3.0           8080     ready        yes
      v1.1.0           8080     standby      no

  Auto-promote: disabled
  Health checks: 1 configured
```

Use `--json` for machine-readable output.

## Configuration

### protocol.json

```json
{
    "bluegreen": {
        "enabled": true,
        "releases_dir": "myapp-releases",
        "git_remote": "git@github.com:yourorg/yourapp.git",
        "auto_promote": false,
        "health_checks": [
            {"type": "http", "path": "/health", "expect_status": 200},
            {"type": "http", "path": "/", "expect_status": 200, "timeout": 15},
            {"type": "exec", "command": "php artisan migrate:status", "expect_exit": 0}
        ]
    }
}
```

| Key | Type | Default | Description |
|---|---|---|---|
| `enabled` | boolean | `false` | Enable shadow deployment mode |
| `releases_dir` | string | `<project>-releases` | Releases directory (relative to parent, or absolute path) |
| `git_remote` | string | repo's remote | Git URL to clone releases from |
| `auto_promote` | boolean | `false` | Automatically promote after successful shadow build |
| `health_checks` | array | `[]` | Health checks to run after build and promote |

### Health Check Types

#### HTTP Check

```json
{"type": "http", "path": "/health", "expect_status": 200, "timeout": 10}
```

Sends a GET request to `http://127.0.0.1:<port>/<path>` and checks the HTTP status code. Retries up to 3 times with 3-second intervals.

| Field | Default | Description |
|---|---|---|
| `path` | `/health` | URL path to check |
| `expect_status` | `200` | Expected HTTP status code |
| `timeout` | `10` | Request timeout in seconds |

#### Exec Check

```json
{"type": "exec", "command": "php artisan migrate:status", "expect_exit": 0}
```

Runs a command inside the version's Docker container and checks the exit code.

| Field | Default | Description |
|---|---|---|
| `command` | (required) | Command to run in the container |
| `expect_exit` | `0` | Expected exit code |

### protocol.lock State

Shadow deployment state is tracked in `protocol.lock` (gitignored):

```json
{
    "bluegreen": {
        "active_version": "v1.2.0",
        "previous_version": "v1.1.0",
        "shadow_version": "v1.3.0",
        "promoted_at": "2026-03-10T21:00:00+00:00",
        "releases": {
            "v1.2.0": {
                "version": "v1.2.0",
                "port": 80,
                "status": "serving"
            },
            "v1.3.0": {
                "version": "v1.3.0",
                "port": 8080,
                "status": "ready"
            },
            "v1.1.0": {
                "version": "v1.1.0",
                "port": 8080,
                "status": "standby"
            }
        }
    }
}
```

**Release statuses:**

| Status | Meaning |
|---|---|
| `ready` | Build complete, health checks passed, waiting for promotion |
| `serving` | Currently serving production traffic |
| `standby` | Previously serving, available for instant rollback |
| `failed` | Build or health check failed |

## Automatic Deployment with the Release Watcher

When shadow deployment is enabled, the release watcher daemon (`protocol start` → `deploy:slave`) automatically uses shadow builds instead of in-place deployments:

1. Watcher detects a new release pointer on GitHub
2. Clones and builds the release in a new directory (shadow ports)
3. Runs health checks
4. If `auto_promote: true` — automatically swaps to production
5. If `auto_promote: false` — logs "Shadow ready" and waits for manual `shadow:start`

This means you can still use the same deployment workflow:

```bash
protocol release:create v1.3.0
protocol deploy:push v1.3.0
```

The watcher handles the shadow mechanics automatically. The only difference is whether traffic switches immediately (`auto_promote: true`) or waits for your confirmation.

## Directory Structure

```
/opt/
├── myapp/                     ← project directory (protocol.json lives here)
│   ├── protocol.json          ← bluegreen config
│   ├── protocol.lock          ← runtime state (gitignored)
│   └── docker-compose.yml     ← original (not used in shadow mode)
│
└── myapp-releases/            ← sibling releases directory
    ├── v1.2.0/
    │   ├── .git/
    │   ├── .env.bluegreen     ← port config (auto-generated)
    │   ├── docker-compose.yml ← patched with parameterized ports
    │   └── ...app files...
    ├── v1.3.0/
    │   ├── .git/
    │   ├── .env.bluegreen
    │   ├── docker-compose.yml
    │   └── ...app files...
    └── v1.1.0/
        └── ...standby version...
```

Each release is a full, independent git clone. They share nothing — different containers, different port mappings, different checked-out versions. Docker containers are named with the version tag (e.g., `myapp-v1.2.0`) so they never collide.

The releases directory is a sibling to your project by default (`<project>-releases/`), but you can configure any path in protocol.json.

## Ports

| Port | Assignment |
|---|---|
| 80 | Active version HTTP |
| 443 | Active version HTTPS |
| 8080 | Shadow version HTTP |
| 8443 | Shadow version HTTPS |

The shadow ports (8080/8443) let you inspect and test the shadow build before promoting. For example: `curl http://localhost:8080/health`

## Workflow Examples

### First-Time Setup (Existing Project)

```bash
protocol shadow:init             # Configure shadow deployment (wizard)
protocol shadow:build v1.0.0     # Build initial version
protocol shadow:start            # Promote to production
```

### First-Time Setup (Fresh Server)

```bash
mkdir /opt/myapp && cd /opt/myapp
protocol init                    # Select "Configure as a shadow deployment node"
protocol shadow:build v1.0.0    # Build initial version
protocol shadow:start            # Promote to production
protocol start                   # Start watcher daemon
```

### Deploying a New Version

```bash
protocol release:create v1.1.0    # Tag the release
protocol shadow:build v1.1.0      # Build in shadow (takes time)

# Optional: test the shadow
curl http://localhost:8080/health

protocol shadow:start              # Swap to production (~1 second)
```

### Emergency Rollback

```bash
protocol shadow:rollback    # Back to previous version (~1 second)
```

### Fully Automated (Release Watcher)

```json
{
    "bluegreen": {
        "enabled": true,
        "auto_promote": true,
        "health_checks": [
            {"type": "http", "path": "/health", "expect_status": 200}
        ]
    }
}
```

```bash
# On your dev machine:
protocol release:create v1.1.0
protocol deploy:push v1.1.0

# On production (automatic):
# 1. Watcher detects v1.1.0
# 2. Clones into myapp-releases/v1.1.0/ and builds
# 3. Health checks pass
# 4. Auto-promotes to production
# 5. Old version on standby for rollback
```

## Compatibility

Shadow deployment is **opt-in** and fully backward compatible:

- `bluegreen.enabled: false` (default) — Protocol works exactly as before
- Existing `deploy:push`, `deploy:rollback`, `release:create` commands are unchanged
- The release watcher automatically uses the right strategy based on config
- `protocol start` and `protocol stop` are shadow-deployment aware

## Audit Logging

All shadow operations are logged to `~/.protocol/deployments.log`:

```
2026-03-10T21:00:00+00:00 SHADOW repo='/opt/myapp' action='build' slot='v1.3.0' version='v1.3.0' status='success' user='deploy'
2026-03-10T21:05:00+00:00 SHADOW repo='/opt/myapp' action='promote' slot='v1.3.0' version='v1.3.0' status='success' user='deploy'
2026-03-10T21:05:00+00:00 DEPLOY repo='/opt/myapp' from='v1.2.0' to='v1.3.0' status='success' scope='shadow-promote' user='deploy'
```

SOC 2 ready — every build, promotion, and rollback is tracked with timestamps, versions, and user identity.
