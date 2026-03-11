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

Pulls your code, links your configs, decrypts your secrets, boots Docker, starts the watchers. On a fresh production node, this is the last command you run during setup.

### `protocol stop`

Stop everything. Kills watchers, unlinks configs, stops Docker containers, removes the reboot cron entry.

```bash
protocol stop
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

---

## Secrets

Managing your encryption key and encrypted files.

### `protocol secrets:setup`

Generate a new encryption key or store one from another node.

```bash
protocol secrets:setup                          # generate new key
protocol secrets:setup "your-64-char-hex-key"   # store existing key
```

The key is saved at `~/.protocol/key` with strict permissions. Run this once per machine.

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

---

## System

Housekeeping and setup commands.

| Command | What it does |
|---|---|
| `protocol self:update` | Update Protocol to the latest release |
| `protocol self:update --nightly` | Update to the latest commit (bleeding edge) |
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
| Set up configs & secrets | `protocol config:init` |
| Run a command in Docker | `protocol docker:exec "your command"` |
| View your encryption key | `protocol secrets:key` |
| Update Protocol itself | `protocol self:update` |
