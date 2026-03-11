# Security & SOC2 Type II Compliance

This document covers security considerations for using Protocol in environments that require SOC2 Type II compliance. It identifies current gaps, provides hardening guidance, and maps Protocol's capabilities to SOC2 trust service criteria.

## SOC2 Type II Overview

SOC2 Type II evaluates an organization's controls over time across five trust service criteria:

1. **Security** — Protection against unauthorized access
2. **Availability** — System uptime and operational reliability
3. **Processing Integrity** — Accurate and complete data processing
4. **Confidentiality** — Protection of confidential information
5. **Privacy** — Personal information handling

Protocol touches Security, Availability, Confidentiality, and Processing Integrity through its deployment and configuration management functions.

## How Protocol Maps to SOC2 Controls

### CC6: Logical and Physical Access Controls

#### What Protocol Provides

- **SSH Key Management** — `protocol key:generate` creates ed25519 SSH keys for deployment, avoiding password-based authentication
- **Separate Config Repositories** — Secrets and configuration files are stored in a separate, access-controlled repository
- **Environment Isolation** — Each environment (production, staging, dev) uses a separate config branch, preventing cross-environment credential leakage

#### Current Gaps and Recommendations

| Gap | Risk | Recommendation |
|---|---|---|
| `protocol.json` can store Docker registry passwords in plaintext | Credential exposure if repo is compromised | Use environment variables or a secrets manager (Vault, AWS Secrets Manager) instead of storing credentials in `protocol.json` |
| `docker:push` passes passwords via `echo \| docker login --password-stdin` | Password visible in process list briefly | Use `docker login` with credential helpers or CI/CD-managed tokens |
| No audit logging of who ran what commands | Cannot prove access controls are enforced | Implement command execution logging (see Audit Logging section below) |
| SSH key generated with hardcoded comment `worker@ec2.com` | Key attribution is unclear | Generate keys with identifiable comments tied to the operator or service account |
| Config files may contain secrets symlinked into the app directory | Secrets accessible to application runtime | Use runtime secret injection (environment variables) where possible |

### CC7: System Operations / Change Management

#### What Protocol Provides

- **Git-Based Deployment** — All code changes flow through git, providing a complete audit trail of what was deployed and when
- **Git-Based Configuration** — Config changes are versioned in git, enabling rollback and change history
- **Slave Mode** — Automated deployment ensures consistency across nodes; manual changes to production are overwritten
- **`protocol.lock`** — Runtime state file tracks active PIDs, symlinks, and environment

#### Current Gaps and Recommendations

| Gap | Risk | Recommendation |
|---|---|---|
| `git:pull` does a hard reset — no review gate | Untested code can reach production immediately | Add a pre-pull hook that checks for required CI status checks or approval tags |
| No deployment approval workflow | Changes bypass change management | Integrate with GitHub branch protection rules; only deploy from branches with required reviews |
| No deployment notifications | Team unaware of production changes | Add webhook or notification hooks (Slack, email) on `change_pulled` events |
| Config slave mode auto-pulls config changes | Config changes bypass review | Require pull request reviews on config repo branches, especially production |
| No rollback mechanism beyond git revert | Recovery requires manual git operations | Add `protocol rollback` command that reverts to a previous known-good commit |

### CC8: Change Management

#### What Protocol Provides

- **`protocol.json` versioned in git** — Infrastructure-as-code pattern; deployment configuration is tracked
- **`release:changelog`** — Generates changelogs from git history for change documentation
- **Project Initializers** — Standardized project setup reduces configuration drift

#### Current Gaps and Recommendations

| Gap | Risk | Recommendation |
|---|---|---|
| No environment promotion workflow | Changes may skip staging | Add `protocol promote` command that moves a tested config from staging to production |
| No config diff before applying | Unknown what will change | Add `protocol config:diff` command that shows differences before switching environments |
| `protocol.json` schema is not validated | Invalid config can cause runtime failures | Add JSON schema validation on load |

### A1: Availability

#### What Protocol Provides

- **Crontab Restart** — `@reboot` cron entry ensures Protocol restarts after server reboot (`protocol cron:add`)
- **Process Monitoring** — `protocol status` shows whether slave watchers and containers are running
- **Docker Compose Integration** — Container orchestration with automatic restart

#### Current Gaps and Recommendations

| Gap | Risk | Recommendation |
|---|---|---|
| Slave mode polling is the only health check | No alerting if slave mode dies silently | Add health check endpoint or external monitoring integration |
| No graceful degradation | If config repo is unreachable, slave mode stops | Add retry logic with exponential backoff and alerting on repeated failures |
| `LockableTrait` prevents concurrent runs but has no timeout | Stale locks can prevent restarts | Add lock timeout/expiry mechanism |
| PID-based process tracking can be stale | PIDs get recycled by the OS | Verify process name matches, not just PID existence |

### C1: Confidentiality

#### What Protocol Provides

- **Separate Config Repository** — Secrets live outside the application codebase
- **Branch-Based Environment Isolation** — Production secrets are on a different branch than development
- **`.gitignore` Integration** — `config:mv` automatically adds moved files to `.gitignore`

#### Current Gaps and Recommendations

| Gap | Risk | Recommendation |
|---|---|---|
| No encryption at rest for config files | Secrets readable if server is compromised | Use encrypted config files or a secrets manager |
| Config repo may be cloned to developer machines | Production secrets on developer laptops | Use branch protection to prevent developers from checking out production branches |
| No secret rotation support | Stale credentials increase breach impact | Document secret rotation procedures; consider integration with secrets managers |
| Background process log may contain sensitive output | Log files expose operational details | Ensure log file permissions are restricted; rotate and purge logs |

