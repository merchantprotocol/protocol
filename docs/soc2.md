# SOC 2 Ready

Protocol is built to get you SOC 2 ready. This document explains every control Protocol provides, what you still need to configure on your end, and how each piece maps to the Trust Service Criteria that auditors evaluate.

SOC 2 Type II proves your systems handle customer data responsibly. "Type II" means an auditor watches your controls operate over time (usually 6-12 months), not just checks a snapshot. Protocol gives you the tooling — encryption, audit trails, readiness checks, SIEM integration — but achieving full readiness also requires organizational policies, team training, and a formal audit. This document covers the technical side.

## What Protocol Gives You Out of the Box

Before any configuration, Protocol already handles:

| Capability | How it works |
|---|---|
| **Encrypted secrets** | `.env` files encrypted with AES-256-GCM before they touch git |
| **Audit trail** | Every deployment, rollback, and config change logged with timestamps, versions, and user identity. PR approval chain (author, approvers, merger) captured at deploy time |
| **Audit log rotation** | Automatic log rotation at 5 MB with gzip compression, 12-month retention (52 weekly archives) |
| **Immutable deployments** | Release mode deploys specific git tags — `v1.2.0` always means the same code |
| **Environment isolation** | Each environment has its own config branch with its own secrets |
| **Reboot recovery** | `@reboot` crontab entry restarts Protocol after server reboots |
| **Automated readiness checks** | `protocol soc2:check` validates your setup against SOC 2 requirements on every startup |
| **Security scanning** | `protocol security:audit` scans for malicious code, suspicious processes, and permission issues |
| **Input sanitization** | All shell commands use `escapeshellarg()` to prevent command injection |
| **Failed deploy logging** | Both successful and failed deployment attempts are recorded in the audit trail |
| **Key rotation tracking** | SOC 2 check warns when your encryption key is older than 90 days |
| **Sensitive file protection** | `.env`, `*.key`, and `*.pem` patterns in `.gitignore` prevent accidental commits |
| **Code review enforcement** | `.github/CODEOWNERS` requires security team review for encryption, audit, and deployment files |
| **CI/CD pipeline** | PHPUnit tests, syntax checking, dependency auditing, and secret scanning run on every push |
| **SIEM integration** | `protocol siem:install` configures Wazuh agent with audit log forwarding and file integrity monitoring |
| **Centralized logging** | Wazuh SIEM provides centralized, tamper-evident log aggregation across all nodes. Audit logs are forwarded off-node automatically when SIEM is configured |
| **Change approval chain** | `deploy:push` captures PR number, author, approvers, and merger from GitHub and writes APPROVAL entries to the audit log |
| **Docker health checks** | Container health checks with automatic restart ensure availability monitoring |
| **Branch protection** | GitHub branch protection enforces required CI status checks and PR reviews before merge to master |
| **Webhook notifications** | Configurable webhooks fire on deploy, security audit failures, SOC 2 check failures, and incidents. Supports Slack, PagerDuty, and any webhook-compatible service |
| **Incident reporting** | `protocol incident:report` gathers system state, creates a GitHub issue, sends to webhooks, and logs to audit trail |
| **Forensic snapshots** | `protocol incident:snapshot` captures full system evidence (logs, processes, network, containers, git state) for incident response |

## Trust Service Criteria Mapping

SOC 2 Type II evaluates five categories. Here's how Protocol maps to each one.

### CC6 — Logical and Physical Access Controls

This is about making sure only the right people and systems can access sensitive data.

**What Protocol does:**

- **Secrets are encrypted at rest.** Your `.env` files are encrypted with AES-256-GCM using a 256-bit key. The encrypted files live in git; plaintext never touches a shared repository. Each encryption uses a random 12-byte nonce, so identical plaintext produces different ciphertext every time.

- **Keys have strict filesystem permissions.** The encryption key at `~/.protocol/.node/key` is created with `0600` permissions (owner-read/write only). The `~/.protocol/.node/` directory is `0700`. Protocol's SOC 2 check validates these on every startup and fails if they've drifted.

- **Key rotation is tracked.** Protocol checks the age of your encryption key on every startup. If it's older than 90 days, you get a warning. The [Key Rotation Procedure](key-rotation.md) documents the full process.

- **Environment isolation.** Each environment (production, staging, localhost) is a separate branch in the config repo. A developer's laptop never touches production credentials. The environments share nothing except the encryption key.

