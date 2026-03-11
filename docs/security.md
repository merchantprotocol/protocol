# Security & SOC2 Compliance

If you're running Protocol in a production environment — especially one that needs to pass a SOC2 audit — this page covers what Protocol gives you, what you need to add, and how to lock everything down.

## What Protocol Already Handles

Protocol was built with security in mind. Here's what you get out of the box:

### Encrypted Secrets

Your `.env` files are encrypted with **AES-256-GCM** before they touch git. The same encryption standard used by banks, governments, and every serious security system. The encryption key stays on your machines — only gibberish travels through repositories.

### Audit Trail

Every deployment writes a timestamped log entry: what version was running before, what version is running now, when it happened, and whether it succeeded. View it with `protocol deploy:log`.

### Immutable Deployments

Release mode deploys specific git tags. Tags are immutable — `v1.2.0` always means the same code. You can't accidentally deploy "whatever's on master." And because tags don't change, rolling back means deploying a known-good version, not reverting commits.

### Environment Isolation

Each environment (production, staging, dev) has its own branch in the config repo with its own secrets. A developer's laptop never touches production credentials. The environments share nothing except the encryption key.

### SSH Key Management

`protocol key:generate` creates ed25519 SSH keys for deployment, so no passwords are used for git access.

---

## SOC2 Type II — What Auditors Want to See

SOC2 Type II evaluates your controls over time. Here's how Protocol maps to the things auditors care about:

### Access Controls (CC6)

**What you can show them:**

- Secrets are encrypted at rest in git — nobody can read `.env` files without the key
- Each environment is isolated in its own config branch
- SSH keys are used for all git operations — no shared passwords
- Encryption keys have strict file permissions (`0600`) and live outside any repository

**What you should add:**

- Use GitHub branch protection to restrict who can push to the `production` config branch
- Require pull request reviews before config changes reach production
- Use GitHub's CODEOWNERS file to require specific approvers for sensitive files

### Change Management (CC7/CC8)

**What you can show them:**

- All code changes flow through git — every change has an author, timestamp, and commit message
- All config changes flow through git — same audit trail
- Deployments use explicit version tags — creating a release is a deliberate approval decision
- The audit log tracks every deployment with before/after versions

**What you should add:**

- Enable required status checks on your main branch (CI must pass before merge)
- Use GitHub's required reviewers feature on production branches
- Set up deployment notifications (Slack, email, PagerDuty) so the team knows when production changes

### Availability (A1)

**What you can show them:**

- `protocol cron:add` ensures Protocol restarts after server reboots
- `protocol status` gives a health overview of all running processes
- Docker Compose handles container restart policies

**What you should add:**

- External monitoring (Uptime Robot, Datadog, etc.) that alerts when nodes go down
- Health check endpoints in your application that monitoring services can ping
- Load balancing across multiple nodes so one node going down doesn't take everything offline

### Confidentiality (C1)

**What you can show them:**

- Secrets are encrypted with AES-256-GCM before being stored anywhere
- Decryption keys have strict filesystem permissions and never enter git
- Plaintext secrets are gitignored and deleted after encryption
- Each machine decrypts independently — secrets don't travel in plaintext over the network

**What you should add:**

- Document your key rotation schedule (how often you change the encryption key)
- Keep a backup of your encryption key in a password manager or vault
- Restrict which team members have access to the encryption key

---

## The Hardening Checklist

Before running Protocol in a SOC2-audited environment, go through this list:

### Must Do

- [ ] Set `deployment.strategy` to `"release"` in `protocol.json` — branch mode has no audit trail
- [ ] Set `deployment.secrets` to `"encrypted"` — never store plaintext secrets in git
- [ ] Enable GitHub branch protection on your main branch and production config branch
- [ ] Require pull request reviews before merging to production
- [ ] Set up `protocol cron:add` on every node for reboot recovery
- [ ] Keep your encryption key in a password manager as a backup
- [ ] Restrict `~/.protocol/key` permissions to `0600` (Protocol does this by default)

### Should Do

