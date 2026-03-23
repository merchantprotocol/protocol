# Container Name Resolution

How Protocol resolves which Docker container to talk to.

## Deployment Strategies

Protocol supports four deployment strategies. The strategy determines how container names are resolved, how containers are started/stopped, and what infrastructure is involved.

| Strategy | Default | Use Case | Watchers | Release Dirs | Container Versioning |
|----------|---------|----------|----------|-------------|---------------------|
| `none` | Yes | Local development | None | No | No |
| `branch` | No | Legacy git-polling | `git:slave` | No | No |
| `release` | No | Tag-based production | `deploy:slave` | Yes | Yes (`myapp-v1_2_0`) |
| `bluegreen` | No | Zero-downtime production | `deploy:slave` | Yes | Yes (`myapp-v1_2_0`) |

When no `deployment.strategy` is set in `protocol.json` or NodeConfig, the strategy defaults to `none`.

---

## User Stories

### Story 1: Local Developer — No Deployment Strategy

**Who:** A developer running `protocol start` / `protocol stop` / `protocol exec` on their laptop.

**Environment:**
- Strategy: `none` (default — no `deployment.strategy` set)
- No releases directory, no `.env.deployment`, no versioned containers
- `docker-compose.yml` has `container_name: ghostagent`
- `protocol.json` has `docker.container_name: "ghostagent"`

**Flow:**
```
protocol start
  → detect env: development
  → detect strategy: none
  → skip watchers (no git:slave, no deploy:slave)
  → link config repo if present
  → docker compose up --build -d
  → container created: "ghostagent"

protocol exec
  → detect strategy: none
  → ContainerName::resolveActive(repo_dir)
  → resolveFromDir(repo_dir)
  → protocol.json → "ghostagent"
  → docker exec -it "ghostagent" sh

protocol stop
  → detect strategy: none
  → docker compose down in repo dir
  → container "ghostagent" removed

protocol status
  → detect strategy: none
  → ContainerName::resolveAll(repo_dir)
  → docker-compose.yml → ["ghostagent"]
  → shows: ghostagent (running)
```

**Resolution chain for `none`:**
1. No `.env.deployment` or `.env.bluegreen` → skip
2. `protocol.json` → `docker.container_name` = `"ghostagent"` → use it
3. (Fallback) `docker-compose.yml` → `container_name: ghostagent`

**Key behavior:** No deployment state machinery is consulted. `DeploymentState::allKnownDirs()` returns just `[repo_dir]`. `ContainerName::resolveAll()` reads directly from `docker-compose.yml`. `ProtocolStop` runs `docker compose down` in the repo dir. Period.

---

### Story 2: Production Node — Release Strategy

**Who:** A production server polling for release tags.

**Environment:**
- environment: production
- Strategy: `release`
- Releases cloned into `/opt/myapp-releases/v1.2.0/`
- Each release has `.protocol/deployment.json` with `container_name: myapp-v1_2_0`
- Compose file patched: `container_name: ${CONTAINER_NAME:-myapp}`

**Flow:**
```
release-watcher detects v1.2.0
  → ReleaseBuilder clones into releases/v1.2.0/
  → writes deployment.json + .env.deployment: CONTAINER_NAME=myapp-v1_2_0
  → patches docker-compose.yml
  → calls protocolStopStart()

protocol start
  → reads release.active from NodeConfig → finds release dir
  → docker compose --env-file .env.deployment up -d
  → container "myapp-v1_2_0" starts
  → sets release.active = v1.2.0 in NodeConfig

protocol exec "php artisan migrate"
  → ContainerName::resolveActive()
  → strategy=release → find active release dir
  → deployment.json → "myapp-v1_2_0"
  → docker exec myapp-v1_2_0 php artisan migrate

protocol stop
  → ContainerName::resolveAll() → scans all release dirs
  → stops each versioned container
```

---

### Story 3: Production Node — Blue-Green Strategy

**Who:** A production server running zero-downtime deployments.

**Environment:**
- Strategy: `bluegreen`
- Production: `myapp-v1_2_0` on ports 80/443
- Shadow: `myapp-v1_3_0` on ports 18080/18081

**Flow:**
```
protocol status
  → iterates all release dirs
  → ContainerName::resolveFromDir() per dir → versioned names
  → shows: myapp-v1_2_0 (active), myapp-v1_3_0 (shadow)

protocol exec
  → resolves active release dir from NodeConfig
  → deployment.json → "myapp-v1_2_0"
  → docker exec myapp-v1_2_0 sh

protocol stop
  → resolves all release dirs
  → stops each container by versioned name
```

---

## `ContainerName` Helper

A single class (`src/Helpers/ContainerName.php`) that encapsulates all resolution logic.

### Resolution Chain — Single Directory

For any given directory (repo dir or release dir):

