# Commands

Everything Protocol can do, organized by what you're trying to accomplish. Run `protocol <command> --help` on any command for the full details.

## The Essentials

These are the commands you'll use every day.

### `protocol init`

Set up a new project or update an existing one. A wizard walks you through it.

```bash
protocol init
```

If it's a new project, you pick your Docker image, choose a deploy strategy, and optionally set up secrets and a config repo. If you've already set up, it offers to fix/migrate, change strategy, or set up secrets.

Safe to re-run anytime — it detects what's already there.

### `protocol start`

Start everything. This is the one command that makes it all work.

```bash
protocol start
```

Runs through six stages — scanning your codebase, provisioning infrastructure, building containers, running a security audit, checking SOC 2 readiness, and verifying health. Each stage shows its progress and collapses to OK, PASS, or FAIL:

```
[protocol] Scanning codebase.............. OK
[protocol] Infrastructure provisioning.... OK
[protocol] Container build & push......... OK
[protocol] Running security audit......... PASS
[protocol] SOC 2 readiness check.......... PASS
[protocol] Health checks.................. PASS

✓ Deployment complete. All systems operational.
  Environment  production
  Strategy     release (v1.2.0)
  Secrets      decrypted
  Containers   3/3 running
  Watchers     release watcher running
  Crontab      installed
  Completed in 12.3s
```

The summary confirms your environment, whether secrets were decrypted, how many containers are up, whether watchers are active, and crontab status. If a stage fails, it shows the error detail below the FAIL line and continues to the next stage. In CI/CD environments (non-TTY), the output drops the ANSI formatting automatically.

### `protocol stop`

Stop everything. Kills watchers, unlinks configs, stops Docker containers, removes the reboot cron entry.

```bash
protocol stop
```

Same staged output as `protocol start` — five stages with verification at the end:

```
[protocol] Stopping watchers.............. OK
[protocol] Unlinking configuration........ OK
[protocol] Stopping containers............ OK
[protocol] Removing crontab entry......... OK
[protocol] Verifying shutdown............. PASS

✓ Shutdown complete. All services stopped.
  Environment  production
  Containers   3/3 stopped
  Watchers     stopped
  Crontab      removed
  Completed in 3.1s
```

### `protocol status`

See what's going on. Shows your deploy strategy, current version, running watchers, Docker containers, and whether everything looks healthy.

```bash
protocol status
```

### `protocol restart`

Stop and start again. Designed for `@reboot` crontab entries so your app survives server restarts.

```bash
protocol restart
```

---

## Releases

Creating and managing versioned releases of your code.

### `protocol release:create`

Tag a new release. Writes a VERSION file, creates a git tag, pushes it, and creates a GitHub Release.

```bash
protocol release:create              # auto-bumps patch (1.0.0 → 1.0.1)
protocol release:create 2.0.0       # specific version
protocol release:create --minor     # bumps minor (1.0.1 → 1.1.0)
protocol release:create --major     # bumps major (1.1.0 → 2.0.0)
protocol release:create --draft     # creates as draft release
```

### `protocol release:list`

See all your releases. The currently deployed version gets a `*` next to it.

```bash
protocol release:list
```

### `protocol release:prepare`

Prepare the codebase for the next release. Runs any pre-release tasks.

```bash
protocol release:prepare
```

### `protocol release:changelog`

Generate a CHANGELOG.md file from your git history.

```bash
protocol release:changelog
```

---

## Deployment

Pushing releases to your fleet and rolling back when things go wrong.

### `protocol deploy:push`

Deploy a release to ALL nodes. Sets the GitHub variable that every node watches.

```bash
protocol deploy:push 1.2.0
```

Every node running `protocol start` will pick this up within 60 seconds and deploy automatically.

### `protocol deploy:rollback`

Undo the last deploy. Sets the pointer back to the previous version. Every node follows.

```bash
protocol deploy:rollback
```

### `protocol deploy:status`

Check if your nodes are in sync. Shows the active release (what GitHub says) vs. the local version (what this node is running).

```bash
protocol deploy:status
```

### `protocol deploy:log`

View the deployment audit trail. Every deploy and rollback is logged with timestamps and version transitions.

```bash
protocol deploy:log
protocol deploy:log --limit=50      # show more entries
```

### `protocol node:deploy`

Deploy a specific version on THIS node only. Useful for testing a release on staging before pushing it to everyone.