- [ ] Set up deployment notifications (Slack webhook, email) when releases are pushed
- [ ] Set up external health monitoring for each production node
- [ ] Document your deployment process in a runbook
- [ ] Document your key rotation procedure
- [ ] Forward audit logs (`~/.protocol/deployments.log`) to a centralized logging system (CloudWatch, Datadog, Splunk)
- [ ] Run `protocol status` checks as part of your monitoring

### Nice to Have

- [ ] Use `security:trojansearch` in your CI pipeline to scan for suspicious code patterns
- [ ] Set up `security:changedfiles` alerts to review files modified in the last 15 days
- [ ] Use GitHub's CODEOWNERS feature for sensitive files

---

## How Secrets Stay Safe

Here's the full journey of a secret, from your keyboard to a running container:

```
Your Machine                    Git                         Production Node
─────────────                   ───                         ───────────────
1. You edit .env
2. protocol config:init
   → Encrypt secrets
3. .env → AES-256-GCM
   → .env.enc                  4. .env.enc committed
   → plaintext deleted             and pushed
   → .gitignore updated                                    5. protocol start
                                                              → pulls config repo
                                                              → reads ~/.protocol/key
                                                              → decrypts .env.enc
                                                              → .env exists in memory
                                                              → passed to Docker
                                                              → containers run with secrets
                                                              → audit log written
```

At no point does a plaintext secret travel through git. At no point is a plaintext secret stored in a shared location. The encrypted file is useless without the key, and the key never leaves the machines it's installed on.

### Encryption Details

- **Algorithm:** AES-256-GCM (authenticated encryption — tamper-proof)
- **Key size:** 256-bit (64-character hex string)
- **Nonce:** Random 12 bytes per file (prevents identical plaintext from producing identical ciphertext)
- **Output format:** `base64(nonce + auth_tag + ciphertext)`
- **Implementation:** PHP's built-in `openssl_encrypt()` — no external dependencies

---

## The Deployment Audit Log

Every deployment action writes to `~/.protocol/deployments.log`:

```
2024-01-15T10:30:01Z deploy repo=/opt/myapp from=v1.1.0 to=v1.2.0 status=success
2024-01-15T10:30:05Z config repo=/opt/myapp env=production files=3 status=success
2024-01-15T10:30:08Z docker repo=/opt/myapp image=registry/app:latest action=rebuild status=success
2024-03-01T14:22:00Z rollback repo=/opt/myapp from=v1.3.0 to=v1.2.0 status=success
```

**What gets logged:**
- Every deployment (version transitions)
- Every rollback
- Config changes
- Docker rebuilds

**What you should do with it:**
- Keep logs for at least 12 months (SOC2 audits typically cover 6-12 months)
- Forward to a centralized, tamper-evident logging system
- Set up alerts on `status=failure` entries

View your logs anytime:

```bash
protocol deploy:log
```

---

## Key Rotation

When you need to change your encryption key (and you should, periodically):

```bash
# 1. Decrypt everything with the old key
protocol config:init    # → choose "Decrypt secrets"

# 2. Generate a new key
protocol secrets:setup

# 3. Re-encrypt with the new key
protocol config:init    # → choose "Encrypt secrets"

# 4. Push encrypted files
protocol config:save

# 5. Distribute the new key to all nodes
protocol secrets:key --scp=deploy@prod-server-1
protocol secrets:key --scp=deploy@prod-server-2

# 6. Restart nodes to pick up the new key
# (on each node)
protocol stop && protocol start
```

---

## Quick Reference

| Question | Answer |
|---|---|
| What encryption does Protocol use? | AES-256-GCM (same as banks and governments) |
| Where is the key stored? | `~/.protocol/key` on each machine, with `0600` permissions |
| Can I recover secrets without the key? | No. Keep a backup in a password manager. |
| Are secrets stored in git? | Only the encrypted versions. Plaintext is gitignored and deleted. |
| Does each environment use a different key? | No. Same key, different secrets (on different config branches). |
| How do I transfer the key to production? | `protocol secrets:key --scp=user@host` or `protocol secrets:key --push` (GitHub) |
| Where is the audit log? | `~/.protocol/deployments.log` — view with `protocol deploy:log` |