- **SSH keys for git access.** `protocol key:generate` creates ed25519 SSH keys for deployment — no passwords for git operations.

- **Input sanitization.** Every variable interpolated into a shell command uses `escapeshellarg()` or PHP file I/O instead of shell string interpolation. This prevents command injection even if config values contain special characters.

**What you configure:**

- Enable GitHub branch protection on your `production` config branch
- Require pull request reviews before config changes reach production
- Restrict which team members have access to the encryption key
- Use the CODEOWNERS file (already set up at `.github/CODEOWNERS`) to require security team approval for sensitive file changes

### CC7 — System Operations and Monitoring

This is about detecting and responding to issues — knowing what's happening and acting on it.

**What Protocol does:**

- **Security audit on every startup.** `protocol security:audit` runs six checks automatically during `protocol start`:

  | Check | What it looks for |
  |---|---|
  | Malicious code | `eval(`, `base64_decode(`, `shell_exec(` and other backdoor patterns in PHP files |
  | File permissions | Key and directory permissions, world-writable config files |
  | Dependencies | `composer audit` for known vulnerabilities |
  | Suspicious processes | Cryptominers (`xmrig`, `kinsing`), reverse shells (`nc`, `ncat`, `socat`) |
  | Docker security | `privileged: true`, `user: root`, dangerous capabilities in docker-compose.yml |
  | Recent changes | Files modified in the last 24 hours that aren't tracked by git |

- **SOC 2 readiness check on every startup.** `protocol soc2:check` validates seven controls:

  | Check | What it verifies |
  |---|---|
  | Encrypted secrets | `deployment.secrets` is `"encrypted"` and key exists |
  | Audit logging | Log file exists and isn't world-readable |
  | Deploy strategy | Strategy is `"release"` (immutable tags, not mutable branches) |
  | Git integrity | Remote is configured, HEAD is on a remote branch |
  | Reboot recovery | `@reboot` crontab entry exists |
  | Key permissions | Key is `0600`, directory is `0700` |
  | Key rotation | Key is less than 90 days old |

- **Centralized logging via SIEM.** `protocol siem:install` installs and configures the Wazuh agent, sets up file integrity monitoring for `~/.protocol/.node/`, and forwards Protocol's audit log to your centralized SIEM. This ensures logs are preserved off-node in a tamper-evident system even if a node is compromised. `protocol siem:status` checks agent health. The `protocol status` dashboard shows SIEM status alongside other services.

- **Rich status dashboard.** `protocol status` shows a real-time view of all services, Docker containers, configuration state, and security status with colored indicators and issue summaries.

**What you configure:**

- Install Wazuh SIEM: `protocol siem:install --manager=your-wazuh-server`
- Set up external monitoring (Uptime Robot, Datadog) that alerts when nodes go down
- Add health check endpoints to your application
- Set up deployment notifications (Slack, email, PagerDuty)

### CC8 — Change Management

This is about controlling how changes move from development to production.

**What Protocol does:**

- **All changes flow through git.** Code, configs, and secrets all live in git repositories. Every change has an author, timestamp, and commit message. Nothing reaches production without being committed.

- **Immutable release tags.** Release mode deploys specific git tags. `v1.2.0` always means the same code. You can't accidentally deploy "whatever's on main." Creating a release is a deliberate approval decision.

- **Deployment audit trail.** Every deployment writes a structured log entry to `~/.protocol/.node/deployments.log`:

  ```
  2024-01-15T10:30:01Z DEPLOY repo=/opt/myapp from=v1.1.0 to=v1.2.0 status=success user=deploy scope=global
  2024-03-01T14:22:00Z DEPLOY repo=/opt/myapp from=v1.1.0 to=v1.2.0 status=failure user=deploy scope=global
  ```

  Both successful and failed attempts are logged. Log entries use `escapeshellarg()` to prevent log injection. Logs are automatically rotated at 5 MB and archived with gzip compression, retaining 52 weekly archives (12 months).

- **Change approval chain captured at deploy time.** When `protocol deploy:push` deploys a version, it queries GitHub for all merged PRs associated with that release and writes `APPROVAL` entries to the audit log:

  ```
  2024-01-15T10:30:02Z APPROVAL repo=/opt/myapp version=v1.2.0 pr_number=42 pr_title='Add payment validation' pr_author='dev1' pr_approvers='securitylead,dev2' pr_merged_by='securitylead' pr_merged_at='2024-01-15T09:00:00Z' pr_url='https://github.com/org/repo/pull/42' user='deploy'
  ```

  This gives auditors a complete chain from code change to approval to deployment — all in one log.