```bash
protocol node:deploy 1.2.0          # on your staging server
```

### `protocol node:rollback`

Roll back THIS node only.

```bash
protocol node:rollback
```

### `protocol deploy:slave`

Start the release watcher daemon. Polls the GitHub variable for active release changes and deploys automatically. Used internally by `protocol start` in release mode.

```bash
protocol deploy:slave
protocol deploy:slave --interval=30   # poll every 30 seconds
protocol deploy:slave --no-daemon     # run in foreground (debugging)
```

### `protocol deploy:slave:stop`

Stop the release watcher daemon.

```bash
protocol deploy:slave:stop
```

---

## Shadow (Blue-Green) Deployment

Zero-downtime deployments using shadow directories. Each release gets its own directory with a full git clone, Docker containers, and config files. Traffic is swapped instantly when the new version is healthy.

### `protocol shadow:init`

Initialize shadow deployment configuration for your project.

```bash
protocol shadow:init
```

### `protocol shadow:build`

Build a release version in a shadow directory. Clones the repo, checks out the version, sets up Docker, and runs health checks.

```bash
protocol shadow:build v1.2.0
protocol shadow:build v1.2.0 --skip-health-check
```

### `protocol shadow:start`

Promote the shadow version to production by swapping ports.

```bash
protocol shadow:start
```

### `protocol shadow:rollback`

Roll back to the previous version instantly by swapping ports back.

```bash
protocol shadow:rollback
```

### `protocol shadow:status`

Show shadow deployment status — which version is active, which is standby, health state.

```bash
protocol shadow:status
protocol shadow:status --json         # raw JSON output
```

---

## Secrets

Managing your encryption key and encrypted files.

### `protocol secrets:setup`

Generate a new encryption key or store one from another node.

```bash
protocol secrets:setup                          # generate new key
protocol secrets:setup "your-64-char-hex-key"   # store existing key
```

The key is saved at `~/.protocol/.node/key` with strict permissions. Run this once per machine.

In CI/CD, it also reads from the `PROTOCOL_ENCRYPTION_KEY` environment variable automatically.

### `protocol secrets:key`

View your encryption key and all the ways to transfer it to other machines.

```bash
protocol secrets:key                            # show key + transfer options
protocol secrets:key --raw                      # just the key (for scripting)
protocol secrets:key --push                     # push to GitHub as a secret
protocol secrets:key --scp=deploy@prod-server   # SCP to a remote node
```

### `protocol secrets:encrypt`

Encrypt `.env` files in your config repo.

```bash
protocol secrets:encrypt              # encrypts .env → .env.enc
protocol secrets:encrypt myfile.env   # encrypt a specific file
```

### `protocol secrets:decrypt`

Decrypt and display an encrypted file. For debugging — secrets are decrypted automatically during `protocol start`.

```bash
protocol secrets:decrypt
```

---

## Configuration

Managing your config repo and environment-specific files.

### `protocol config:init`

The config wizard. Creates your config repo, encrypts or decrypts secrets, or re-initializes from scratch.

```bash
protocol config:init
```

If a config repo already exists, it shows you a smart menu — recommending encrypt if you have unencrypted `.env` files, or decrypt if you have encrypted files but no key on this machine.

### `protocol config:env`

Set this machine's environment name. This determines which branch of the config repo gets used.

```bash
protocol config:env production
protocol config:env localhost-sarah
```

### `protocol config:mv`

Move a file from your project into the config repo. Creates a symlink back so your app still finds it.

```bash
protocol config:mv .env
protocol config:mv nginx.conf
```

Also adds the file to `.gitignore` in your project.

### `protocol config:link`

Create all the symlinks from your config repo into your project. Happens automatically during `protocol start`, but you can run it manually.

```bash
protocol config:link
```

### `protocol config:unlink`

Remove all config symlinks.

```bash
protocol config:unlink
```

### `protocol config:switch`

Switch to a different environment. Saves current changes, unlinks, switches the branch, and re-links.

```bash
protocol config:switch staging
protocol config:switch production
```

### `protocol config:save`

Commit and push changes in your config repo.

```bash
protocol config:save
```

### `protocol config:cp`

Copy a file into the config repo without removing it from your project (unlike `config:mv` which moves it).

```bash
protocol config:cp nginx.conf
```

### `protocol config:new`

Create a new configuration repository from scratch.

