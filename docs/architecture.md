# How Protocol Works Under the Hood

You don't need to read this page to use Protocol. But if you're the kind of person who wants to know what's happening behind the curtain — or if you're debugging something weird — this is for you.

## The 30-Second Version

Protocol is a PHP CLI tool built on Symfony Console. You type commands like `protocol start` and `protocol deploy:push 1.2.0`. Behind the scenes, it's managing four things:

1. **Your code** — git tags, releases, deployments
2. **Your configs** — a separate git repo with environment-specific files
3. **Your secrets** — encrypted `.env` files that decrypt themselves on arrival
4. **Your containers** — Docker Compose, up and down, rebuild when needed

Everything talks to git. Everything lives in git. Protocol is just the glue.

## How Deployment Works

### Release Mode (Recommended)

This is the one you want for anything beyond your laptop.

You create a versioned release — say, `v1.2.0`. That creates a git tag and a GitHub Release. Then you run `protocol deploy:push v1.2.0`, which sets a GitHub repository variable called `PROTOCOL_ACTIVE_RELEASE` to `v1.2.0`.

Every production node is running a tiny background daemon called the **release watcher**. It polls that variable every 60 seconds. When it sees the value change, it:

1. Fetches the new tag from git
2. Checks out that exact version
3. Decrypts your secrets
4. Rebuilds your Docker containers
5. Logs everything to an audit trail

That's it. No webhooks. No build servers. No deploy scripts. Just a variable that says "run this version" and nodes that listen.

```
You run:                              Every node:
─────────                             ───────────
protocol release:create 1.2.0        (watching...)
protocol deploy:push 1.2.0     ───▶  "1.2.0? On it."
                                      ✓ git checkout tags/1.2.0
                                      ✓ secrets decrypted
                                      ✓ docker rebuilt
                                      ✓ audit log written
```

**Rollback?** Change the variable back to `v1.1.0`. Every node deploys the old version. No git surgery. No reverting commits. Just point to the version you want.

### Branch Mode (Local Dev)

Simpler. A watcher follows the tip of a git branch and pulls whenever it changes. Good for your laptop. No versioning, no rollback, no audit trail — just "deploy whatever's latest."

## How Configs Work

Your project has a sibling directory — a separate git repo — that holds all your environment-specific files: `.env`, `nginx.conf`, cron schedules, PHP settings.

```
myapp/                    ← your code
myapp-config/             ← your configs (separate git repo)
├── .env.enc              ← encrypted secrets
├── nginx.conf            ← server config
├── php.ini               ← PHP settings
└── cron.d/               ← scheduled tasks
```

Each **branch** in the config repo is a different environment. Your laptop uses `localhost-sarah`. Production uses `production`. Same repo, different configs.

When Protocol starts, it symlinks the config files into your project directory. Your app doesn't know they came from somewhere else — it just sees `nginx.conf` sitting right where it expects it.

## How Secrets Work

Your `.env` files get encrypted with AES-256-GCM before they touch git. The encryption key lives on your machines at `~/.protocol/key` — it never goes into any repository.

When a node starts up, Protocol sees the `.env.enc` file, decrypts it using the local key, and your app reads `.env` like nothing happened.

The key is a 256-bit (64-character hex) string. You generate it once and copy it to every machine that needs to decrypt secrets. Same key for all environments — the secrets themselves differ because each environment is a different branch.

## What `protocol start` Actually Does

When you run `protocol start`, it works through six stages. Each stage shows its progress and collapses to a single status line — OK, PASS, or FAIL — before moving to the next:

```
[protocol] Scanning codebase.............. OK
[protocol] Infrastructure provisioning.... OK
[protocol] Container build & push......... OK
[protocol] Running security audit......... PASS
[protocol] SOC 2 compliance check......... PASS
[protocol] Health checks.................. PASS

✓ Deployment complete. All systems operational.
  Completed in 12.3s
```

Here's what each stage does:

1. **Scanning codebase** — Validates this is an initialized Protocol project with a `docker-compose.yml`
2. **Infrastructure provisioning** — Initializes the config repo, starts watchers (release watcher in release mode, git watcher in branch mode), links config files into your project, adds a `@reboot` crontab entry
3. **Container build & push** — Pulls or builds your Docker image, starts containers with `docker compose up --build -d`, runs `composer install` inside the container if needed
4. **Running security audit** — Scans for malicious code, checks file permissions, audits dependencies, looks for suspicious processes, validates Docker security, flags unauthorized file changes
5. **SOC 2 compliance check** — Verifies encrypted secrets, audit logging, release-based deployment, git integrity, reboot recovery, and key permissions
6. **Health checks** — Confirms Docker containers are actually running and watchers are alive

If a stage fails, it shows FAIL with the error detail and continues to the next stage. The final summary tells you whether everything passed or if there were issues.

In CI/CD environments (where there's no terminal), the output drops the ANSI animations and prints plain text lines instead.

### What `protocol stop` Does

`protocol stop` runs the same staged pattern in reverse — five stages that shut everything down and verify the shutdown was clean:

```
[protocol] Stopping watchers.............. OK
[protocol] Unlinking configuration........ OK
[protocol] Stopping containers............ OK
[protocol] Removing crontab entry......... OK
[protocol] Verifying shutdown............. PASS

✓ Shutdown complete. All services stopped.
  Completed in 3.1s
```

The verification stage checks that containers are actually stopped and watchers are no longer running — it doesn't just trust that the stop commands worked.

## The Audit Trail

Every deployment writes a line to `~/.protocol/deployments.log`:

```
2024-01-15T10:30:01Z deploy repo=/opt/myapp from=v1.1.0 to=v1.2.0 status=success
2024-03-01T14:22:00Z rollback repo=/opt/myapp from=v1.3.0 to=v1.2.0 status=success
```

Timestamped. Versioned. What was running before, what's running now, did it work. SOC2 auditors love this.

View it anytime with `protocol deploy:log`.

## Files That Matter

| File | Where | What it does |
|---|---|---|
| `protocol.json` | Your project root (in git) | Your project's Protocol settings — Docker image, deploy strategy, config repo URL |
| `protocol.lock` | Your project root (gitignored) | Runtime state — what version is deployed, which processes are running, which files are symlinked |
| `~/.protocol/key` | Each machine (never in git) | Your encryption key for decrypting secrets |
| `~/.protocol/deployments.log` | Each machine | Audit trail of every deployment |
| `config/config.php` | Protocol install directory | This machine's environment name |

## The Big Picture

```
                          GitHub
                    ┌──────────────────┐
                    │  App Repo         │
                    │  Config Repo      │
                    │  Encrypted .env   │
                    │  Release Tags     │
                    │  Active Release   │  ← one variable controls everything
                    └────────┬─────────┘
                             │
              ┌──────────────┼──────────────┐
              ▼              ▼              ▼
         Dev Machine    Staging Node   Prod Nodes
         (localhost)    (staging)      (production)

         protocol       protocol       protocol
         start          start          start

         Watches        Watches        Watches
         branch tip     release var    release var

         Own config     Own config     Own config
         branch         branch         branch

         Same key       Same key       Same key
```

Every node is independent. They don't talk to each other. They don't need a coordinator. They just watch GitHub and do what it says.