- **CI/CD enforcement.** The CI pipeline (`.github/workflows/ci.yml`) runs on every push and pull request:
  - PHP syntax checking across PHP 8.1, 8.2, and 8.3
  - `composer audit` for dependency vulnerabilities
  - PHPUnit test suite (36 tests, 73 assertions)
  - TruffleHog secret scanning (weekly)
  - Static analysis and file permission checks (weekly)

- **CODEOWNERS.** Changes to security-critical files require review from the `@merchantprotocol/security` team:
  - Encryption: `Secrets.php`, `SecretsSetup.php`, `SecretsKey.php`
  - Audit: `AuditLog.php`, `SecurityAudit.php`, `Soc2Check.php`
  - Deployment: `Deploy.php`, `BlueGreen.php`, CI/CD workflows
  - Documentation: security docs, IR runbook, key rotation procedure

**What you configure:**

- Enable required status checks on your main branch (CI must pass before merge)
- Enable required reviewers on production branches
- Set up deployment notifications so the team knows when production changes

### A1 — Availability

This is about keeping systems running and recovering quickly when they go down.

**What Protocol does:**

- **Reboot recovery.** `protocol cron:add` installs a `@reboot` crontab entry. When the server reboots, Protocol starts automatically — pulls the current release, decrypts secrets, starts containers, resumes watchers. The SOC 2 check verifies this is in place.

- **Zero-downtime deployment.** Shadow mode builds new releases in a separate directory while the current version keeps serving traffic. When the new version passes health checks, a port swap happens in under one second. If the swap fails, Protocol automatically rolls back to the previous version.

- **Instant rollback.** In release mode, roll back by changing the pointer: `protocol deploy:rollback`. Every node deploys the previous version. In shadow mode, rollback is a port swap — under one second, because the previous version's Docker image is still cached.

- **Health checks.** Shadow deployments run configurable health checks (HTTP endpoint checks, in-container exec checks) before promoting a new version to production. Retries 3 times with 3-second backoff.

- **Independent nodes.** Each node watches GitHub independently. They don't talk to each other. One node going down doesn't affect the others. Scale up by adding nodes, scale down by stopping them.

**What you configure:**

- External monitoring for each production node
- Load balancing across multiple nodes
- Health check endpoints in your application
- Docker restart policies in your docker-compose.yml

### C1 — Confidentiality

This is about protecting sensitive data from unauthorized access.

**What Protocol does:**

- **AES-256-GCM authenticated encryption.** Secrets are encrypted with the same standard used by banks and governments. The authentication tag ensures tamper detection — if anyone modifies the encrypted file, decryption fails.

- **Encryption key never enters git.** The key lives at `~/.protocol/.node/key` on each machine. It's generated locally or transferred via `protocol secrets:key --scp=user@host`. Secure key distribution options include SCP (direct transfer) and GitHub Secrets (for CI/CD pipelines).

- **Plaintext is gitignored.** `.env`, `*.key`, and `*.pem` are in `.gitignore`. Even if you forget to encrypt, plaintext secrets won't be committed.

- **Decrypted files are protected.** When Protocol decrypts `.env.enc` files during startup, the plaintext files get `0600` permissions. On Linux, `decryptToTempFile()` prefers RAM-backed `/dev/shm` to avoid writing to disk.

- **Separate config repository.** Secrets live in a dedicated git repo, not mixed with application code. Each environment is a branch — production credentials are never in the same branch as development configs.

**What you configure:**

- Document your key rotation schedule and follow it quarterly
- Keep a backup of your encryption key in a password manager or vault
- Forward audit logs to a centralized, tamper-evident logging system (SIEM)
- Restrict which team members have access to production keys

## Automated Readiness Verification

Protocol runs readiness checks automatically. You can also run them on demand:

```bash
# Run all SOC 2 checks
protocol soc2:check

# Run the security audit
protocol security:audit

# View the full status dashboard
protocol status

# View the deployment audit log
protocol deploy:log

# Check SIEM agent health
protocol siem:status
```