```bash
protocol config:new
```

### `protocol config:refresh`

Clear all config symlinks and rebuild them. Useful if symlinks get out of sync.

```bash
protocol config:refresh
```

### `protocol config:slave`

Keep the config repo in sync with its remote. Polls for changes and pulls automatically.

```bash
protocol config:slave
protocol config:slave --increment=30     # poll every 30 seconds
protocol config:slave --no-daemon        # run in foreground
```

Used internally by `protocol start`. You rarely need to run this directly.

### `protocol config:slave:stop`

Stop the config repo watcher.

```bash
protocol config:slave:stop
```

---

## Docker

Managing your containers.

### `protocol docker:compose`

Start your Docker containers. In encrypted mode, decrypts secrets and injects them.

```bash
protocol docker:compose
```

### `protocol docker:compose:rebuild`

Rebuild and restart containers. Use this after changing your Dockerfile or docker-compose.yml.

```bash
protocol docker:compose:rebuild
```

### `protocol docker:compose:down`

Stop and remove containers.

```bash
protocol docker:compose:down
```

### `protocol docker:exec`

Run a command inside your container. Opens a bash shell if you don't specify a command.

```bash
protocol docker:exec                           # opens bash
protocol docker:exec "php artisan migrate"     # run a specific command
```

### `protocol docker:logs`

Follow your container's logs.

```bash
protocol docker:logs
```

### `protocol docker:build`

Build the Docker image from a local Dockerfile source.

```bash
protocol docker:build
```

### `protocol docker:pull`

Pull the Docker image from the registry, or build it if a local Dockerfile is configured.

```bash
protocol docker:pull
```

### `protocol docker:push`

Push the Docker image to the remote registry.

```bash
protocol docker:push
```

### `protocol composer:install`

Run `composer install` inside the Docker container.

```bash
protocol composer:install
```

---

## Security & Readiness

Commands for auditing your codebase and verifying SOC 2 readiness.

### `protocol security:audit`

Run a security scan against your codebase and server. Checks for malicious code patterns, file permission issues, dependency vulnerabilities, suspicious processes, Docker misconfigurations, and unauthorized file changes.

```bash
protocol security:audit
```

Results are displayed in a table with PASS/WARN/FAIL for each check. This runs automatically during `protocol start`, but you can run it anytime on its own.

### `protocol soc2:check`

Validate your setup against SOC 2 Type II requirements. Checks that secrets are encrypted, audit logging is active, you're using release-based deployment, git integrity is maintained, reboot recovery is configured, and key permissions are correct.

```bash
protocol soc2:check
```

Same table format as the security audit. Also runs automatically during `protocol start`.

### `protocol security:trojansearch`

Deep scan for trojan patterns in PHP files. Looks for obfuscated code, backdoors, and known malicious patterns like `eval(base64_decode(...))`.

```bash
protocol security:trojansearch
```

### `protocol security:changedfiles`

List files that have been modified recently. Useful for spotting unauthorized changes on production nodes.

```bash
protocol security:changedfiles
protocol security:changedfiles --days=7    # look back 7 days
```

### `protocol incident:status`

Live incident dashboard. Shows all detected issues, container health, logged-in users, changed files, recently modified files, and recently added files. Refreshes every 5 seconds. Use this as your first command when Protocol alerts you to an incident.

```bash
protocol incident:status
protocol incident:status --once          # run once and exit
protocol incident:status --interval=10   # refresh every 10 seconds
```

When Protocol detects a P1 or P2 incident, every command will show an alert banner directing you to run `protocol incident:status`.

### `protocol incident:report`

Create a full incident report. Gathers all available system state — deployment logs, security audit results, SOC 2 check results, container status, process list, network connections — and compiles a structured report. Opens a GitHub issue and sends notifications to configured webhooks.

```bash
# Severity auto-detected from system state:
protocol incident:report "Unauthorized deploy detected at 3am"

# Override severity (1-4 or P1-P4):
protocol incident:report 1 "SIEM alert: file integrity change"
protocol incident:report P2 "Degraded service on node-3"
protocol incident:report 3 "Dependency CVE discovered" --no-issue
```

Severity levels:
- **P1** — Security audit failures or multiple containers down
- **P2** — SOC 2 check failures or single container down
- **P3** — Warnings from audits or checks
- **P4** — Informational, no failures detected

