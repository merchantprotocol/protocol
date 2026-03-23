# Secrets Management

Your `.env` files hold the keys to your kingdom — database passwords, API tokens, payment gateway credentials. Losing them means downtime. Leaking them means a breach. And yet most teams pass these files around in Slack messages, shared drives, or sticky notes.

Protocol fixes this. Your secrets are now version-controlled, encrypted, and automatically deployed — just like your code.

## The Problem

Every project has files that can't go in git: `.env`, database configs, API keys. So teams do one of these:

- Copy them by hand from machine to machine
- Store them in a shared Google Doc
- DM them on Slack
- Forget where they are entirely

When something breaks at 2am and you need to rebuild a server, those files are gone. Or worse — they're sitting in plain text on a server that just got compromised.

## How Protocol Solves It

Protocol gives you a **config repository** — a separate git repo just for your environment files. Each branch is an environment: `localhost`, `staging`, `production`.

The secret sauce: before those files go into git, Protocol **encrypts** them with AES-256-GCM. The encrypted versions (`.env.enc`) travel through git. The encryption key stays on your machines and never touches git.

When Protocol starts your app, it automatically **decrypts** those files and links them into your project. Your app doesn't know the difference — it just reads `.env` like it always has.

```
Your Machine                    Git (safe to push)              Production Server
─────────────                   ──────────────────              ─────────────────
.env (plaintext)  ──encrypt──▶  .env.enc (gibberish)  ──pull──▶  .env.enc
                                                                    │
                                                                 decrypt
                                                                    │
                                                                    ▼
                                                                 .env (plaintext)
```

## Getting Started

### Step 1: Set Up Your Config Repository

Run the config wizard:

```bash
protocol config:init
```

If you haven't set one up before, it walks you through creating a config repo, choosing your environment name, and connecting a git remote.

If you've already got a config repo, it shows you a menu:

```
● Encrypt secrets          ← recommended
○ Decrypt secrets
○ Re-initialize config repo (wipes existing)
○ Cancel
```

Protocol looks at your files and recommends the right action. If you have unencrypted `.env` files, it recommends encrypting. If you have encrypted files but no key, it recommends decrypting (and asks for the key).

### Step 2: Generate an Encryption Key

During `config:init`, Protocol offers to generate a key. Say yes.

```
✓ Key generated

  GitHub repo detected: yourorg/yourproject

  Push encryption key as a GitHub secret? [Y/n]
```

If you have the GitHub CLI (`gh`) installed, Protocol can push the key as a **GitHub secret** called `PROTOCOL_ENCRYPTION_KEY`. This is the easiest way to get the key to your CI/CD pipeline.

### Step 3: Encrypt Your Files

Protocol finds any unencrypted `.env` files in your config repo and offers to encrypt them:

```
  Found: .env, .env.production

  Encrypt them now? [Y/n]

  ✓ .env → .env.enc
  ✓ .env.production → .env.production.enc
```

The plaintext files are deleted and gitignored. Only the encrypted versions remain. You can safely push your config repo now.

### Step 4: Deploy to Production

On your production server, you need two things: the Protocol tool and the encryption key.

**Option A: Push the key via SCP** (simplest for bare-metal servers)

From your dev machine:
```bash
protocol secrets:key --scp=deploy@production-server
```

This copies your key file directly to `~/.protocol/.node/key` on the remote server.

**Option B: Use GitHub Actions** (for CI/CD pipelines)

In your deploy workflow, pass the secret as an environment variable:

```yaml
- name: Setup encryption key
  run: protocol secrets:setup
  env:
    PROTOCOL_ENCRYPTION_KEY: ${{ secrets.PROTOCOL_ENCRYPTION_KEY }}
```

**Option C: Copy the key manually**

View your key:
```bash
protocol secrets:key
```

Then on the target machine:
```bash
protocol secrets:setup "your-64-character-hex-key"
```

### Step 5: Start Your App

On any node that has the key:

```bash
protocol start
```

During startup, `config:link` sees the `.enc` files, decrypts them, drops the plaintext into place (with `0600` permissions so only your app can read them), and symlinks them into your project. Your app boots up with all its secrets intact.

## Day-to-Day Usage

### View your key

```bash
protocol secrets:key
```

Shows the key and all the ways to transfer it.

### View just the raw key (for scripting)

```bash
protocol secrets:key --raw
```

### Push the key to GitHub

```bash
protocol secrets:key --push
```

### Copy the key to another server

```bash
protocol secrets:key --scp=user@host
```

### Add a new secret file

1. Put the file in your config repo (e.g., `newtest-config/.env.special`)
2. Run `protocol config:init`, choose **Encrypt secrets**
3. Push your config repo
4. On other nodes, `protocol start` picks it up automatically

### Re-encrypt after changing a secret

1. Decrypt: `protocol config:init` → **Decrypt secrets**
2. Edit the plaintext file
3. Encrypt: `protocol config:init` → **Encrypt secrets**
4. Push your config repo

## How It Works Under the Hood

**Encryption:** AES-256-GCM with a random 12-byte nonce per file. The output is `base64(nonce + auth_tag + ciphertext)`. This is the same standard used by banks and government systems.

**Key storage:** A 256-bit key stored as a 64-character hex string at `~/.protocol/.node/key` with `0600` permissions (owner-only read/write).

**Decryption during startup:** When `config:link` encounters a `.enc` file, it decrypts to plaintext in the config repo directory, sets `0600` permissions, adds the plaintext filename to `.gitignore`, and symlinks the plaintext file into your project.

**Tracking:** Decrypted files are tracked in `.protocol-secrets.json` in the config repo and NodeConfig (`~/.protocol/.node/nodes/<project>.json`), including a key fingerprint so Protocol knows which key was used.

## Important Things to Know

**The key is everything.** If you lose the key, you cannot decrypt your secrets. There is no recovery. Keep it in GitHub secrets, on your servers, and ideally in a password manager as a backup.

**GitHub secrets are write-only.** You can push a key to GitHub, but you can't pull it back out. GitHub secrets are only accessible inside GitHub Actions workflows as environment variables. This is a security feature, not a bug.

**Each project has one key.** All environments (localhost, staging, production) use the same encryption key. The secrets themselves differ per environment because each environment is a different branch in the config repo.

**Plaintext files are gitignored.** After encryption, the plaintext files are deleted and added to `.gitignore` in the config repo. They only exist on machines that have the key and have run `protocol start`.

## Quick Reference

| Command | What it does |
|---|---|
| `protocol config:init` | Wizard: create config repo, encrypt, or decrypt |
| `protocol secrets:setup` | Generate a new key or store one from another node |
| `protocol secrets:setup <key>` | Store a specific key on this node |
| `protocol secrets:key` | View the key and transfer options |
| `protocol secrets:key --push` | Push key to GitHub as a secret |
| `protocol secrets:key --scp=user@host` | Copy key file to a remote server |
| `protocol secrets:key --raw` | Output just the key (for scripting) |
| `protocol secrets:encrypt` | Encrypt files in the config repo |
| `protocol secrets:decrypt` | Decrypt files in the config repo |
| `protocol start` | Auto-decrypts and links everything |