The `protocol status` dashboard shows readiness state at a glance:

```
  Security
    SOC 2            all checks passing
    SIEM             connected to 10.0.0.5 log forwarding active
    Encryption       AES-256-GCM key present (0600)
    Audit log        active last entry 2m ago
```

## What Auditors Will Ask For

During a SOC 2 Type II audit, you'll be asked to demonstrate that controls have been operating consistently over the review period. Here's what to have ready:

| Auditor request | Where to find it |
|---|---|
| "Show me your deployment history" | `protocol deploy:log` — timestamped log of every deploy and rollback |
| "How are secrets protected?" | AES-256-GCM encryption, key permissions validated on every startup |
| "Show me access controls on production" | GitHub branch protection, CODEOWNERS, SSH key auth |
| "How do you detect unauthorized changes?" | `protocol security:audit` — scans for malicious code, permission drift, untracked changes |
| "What happens when a server reboots?" | `@reboot` crontab entry, verified by `protocol soc2:check` |
| "How do you roll back a bad deploy?" | `protocol deploy:rollback` (release mode) or `protocol shadow:rollback` (~1 second) |
| "Show me your incident response plan" | [Incident Response Runbook](incident-response.md) — severity levels, phases, playbooks |
| "How often do you rotate keys?" | [Key Rotation Procedure](key-rotation.md) — quarterly, tracked by SOC 2 check |
| "Show me your deployment procedures" | [Deployment SOPs](deployment-sops.md) — 8 standard operating procedures |
| "How are changes reviewed before production?" | CI pipeline (tests, security scan, audit), CODEOWNERS, required PR reviews. Branch protection requires passing CI and approving review |
| "Show me the approval chain for this deploy" | `protocol deploy:log` — APPROVAL entries capture PR number, author, approvers, merger, and timestamp for every deployment |
| "Do you have centralized logging?" | Wazuh SIEM aggregates audit logs from all nodes with file integrity monitoring and tamper-evident storage |
| "How long do you retain logs?" | Audit logs auto-rotate at 5 MB with gzip compression, retaining 52 weekly archives (12 months) |

## Hardening Checklist

### Required for SOC 2

- [ ] Set `deployment.strategy` to `"release"` in protocol.json
- [ ] Set `deployment.secrets` to `"encrypted"` in protocol.json
- [ ] Run `protocol cron:add` on every node
- [x] Enable GitHub branch protection on master (required CI checks and PR reviews configured)
- [x] Require PR reviews before merging to production
- [ ] Verify `protocol soc2:check` passes on all nodes
- [ ] Keep encryption key backup in a password manager

### Strongly Recommended

- [ ] Install SIEM agent: `protocol siem:install --manager=your-wazuh-server`
- [ ] Forward audit logs to centralized logging
- [ ] Set up external health monitoring for each node
- [ ] Set up deployment notifications (Slack, email)
- [ ] Follow the [Key Rotation Procedure](key-rotation.md) quarterly
- [ ] Review the [Incident Response Runbook](incident-response.md) with your team
- [ ] Follow the [Deployment SOPs](deployment-sops.md) for all production changes
- [ ] Run `protocol security:audit` in your CI pipeline
- [ ] Fill in the contact list in the incident response runbook

### Good to Have

- [ ] Enable shadow deployment for zero-downtime deploys
- [ ] Run `protocol soc2:check` as a CI gate
- [ ] Set up weekly scheduled security scans (already configured in `.github/workflows/security-scan.yml`)
- [ ] Load balance across multiple independent nodes

## Related Documentation

| Document | What it covers |
|---|---|
| [SOC 2 Controls Matrix](soc2-controls-matrix.md) | Auditor-facing evidence matrix — every numbered criterion mapped to controls and evidence |
| [Security & Hardening](security.md) | Detailed security controls, encryption internals, audit log format |
| [Incident Response Runbook](incident-response.md) | Detection, triage, containment, resolution, post-incident review |
| [Key Rotation Procedure](key-rotation.md) | Step-by-step key rotation, rollback plan, automation |
| [Deployment SOPs](deployment-sops.md) | Standard procedures for deploys, rollbacks, hotfixes, and incidents |
| [Secrets Management](secrets.md) | Encryption setup, key distribution, GitHub Actions integration |
| [Shadow Deployment](blue-green.md) | Zero-downtime deploys with instant rollback |