## Known Security Vulnerabilities

The following issues exist in the current codebase and should be addressed before using Protocol in a SOC2-audited environment.

### Command Injection (Critical)

Several helpers pass unsanitized input directly into shell commands:

**Affected Files:**
- `src/Helpers/Shell.php` — `run()` and `background()` execute unescaped `$command` parameters
- `src/Helpers/Git.php` — `commit()` passes `$message` directly into shell: `git commit -m '$message'`
- `src/Helpers/Crontab.php` — `appendCrontab()` injects `$toappend` into shell pipe without escaping

**Impact:** An attacker who can influence command arguments, commit messages, branch names, or file paths could execute arbitrary system commands.

**Remediation:**
- Use `escapeshellarg()` for all variables interpolated into shell commands
- Use `ProcessBuilder` or `symfony/process` instead of raw `exec()`/`passthru()`
- Validate and sanitize all user-provided inputs (branch names, file paths, environment names)

### Path Traversal (Medium)

- `Helpers\Config::repo()` checks for `..` in paths but the validation is insufficient
- File operations in `config:mv`, `config:cp` accept relative paths without boundary checking

**Remediation:**
- Validate that resolved paths stay within expected directories
- Use `realpath()` and verify the result starts with the expected base path

### Debug Code in Production (Low)

- `src/Helpers/Release.php` contains `var_dump($releases); die;`
- `src/Helpers/Shell.php` `getProcess()` contains `var_dump()` with no return

**Remediation:** Remove all debug statements.

### Credential Exposure (Medium)

- `protocol.json` can store Docker registry credentials in plaintext
- `docker:push` passes password via shell pipe
- SSH key generation uses a hardcoded, non-identifying email

**Remediation:**
- Never store credentials in `protocol.json` — use environment variables or credential helpers
- Use Docker credential stores
- Generate SSH keys with identifiable metadata

## Audit Logging Recommendations

For SOC2 Type II compliance, you need evidence that controls operate effectively over time. Protocol currently has no audit logging.

### Recommended Implementation

1. **Command Execution Log** — Log every Protocol command execution with timestamp, user, command, arguments, and outcome to a dedicated log file
2. **Deployment Log** — Record every `git:pull` and `config:switch` with before/after commit hashes
3. **Access Log** — Track `config:link`, `config:unlink`, and environment switches
4. **Error Log** — Capture all command failures with context

### Log Format Recommendation

```
[2024-01-15T10:30:00Z] user=deploy command="protocol start" dir=/opt/app result=success
[2024-01-15T10:30:01Z] user=deploy command="git:pull" dir=/opt/app before=abc123 after=def456 result=success
[2024-01-15T10:30:05Z] user=deploy command="config:link" dir=/opt/app env=production files=3 result=success
```

### Log Retention

SOC2 Type II audits typically cover a 6-12 month period. Retain audit logs for at least 12 months. Consider forwarding to a centralized logging system (CloudWatch, Datadog, Splunk) that provides tamper-evident storage.

## Hardening Checklist

Before deploying Protocol in a SOC2-compliant environment:

- [ ] Remove all credentials from `protocol.json` — use environment variables or secrets manager
- [ ] Ensure config repositories are private with restricted access
- [ ] Enable branch protection on production branches (both code and config repos)
- [ ] Require pull request reviews before merging to production
- [ ] Set up deployment notifications (Slack, email, PagerDuty)
- [ ] Implement audit logging for all Protocol commands
- [ ] Run `security:trojansearch` as part of CI/CD pipeline
- [ ] Restrict `protocol.lock` and log file permissions (`chmod 600`)
- [ ] Use SSH key authentication exclusively (no password-based git access)
- [ ] Set up monitoring and alerting for slave mode process health
- [ ] Document incident response procedures for deployment failures
- [ ] Implement secret rotation schedule
- [ ] Review and fix command injection vulnerabilities in Shell, Git, and Crontab helpers
- [ ] Remove debug code (`var_dump`, `die`) from production
- [ ] Set up log forwarding to a centralized, tamper-evident logging system
- [ ] Document the deployment process in a runbook for auditors

## Recommended Architecture for SOC2 Environments

```
┌──────────────────────────────────────────────────┐
│                  GitHub / GitLab                  │
│                                                  │
│  ┌──────────┐    ┌──────────────┐               │
│  │ App Repo │    │ Config Repo  │               │
│  │ (private)│    │ (private)    │               │
│  │          │    │              │               │
│  │ Branch   │    │ Branch       │               │
│  │ protect  │    │ protection   │               │
│  │ + CI     │    │ + reviews    │               │
│  └────┬─────┘    └──────┬───────┘               │
│       │                 │                        │
└───────┼─────────────────┼────────────────────────┘
        │                 │
        ▼                 ▼
┌──────────────────────────────────────────────────┐
│              Production Node                     │
│                                                  │
│  protocol start                                  │
│  ├── git:slave (polls app repo)                  │
│  ├── config:slave (polls config repo)            │
│  ├── Audit Logger (all commands logged)          │
│  └── Docker containers                           │
│                                                  │
│  Secrets: injected via env vars / secrets mgr    │
│  Logs: forwarded to centralized system           │
│  Monitoring: external health checks              │
└──────────────────────────────────────────────────┘
```
