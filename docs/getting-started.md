# Getting Started with Protocol

Protocol manages your entire deployment pipeline — from your local machine to production. You set it up once, and from then on, pushing code to GitHub is all it takes to deploy.

This guide walks you through everything, start to finish.

## What You're Building

By the end of this guide, you'll have:

- A project that runs in Docker with one command
- A config repo that stores your `.env` files safely in git (encrypted)
- A production server that auto-deploys when you push code
- Secrets that travel encrypted and decrypt themselves on arrival

Think of Protocol as the glue between your code, your Docker containers, your configs, and your servers.

## Install Protocol

On any Mac or Linux machine:

```bash
sudo curl -L "https://raw.githubusercontent.com/merchantprotocol/protocol/master/bin/install" | bash
```

Verify it worked:

```bash
protocol -v
```

## Set Your Environment Name

Every machine gets a name. This tells Protocol which config files belong to this machine.

```bash
protocol config:env localhost-yourname
```

Use something descriptive. Common patterns:

| Machine | Environment name |
|---|---|
| Your laptop | `localhost-sarah` |
| Staging server | `staging` |
| Production server | `production` |

This name becomes a branch in your config repo. More on that soon.

## Initialize Your Project

Navigate to your project and run the setup wizard:

```bash
cd /path/to/your/project
protocol init
```

Protocol looks at your project and figures out what you need. You'll see a menu with colored dots — use arrow keys to pick, Enter to confirm:

```
  ● Start a new project                    recommended
  ○ Connect an existing repository
  ○ Update an existing Protocol project
```

### What the Wizard Does

**Step 1 — Project Type.** Pick your Docker base image. Protocol ships with PHP 8.1, 8.2, and 8.2+FFmpeg images. Or bring your own.

**Step 2 — Deployment Strategy.** Two choices:

- **Release-based** (recommended) — You create tagged releases. Each server checks out a specific version. You can roll back instantly.
- **Branch-based** — Servers follow the tip of a branch. Simpler, but no rollback safety net.

**Step 3 — Secrets.** Optionally generate an encryption key for your `.env` files. You can do this now or later.

**Step 4 — Config Repository.** Optionally create a config repo to store your environment files. Again, now or later.

When it's done, you'll have:

- `protocol.json` — your project's Protocol settings
- `docker-compose.yml` — ready to run your containers
- Override directories (`nginx.d/`, `cron.d/`, `supervisor.d/`) — for custom server configs

All committed to git automatically.

## Start Everything Locally

```bash
protocol start
```

That's it. Protocol will:

1. Build and start your Docker containers
2. Link your config files (if you set up a config repo)
3. Decrypt any encrypted secrets (if you set up encryption)
4. Show you the status when it's done

Check what's running:

```bash
protocol status
```

Stop everything:

```bash
protocol stop
```

## Set Up Your Config Repository

Your project probably has files that shouldn't live in the main repo — `.env` files, nginx configs, cron schedules. Protocol stores these in a **separate git repo** next to your project.

```
your-project/           ← your code
your-project-config/    ← your configs (separate git repo)
```

Each branch in the config repo is an environment. Your laptop uses the `localhost-sarah` branch. Production uses the `production` branch. Same repo, different configs.

Run the config wizard:

```bash
protocol config:init
```

### First Time

The wizard walks you through:

1. **Environment** — Confirms your environment name
2. **Repository** — Creates the config repo (or clones one if you have a remote URL in `protocol.json`)
3. **Secrets & Encryption** — Generates an encryption key and encrypts any `.env` files
4. **Remote** — Optionally connects a git remote so other nodes can pull this config

### Already Have a Config Repo

If you've done this before, you'll see a menu instead:

```
  ● Encrypt secrets           ← recommended
  ○ Decrypt secrets
  ○ Re-initialize config repo (wipes existing)
  ○ Cancel
```