The report is saved to `~/.protocol/.node/incidents/`, a forensic snapshot is automatically captured, a GitHub issue is created, everything is logged to the audit trail, and notifications are sent to all configured webhook URLs in `protocol.json`.

### `protocol incident:snapshot`

Capture a forensic snapshot of the entire system state. Run this **immediately** during triage — before any containment or remediation. It preserves everything needed for forensic analysis.

```bash
protocol incident:snapshot
```

Captures: audit logs, running processes, network connections, Docker container state and logs, git history and diffs, system info, crontab, SIEM status, auth logs, and recently modified files. All saved to `~/.protocol/.node/incidents/snapshot-YYYY-MM-DD-HHMMSS/` with 0700 permissions.

### `protocol siem:install`

Install and configure the Wazuh SIEM agent for centralized security monitoring. Sets up file integrity monitoring for `~/.protocol/.node/` and forwards audit logs to your SIEM.

```bash
protocol siem:install --manager=wazuh.example.com
protocol siem:install --manager=10.0.0.5 --password=secret --agent-name=prod-1
protocol siem:install --uninstall
```

### `protocol siem:status`

Check the health of the Wazuh SIEM agent on this node.

```bash
protocol siem:status
```

---

## Monitoring

Real-time dashboards for debugging and breach detection.

### `protocol top`

Real-time system command center. Shows processes, network connections, Docker containers, and security status in a continuously updating display.

```bash
protocol top
protocol top --interval=10            # refresh every 10 seconds
protocol top --once                   # run once and exit
```

### `protocol top:shadow`

Visual dashboard of all Docker containers and shadow deployments across release directories.

```bash
protocol top:shadow
protocol top:shadow --interval=10
protocol top:shadow --once
```

---

## Git & Repository

Low-level git operations that Protocol wraps for convenience.

### `protocol git:pull`

Pull from the remote and update the local repo.

```bash
protocol git:pull
```

### `protocol git:clean`

If your `.git` folder is bloating, this runs garbage collection and pruning to reclaim space.

```bash
protocol git:clean
```

### `protocol git:slave`

Start the branch-mode continuous deployment watcher. Polls the remote for changes and pulls automatically.

```bash
protocol git:slave
protocol git:slave --increment=30     # poll every 30 seconds
protocol git:slave --no-daemon        # run in foreground
```

Used internally by `protocol start` in branch mode. You rarely need to run this directly.

### `protocol git:slave:stop`

Stop the branch-mode watcher.

```bash
protocol git:slave:stop
```

---

## System

Housekeeping and setup commands.

| Command | What it does |
|---|---|
| `protocol self:update` | Update Protocol to the latest release |
| `protocol self:update --nightly` | Update to the latest commit (bleeding edge) |
| `protocol self:global` | Install Protocol as a global command (symlink to `/usr/local/bin`) |
| `protocol cron:add` | Add a `@reboot` crontab entry so Protocol restarts after reboots |
| `protocol cron:remove` | Remove the crontab entry |
| `protocol key:generate` | Generate an SSH deploy key for pulling from private repos |
| `protocol nginx:logs` | Tail nginx and PHP-FPM logs from inside the container |
| `protocol migrate` | Interactive wizard to convert from branch-based to release-based deployment |

---

## Quick Reference

| What you want to do | Command |
|---|---|
| Set up a new project | `protocol init` |
| Start everything | `protocol start` |
| Stop everything | `protocol stop` |
| Check what's running | `protocol status` |
| Create a release | `protocol release:create` |
| Deploy to all nodes | `protocol deploy:push 1.2.0` |
| Roll back | `protocol deploy:rollback` |
| Zero-downtime deploy | `protocol shadow:build v1.2.0` then `protocol shadow:start` |
| Shadow rollback (instant) | `protocol shadow:rollback` |
| Set up configs & secrets | `protocol config:init` |
| Run a command in Docker | `protocol docker:exec "your command"` |
| View your encryption key | `protocol secrets:key` |
| Run a security scan | `protocol security:audit` |
| Check SOC 2 readiness | `protocol soc2:check` |
| Install SIEM agent | `protocol siem:install --manager=host` |
| View incident dashboard | `protocol incident:status` |
| Report an incident | `protocol incident:report 1 "msg"` |
| Capture forensic snapshot | `protocol incident:snapshot` |
| Real-time monitoring | `protocol top` |
| Update Protocol itself | `protocol self:update` |