```
┌─────────────────────────────────────────────────┐
│ 1. .protocol/deployment.json → container_name   │
│    Source of truth for release/bluegreen dirs.   │
├─────────────────────────────────────────────────┤
│ 2. .env.deployment → CONTAINER_NAME=            │
│    Flat-file fallback. Same data, different fmt. │
├─────────────────────────────────────────────────┤
│ 3. .env.bluegreen → CONTAINER_NAME=             │
│    Legacy migration only.                        │
├─────────────────────────────────────────────────┤
│ 4. protocol.json → docker.container_name        │
│    Primary source for none/branch strategies.    │
├─────────────────────────────────────────────────┤
│ 5. docker-compose.yml → services.*.container_name│
│    Parse YAML, resolve ${VAR:-default} from .env.│
│    If single service: return it.                 │
│    If multiple: return null (caller must prompt). │
├─────────────────────────────────────────────────┤
│ 6. return null                                   │
└─────────────────────────────────────────────────┘
```

### Resolution Chain — Active Container (strategy-aware)

```
┌─────────────────────────────────────────────────┐
│ 1. Determine strategy from NodeConfig /          │
│    protocol.json (none|branch|release|bluegreen) │
├─────────────────────────────────────────────────┤
│ 2a. none | branch:                               │
│     → resolveFromDir(repo_dir)                   │
│                                                   │
│ 2b. release | bluegreen:                         │
│     → read release.active from NodeConfig        │
│     → build release dir path from releases_dir   │
│     → resolveFromDir(release_dir)                │
│     → fallback: resolveFromDir(repo_dir)         │
└─────────────────────────────────────────────────┘
```

### Resolution Chain — All Containers (stop/status)

```
┌─────────────────────────────────────────────────┐
│ 1. none:                                         │
│    → parse docker-compose.yml in repo_dir        │
│    → return all container names directly         │
│    → NO deployment state, NO release scanning    │
│                                                   │
│ 2. branch:                                       │
│    → DeploymentState::allKnownDirs()             │
│    → resolveFromDir() for each                   │
│                                                   │
│ 3. release | bluegreen:                          │
│    → scan releases_dir for all version dirs      │
│    → resolveFromDir() for each                   │
│    → also check allKnownDirs() for non-release   │
│    → deduplicate and return all                  │
└─────────────────────────────────────────────────┘
```

---

## Public API

```php
class ContainerName
{
    /**
     * Resolve container name for a specific directory.
     * Walks: deployment.json → .env.deployment → .env.bluegreen → protocol.json → compose file.
     */
    public static function resolveFromDir(string $dir): ?string

    /**
     * Resolve the currently active container name (strategy-aware).
     * None/Branch: resolves from repo dir.
     * Release/bluegreen: resolves from active release dir.
     */
    public static function resolveActive(string $repoDir): ?string

    /**
     * Resolve all known container names across all deployment dirs.
     * None: reads directly from docker-compose.yml in repo dir.
     * Branch: uses DeploymentState::allKnownDirs().
     * Release/bluegreen: scans all release dirs.
     */
    public static function resolveAll(string $repoDir): array

    /**
     * Check if the active container is currently running.
     */
    public static function isActiveRunning(string $repoDir): bool
}
```

---

## Logging

All resolution calls log to `Log::debug('container-name', ...)` — silent by default, visible with `PROTOCOL_LOG_LEVEL=debug`.

**None strategy (dev machine):**
```
[DEBUG] [container-name] resolve-all repo=/home/dev/ghostagent strategy=none
[DEBUG] [container-name] strategy=none, compose names: [ghostagent]
```

**Successful resolution:**
```
[DEBUG] [container-name] resolve dir=/opt/releases/v1.2.0
[DEBUG] [container-name]   deployment.json → "myapp-v1_2_0"
[DEBUG] [container-name] resolved: "myapp-v1_2_0" (source: deployment.json)
```

**Fallthrough:**
```
[DEBUG] [container-name] resolve dir=/home/dev/myapp
[DEBUG] [container-name]   deployment.json: not found
[DEBUG] [container-name]   .env.deployment: not found
[DEBUG] [container-name]   .env.bluegreen: not found
[DEBUG] [container-name]   protocol.json → "inbound"
[DEBUG] [container-name] resolved: "inbound" (source: protocol.json)
```

**Failure:**
```
[WARN] [container-name] resolve dir=/opt/myapp — no source found
[WARN] [container-name] resolve-active — no active release dir, falling back to repo dir
```

---

## How `none` Strategy Differs

When `deployment.strategy` is not set (or explicitly set to `none`), Protocol treats the project as a pure development environment:

| Behavior | `none` | `branch` | `release` / `bluegreen` |
|----------|--------|----------|------------------------|
| `protocol start` watchers | Skipped | `git:slave` (non-dev) | `deploy:slave` |
| `protocol start` containers | `docker compose up` in repo dir | Same | From active release dir |
| `protocol stop` containers | `docker compose down` in repo dir | Via `allKnownDirs()` | Via `allKnownDirs()` + release scan |
| `ContainerName::resolveAll()` | Reads `docker-compose.yml` directly | Via `allKnownDirs()` | Scans all release dirs |
| `DeploymentState::allKnownDirs()` | Returns `[repo_dir]` | Searches NodeConfig, state | Searches NodeConfig, state, releases |
| `DeploymentState::current()` | Not consulted | Checked | Checked |
| `BlueGreen::isEnabled()` | `false` | `false` | `true` |

The `none` strategy ensures that no deployment state machinery runs on a machine where there is no deployment happening. The repo dir is the source of truth — the developer's working directory, period.