Protocol looks at your files and recommends the right action. Unencrypted `.env` files? It recommends encrypting. Encrypted files but no key on this machine? It recommends decrypting.

### Adding Config Files

Drop files into the config repo and they'll be symlinked into your project when you run `protocol start`:

```bash
# Example: add an .env file
cp .env ../your-project-config/.env

# Encrypt it
protocol config:init   # → choose Encrypt secrets

# Push to remote
cd ../your-project-config
git push
```

For the full secrets story — encryption, key distribution, GitHub Actions — see [Secrets Management](secrets.md).

## Re-Running Setup

Already initialized? No problem. Run `protocol init` again and it detects your project:

```
  ○ Start a new project
  ○ Connect an existing repository
  ● Update an existing Protocol project     recommended
```

Choose **Update** and you get a menu:

```
  ● Fix / Migrate — regenerate configs, fix paths, update structure
  ○ Re-run full project setup from scratch
  ○ Change deployment strategy
  ○ Set up encrypted secrets
  ○ Initialize configuration repository
  ○ Exit without changes
```

**Fix / Migrate** is the most common choice. It checks your `protocol.json` version against the current schema, runs any needed migrations, fixes broken paths, and ensures your scaffold directories exist. Safe to run anytime.

## Deploy to Production

### First-Time Server Setup

On your production server:

```bash
# 1. Install protocol
sudo curl -L "https://raw.githubusercontent.com/merchantprotocol/protocol/master/bin/install" | bash

# 2. Set the environment
protocol config:env production

# 3. Set up the encryption key (so it can decrypt your secrets)
protocol secrets:setup "your-64-character-hex-key"

# 4. Clone your app and start
git clone git@github.com:yourorg/yourapp.git /opt/yourapp
cd /opt/yourapp
protocol start
```

Protocol handles the rest — clones the config repo, checks out the `production` branch, decrypts your secrets, starts Docker, and begins watching for updates.

### Getting the Key to Production

From your dev machine, pick whichever works:

```bash
# SCP the key file directly
protocol secrets:key --scp=deploy@production-server

# Push to GitHub (for CI/CD pipelines)
protocol secrets:key --push

# Or just view it and paste
protocol secrets:key
```

See [Secrets Management](secrets.md) for the full details.

### Surviving Reboots

Add Protocol to crontab so it restarts after a server reboot:

```bash
protocol cron:add
```

### Updating Protocol Itself

```bash
protocol self:update
```

This checks out the latest release. If you want the bleeding edge:

```bash
protocol self:update --nightly
```

## What's Next

| Want to... | Run this |
|---|---|
| Create a release | `protocol release:create` |
| Deploy a specific version | `protocol deploy:push v1.2.3` |
| Roll back a bad deploy | `protocol deploy:rollback` |
| Check what's running | `protocol status` |
| Run a command inside Docker | `protocol docker:exec "php artisan migrate"` |
| View nginx logs | `protocol nginx:logs` |
| See all commands | `protocol list` |

## The Big Picture

Here's how all the pieces fit together:

```
                          GitHub
                      ┌─────────────────────┐
                      │  App Repo            │
                      │  Config Repo         │
                      │  Secrets (encrypted) │
                      │  Release Tags        │
                      └──────┬──────────────┘
                             │
              ┌──────────────┼──────────────┐
              │              │              │
              ▼              ▼              ▼
         ┌─────────┐   ┌─────────┐   ┌─────────┐
         │ Dev      │   │ Staging │   │ Prod    │
         │          │   │         │   │         │
         │ protocol │   │ protocol│   │ protocol│
         │ start    │   │ start   │   │ start   │
         │          │   │         │   │         │
         │ localhost│   │ staging │   │ prod    │
         │ branch   │   │ branch  │   │ branch  │
         └─────────┘   └─────────┘   └─────────┘
```

Each node runs `protocol start`, pulls its own config branch, decrypts with the shared key, and serves the app. Push code to GitHub, and every node picks it up automatically.
