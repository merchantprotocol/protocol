# Deployment Strategies

Protocol supports three deployment strategies. Each one trades speed for safety — pick the one that matches how much risk you're comfortable with.

## Branch Mode

The simplest option. A background daemon watches the tip of a git branch and pulls whenever it changes. Every push deploys immediately.

```
You push to main
       │
       ▼
  ┌──────────┐   ┌──────────┐   ┌──────────┐
  │  Node 1  │   │  Node 2  │   │  Node N  │
  │          │   │          │   │          │
  │ watching │   │ watching │   │ watching │
  │ branch   │   │ branch   │   │ branch   │
  └────┬─────┘   └────┬─────┘   └────┬─────┘
       │              │              │
       ▼              ▼              ▼
    git pull       git pull       git pull
    restart        restart        restart
       │              │              │
       ▼              ▼              ▼
  ┌──────────────────────────────────────────┐
  │         Production Traffic               │
  │     (all nodes on latest commit)         │
  └──────────────────────────────────────────┘
```

Each node polls the branch independently. When it sees a new commit, it pulls and restarts. No coordination between nodes — they each act on their own.

**Good for:** Local development, personal projects, fast-moving prototypes where you want every push to go live immediately.

**Not good for:** Anything with customers. There's no versioning, no rollback, and no audit trail. If you push a bad commit, every node deploys it.

### Setup

```bash
protocol init
# Choose deployment strategy: branch
protocol start
```

That's it. The watcher starts and follows your branch.

## Release Mode

This is the one you want for production.

Instead of following a branch, each node watches a **pointer** — a GitHub repository variable called `PROTOCOL_ACTIVE_RELEASE`. You create tagged releases (`v1.0.0`, `v1.1.0`), and when you're ready to deploy, you update the pointer. Every node sees the change and checks out that exact version.

```
You run: protocol deploy:push v1.2.0
       │
       │  (updates PROTOCOL_ACTIVE_RELEASE → "v1.2.0")
       │
       ▼
  ┌──────────┐   ┌──────────┐   ┌──────────┐
  │  Node 1  │   │  Node 2  │   │  Node N  │
  │          │   │          │   │          │
  │ watching │   │ watching │   │ watching │
  │ pointer  │   │ pointer  │   │ pointer  │
  └────┬─────┘   └────┬─────┘   └────┬─────┘
       │              │              │
       ▼              ▼              ▼
  git checkout    git checkout    git checkout
  tags/v1.2.0    tags/v1.2.0    tags/v1.2.0
       │              │              │
       ▼              ▼              ▼
  ┌──────────────────────────────────────────┐
  │         Production Traffic               │
  │    (all nodes on v1.2.0, verified)       │
  └──────────────────────────────────────────┘
```

The pointer is the single source of truth. Nodes don't care about individual commits — they only move when the pointer changes. This means your team can merge 50 commits to `main` without triggering a single deployment.

**Rollback?** Update the pointer back to the previous version. Every node deploys it. No git surgery, no reverting commits — just point to the version you want.

```bash
protocol deploy:push v1.1.0   # every node rolls back to v1.1.0
```

**Good for:** Production environments, teams with multiple developers, anything where you need versioning, rollback, and an audit trail. Required for SOC 2 readiness.

### Setup

```bash
protocol init
# Choose deployment strategy: release
protocol start
```

### Deploying a Release

```bash
protocol release:create v1.2.0    # tag the release on GitHub
protocol deploy:push v1.2.0       # update the pointer — all nodes deploy
```

### How Nodes Pick It Up

Each node runs a release watcher daemon (started by `protocol start`). The watcher polls the GitHub variable every 60 seconds. When it sees a new value:

1. Fetches the tag from git
2. Checks out that exact version
3. Decrypts secrets from the config repo
4. Rebuilds Docker containers
5. Logs the deployment to the audit trail

No webhooks. No build servers. No deploy scripts. Just a variable and nodes that listen.

## Shadow Mode (Zero-Downtime)

Shadow mode builds on top of release mode. Instead of rebuilding containers in place (which causes downtime), it builds the new version in a separate directory while the current version keeps serving traffic. When the new version is ready, it swaps the ports — a sub-second operation.

```
  Current version serving traffic
  ┌──────────────────────────────────────────┐
  │           v1.2.0 on port 80              │  ← live
  └──────────────────────────────────────────┘

  Meanwhile, in the background...
  ┌──────────────────────────────────────────┐
  │           v1.3.0 on port 8080            │  ← building
  └──────────────────────────────────────────┘

  Health checks pass. Swap ports.

  ┌──────────────────────────────────────────┐
  │           v1.3.0 on port 80              │  ← now live
  └──────────────────────────────────────────┘
  ┌──────────────────────────────────────────┐
  │           v1.2.0 on standby              │  ← instant rollback
  └──────────────────────────────────────────┘
```

Each version lives in its own directory with its own git clone, Docker containers, and config files. They share nothing. The port swap is fast because the Docker image is already built — it only needs to recreate the container with different port mappings (~1 second).

**Rollback?** Swap back to the previous version. Its image is still cached. Sub-second.

**Good for:** Applications with long build times (Magento, large Laravel apps, compiled assets), mission-critical systems where any downtime is unacceptable, and environments where you want to test a release on shadow ports before it goes live.

### Setup

```bash
protocol shadow:init    # configure shadow deployment (interactive wizard)
```

### Deploying with Shadow Mode

```bash
protocol shadow:build v1.3.0     # clone, build, health-check on port 8080
curl http://localhost:8080/health  # optional: test it yourself
protocol shadow:start             # swap to production (~1 second)
```

Or fully automated — set `auto_promote: true` in protocol.json and the release watcher handles everything. Push a release, and the watcher builds the shadow, verifies it, and promotes it without any manual steps.

For the full details on shadow deployment — configuration, health checks, directory structure, and automation — see [Shadow Deployment](blue-green.md).

## Comparison

| | Branch | Release | Shadow |
|---|---|---|---|
| **How it deploys** | Pulls latest commit from branch | Checks out a tagged version | Builds new version in parallel, swaps ports |
| **Deploy trigger** | Any push to the branch | Pointer variable updated | Pointer variable updated (or manual build) |
| **Downtime** | Brief (container restart) | Brief (container restart) | None (port swap ~1s) |
| **Rollback** | None — push a fix | Change pointer to previous tag | Swap back to standby (~1s) |
| **Versioning** | No | Yes (semantic tags) | Yes (semantic tags) |
| **Audit trail** | No | Yes | Yes |
| **SOC 2 ready** | No | Yes | Yes |
| **Best for** | Local dev | Production | Mission-critical production |

## Choosing a Strategy

Start with **branch mode** on your laptop — it's the fastest way to see changes.

Move to **release mode** when you have users. It gives you versioning, rollback, and an audit trail with zero extra complexity. This is the right choice for most production deployments.

Add **shadow mode** when downtime matters. If your containers take minutes to build, or if you need to verify a release before it serves traffic, shadow mode lets you do that without affecting the live site.

You set the strategy once during `protocol init`. To change it later:

```bash
protocol init
# Choose: Update an existing Protocol project
# Choose: Change deployment strategy
```

All three strategies use the same config repo, the same encrypted secrets, and the same `protocol start` / `protocol stop` commands. The difference is what happens under the hood when a new version is available.
