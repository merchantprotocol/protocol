# SOC 2 Controls Matrix

This is the document you hand to your auditor. It maps every applicable SOC 2 Type II Trust Service Criterion to the specific control Protocol provides, where the evidence lives, and what your team needs to supply on top.

An auditor evaluating your SOC 2 Type II readiness will walk through these criteria one by one. For each one, they want to see: (1) what control is in place, (2) that it's been operating consistently over the review period, and (3) evidence proving both. This document gives them that.

**How to use this document:**
- Review each criterion with your team before the audit
- Run the evidence commands and save the output
- Fill in the "Your Team Provides" items — Protocol handles the technical controls, but organizational policies are on you
- Keep this document updated as you add or change controls

---

## CC6 — Logical and Physical Access Controls

### CC6.1 — The entity implements logical access security software, infrastructure, and architectures over protected information assets to protect them from security events.

| | |
|---|---|
| **Protocol Control** | All secrets (`.env` files) are encrypted with AES-256-GCM before entering any repository. The encryption key is stored at `~/.protocol/key` with `0600` permissions (owner-read/write only). The `~/.protocol/` directory is restricted to `0700`. These permissions are validated on every startup by `protocol soc2:check` — if they drift, the check fails. |
| **Evidence** | `protocol soc2:check` → "Encrypted secrets" and "Key permissions" rows show PASS |
| **Evidence Command** | `protocol soc2:check` — run on each node and save output |
| **Evidence Files** | `~/.protocol/key` (verify permissions with `ls -la`), `.env.enc` files in config repo (verify encrypted content) |
| **Your Team Provides** | Document which team members have access to production encryption keys. Maintain an access control list showing who can SSH into production nodes. |

### CC6.2 — Prior to issuing system credentials and granting system access, the entity registers and authorizes new internal and external users whose access is administered by the entity.

| | |
|---|---|
| **Protocol Control** | SSH key authentication is used for all git operations (`protocol key:generate` creates ed25519 keys). GitHub deploy keys are registered per-node. Encryption keys are distributed via secure channels (`protocol secrets:key --scp=user@host` or GitHub Secrets). No shared passwords are used. |
| **Evidence** | GitHub deploy key list (Settings → Deploy Keys), SSH key fingerprints on each node |
| **Evidence Command** | `ssh-add -l` on each node, `gh repo deploy-key list` |
| **Your Team Provides** | User onboarding/offboarding procedure. Document how new team members receive access to encryption keys and production nodes. Maintain a register of who has access to what. |

### CC6.3 — The entity authorizes, modifies, or removes access to data, software, functions, and other protected information assets based on roles, responsibilities, or the system design and changes, giving consideration to the concepts of least privilege and segregation of duties.

| | |
|---|---|
| **Protocol Control** | GitHub CODEOWNERS (`.github/CODEOWNERS`) enforces role-based review requirements. Changes to security-critical files (encryption, audit logging, deployment logic) require `@merchantprotocol/security` team approval. All other changes require `@merchantprotocol/core` team review. Environment isolation ensures each environment (production, staging, dev) has its own config branch — a developer's laptop never touches production credentials. |
| **Evidence** | `.github/CODEOWNERS` file contents, GitHub branch protection settings, config repo branch list |
| **Evidence Command** | `cat .github/CODEOWNERS`, `gh api repos/{owner}/{repo}/branches/master/protection` |
| **Your Team Provides** | Organization chart showing roles and responsibilities. Document the approval process for granting production access. Record access reviews (quarterly recommended). |

### CC6.6 — The entity implements logical access security measures to protect against threats from sources outside its system boundaries.

| | |
|---|---|
| **Protocol Control** | All shell command inputs are sanitized with `escapeshellarg()` to prevent command injection. Security audit (`protocol security:audit`) scans for suspicious processes (cryptominers, reverse shells) and malicious code patterns. Docker security checks flag dangerous configurations (`privileged: true`, `user: root`, risky capabilities). |
| **Evidence** | `protocol security:audit` output showing PASS for process audit and Docker security checks |
| **Evidence Command** | `protocol security:audit` — run on each node and save output |
| **Your Team Provides** | Network security configuration (firewalls, VPN, security groups). Document external-facing ports and services. |

### CC6.7 — The entity restricts the transmission, movement, and removal of information to authorized internal and external users and processes, and protects it during transmission, movement, or removal to meet the entity's objectives.

| | |
|---|---|
| **Protocol Control** | Secrets are encrypted at rest with AES-256-GCM before transmission through git. Plaintext never travels through any repository. Encryption key transfer uses SCP (encrypted SSH channel) or GitHub Secrets (encrypted at rest by GitHub). `.gitignore` includes `.env`, `*.key`, and `*.pem` to prevent accidental plaintext commits. CI pipeline runs TruffleHog secret scanning to detect leaked credentials. |
| **Evidence** | `.gitignore` contents, `.env.enc` files (verify they're encrypted, not plaintext), TruffleHog scan results from CI |
| **Evidence Command** | `cat .gitignore`, `head -1 path/to/.env.enc` (should show base64, not plaintext), GitHub Actions → security-scan workflow results |
| **Your Team Provides** | Data classification policy defining what constitutes sensitive data. Document any data transfer procedures outside of Protocol. |

### CC6.8 — The entity implements controls to prevent or detect and act upon the introduction of unauthorized or malicious software.

| | |
|---|---|
| **Protocol Control** | `protocol security:audit` scans PHP files for backdoor patterns (`eval(`, `base64_decode(`, `shell_exec(`, `proc_open(`, etc.). `protocol security:trojansearch` performs deep scanning with risk scoring. `protocol security:changedfiles` flags files modified recently that aren't tracked by git. CI pipeline runs static analysis and file permission checks weekly. Dependency vulnerabilities are checked via `composer audit` on every push. |
| **Evidence** | `protocol security:audit` output, `protocol security:trojansearch` output, CI workflow results, `composer audit` output |
| **Evidence Command** | `protocol security:audit`, `protocol security:trojansearch`, `composer audit` |
| **Your Team Provides** | Malware/antivirus policy for developer workstations. Procedure for responding to detected malicious code (reference the [Incident Response Runbook](incident-response.md)). |

---

## CC7 — System Operations

### CC7.1 — To meet its objectives, the entity uses detection and monitoring procedures to identify (1) changes to configurations that result in the introduction of new vulnerabilities, and (2) susceptibilities to newly discovered vulnerabilities.

| | |
|---|---|
| **Protocol Control** | `protocol security:audit` runs automatically during every `protocol start`, checking for: malicious code patterns, file permission drift, dependency vulnerabilities (`composer audit`), suspicious processes, Docker misconfigurations, and unauthorized file changes (files modified in last 24 hours not tracked by git). Weekly scheduled CI scan (`.github/workflows/security-scan.yml`) runs TruffleHog and static analysis on a recurring basis. |
| **Evidence** | Security audit results from each `protocol start` invocation, weekly CI scan history in GitHub Actions |
| **Evidence Command** | `protocol security:audit`, GitHub Actions → security-scan workflow run history |
| **Your Team Provides** | Vulnerability management policy. Procedure for triaging and remediating discovered vulnerabilities. Subscribe to security advisories for your dependencies. |

### CC7.2 — The entity monitors system components and the operation of those components for anomalies that are indicative of malicious acts, natural disasters, and errors affecting the entity's ability to meet its objectives.

| | |
|---|---|
| **Protocol Control** | Wazuh SIEM agent (`protocol siem:install`) provides real-time file integrity monitoring for `~/.protocol/` directory, rootkit detection, and log anomaly analysis. Protocol's audit log (`~/.protocol/deployments.log`) is forwarded to the centralized SIEM for tamper-evident storage. `protocol status` provides a real-time dashboard showing Docker container health, watcher status, SIEM connectivity, and security state. |
| **Evidence** | Wazuh dashboard showing agent connectivity and alerts, `protocol siem:status` output, `protocol status` output |
| **Evidence Command** | `protocol siem:status`, `protocol status` |
| **Your Team Provides** | SIEM alert routing and escalation procedures. Define which anomalies trigger alerts vs. which are informational. External uptime monitoring configuration (Uptime Robot, Datadog, etc.). |

### CC7.3 — The entity evaluates security events to determine whether they could or have resulted in a failure of the entity to meet its objectives.

| | |
|---|---|
| **Protocol Control** | Security audit and SOC 2 readiness checks produce structured results with PASS/WARN/FAIL status for each check. Failed checks include specific messages identifying the issue. Audit log captures failed deployment attempts alongside successful ones, enabling detection of operational anomalies. |
| **Evidence** | Historical `protocol security:audit` and `protocol soc2:check` outputs, audit log entries showing `status=failure` |
| **Evidence Command** | `protocol deploy:log` — filter for `status=failure` entries |
| **Your Team Provides** | Security event evaluation procedure. Define severity levels and response timelines (reference [Incident Response Runbook](incident-response.md) severity table). |

### CC7.4 — The entity responds to identified security incidents by executing a defined incident response program to understand, contain, remediate, and communicate security incidents, as identified.

| | |
|---|---|
| **Protocol Control** | Protocol provides an [Incident Response Runbook](incident-response.md) with defined severity levels (P1–P4), response timeframes, and phase-by-phase procedures: Detection → Triage → Containment → Eradication → Recovery → Post-Incident Review. Includes playbooks for compromised credentials, compromised nodes, and malicious code. Communication matrix defines notification timelines for engineering, management, customers, and legal. |
| **Evidence** | `docs/incident-response.md` — the runbook itself. Post-incident review reports (created using the template in the runbook). |
| **Evidence Files** | [Incident Response Runbook](incident-response.md) |
| **Your Team Provides** | Fill in the Contact List table in the runbook with actual team contacts. Conduct annual incident response drills and document the results. Keep post-incident review reports on file. |

### CC7.5 — The entity identifies, develops, and implements activities to recover from identified security incidents.

| | |
|---|---|
| **Protocol Control** | Incident Response Runbook Phase 5 (Recovery) defines the recovery procedure: deploy from a verified clean state, verify all systems with `protocol status`, `protocol security:audit`, and `protocol soc2:check`, then monitor closely for 24–48 hours. Key rotation procedure (`protocol secrets:setup` → re-encrypt → distribute) is documented for credential compromise scenarios. `protocol deploy:rollback` provides instant rollback to a known-good release. |
| **Evidence** | Recovery procedure documentation, key rotation logs, rollback audit trail entries |
| **Evidence Files** | [Incident Response Runbook](incident-response.md) Phase 5, [Key Rotation Procedure](key-rotation.md) |
| **Your Team Provides** | Maintain a log of recovery actions taken during actual incidents. Document lessons learned and update procedures based on post-incident reviews. |

---

## CC8 — Change Management

### CC8.1 — The entity authorizes, designs, develops or acquires, configures, documents, tests, approves, and implements changes to infrastructure, data, software, and procedures to meet its objectives.

| | |
|---|---|
| **Protocol Control** | All code and configuration changes flow through git with full commit history (author, timestamp, message). Release mode deploys immutable git tags — `v1.2.0` always means the same code. CI pipeline (`.github/workflows/ci.yml`) enforces automated testing (PHPUnit across PHP 8.1/8.2/8.3), syntax checking, dependency auditing, and secret scanning on every push and pull request. CODEOWNERS requires team review before merge. `protocol deploy:push` captures the full PR approval chain (author, approvers, merger) and writes APPROVAL entries to the audit log. GitHub branch protection requires passing CI status checks and approved reviews before merge. |
| **Evidence** | Git commit history, GitHub PR history with approvals, CI pipeline results, audit log APPROVAL entries, branch protection configuration |
| **Evidence Command** | `protocol deploy:log` (shows DEPLOY and APPROVAL entries), `gh pr list --state merged --limit 50`, `gh api repos/{owner}/{repo}/branches/master/protection` |
| **Evidence Files** | `.github/workflows/ci.yml`, `.github/CODEOWNERS`, `~/.protocol/deployments.log` |
| **Your Team Provides** | Change management policy documenting the approval workflow. [Deployment SOPs](deployment-sops.md) — follow these for all production changes. Document any emergency change procedures (reference SOP 6: Emergency Hotfix). |

---

## A1 — Availability

### A1.1 — The entity maintains, monitors, and evaluates current processing capacity and use of system components (infrastructure, data, and software) to manage capacity demand and to enable the implementation of additional capacity to help meet its objectives.

| | |
|---|---|
| **Protocol Control** | `protocol status` shows real-time container health, running counts, watcher status, and resource state. Docker Compose manages container lifecycle with configurable resource limits. Independent node architecture allows horizontal scaling by adding nodes — each node watches GitHub independently. |
| **Evidence** | `protocol status` output from each node, Docker container resource metrics |
| **Evidence Command** | `protocol status`, `docker stats` |
| **Your Team Provides** | Capacity planning documentation. External monitoring dashboards showing resource utilization trends. Alert thresholds for CPU, memory, and disk usage. |

### A1.2 — The entity authorizes, designs, develops or acquires, implements, operates, approves, maintains, and monitors environmental protections, software, data backup, and recovery infrastructure and processes to meet its objectives.

| | |
|---|---|
| **Protocol Control** | `protocol cron:add` installs a `@reboot` crontab entry ensuring Protocol restarts automatically after server reboots — pulling the current release, decrypting secrets, starting containers, and resuming watchers. The SOC 2 readiness check (`protocol soc2:check`) validates this is in place on every startup. Shadow deployment mode builds new versions in parallel, enabling zero-downtime updates. Configuration and secrets are stored in git with encryption, providing a backup and recovery mechanism. |
| **Evidence** | `protocol soc2:check` → "Reboot recovery" row shows PASS, crontab contents |
| **Evidence Command** | `protocol soc2:check`, `crontab -l` |
| **Your Team Provides** | Backup and recovery policy covering databases, user data, and application state. Document RPO (Recovery Point Objective) and RTO (Recovery Time Objective) targets. Server infrastructure redundancy documentation. |

### A1.3 — The entity tests recovery plan procedures supporting system recovery to meet its objectives.

| | |
|---|---|
| **Protocol Control** | `protocol deploy:rollback` provides tested rollback capability — change the release pointer and every node deploys the previous version. Shadow mode rollback swaps ports in under one second. The Incident Response Runbook includes recovery procedures with specific commands. |
| **Evidence** | Rollback entries in `protocol deploy:log`, incident response drill records |
| **Evidence Command** | `protocol deploy:log` — look for ROLLBACK entries |
| **Your Team Provides** | Annual disaster recovery test results. Document the recovery procedure test, who participated, what was tested, and what was learned. Tabletop exercises using the Incident Response Runbook. |

---

## C1 — Confidentiality

### C1.1 — The entity identifies and maintains confidential information to meet the entity's objectives related to confidentiality.

| | |
|---|---|
| **Protocol Control** | Secrets are encrypted with AES-256-GCM (256-bit key, random 12-byte nonce per file, authenticated encryption with tamper detection). Encryption key never enters any git repository — stored only at `~/.protocol/key` on each machine with `0600` permissions. Plaintext `.env` files are gitignored (`.env`, `*.key`, `*.pem` in `.gitignore`). During decryption, plaintext files receive `0600` permissions; on Linux, `decryptToTempFile()` uses RAM-backed `/dev/shm` to avoid writing to disk. Each environment is a separate config branch — production credentials never appear in development branches. CI pipeline runs TruffleHog to detect accidentally committed secrets. |
| **Evidence** | Encrypted `.env.enc` files in config repo, `.gitignore` contents, key file permissions, TruffleHog CI results |
| **Evidence Command** | `protocol soc2:check` → "Encrypted secrets" and "Key permissions" rows, `cat .gitignore`, `ls -la ~/.protocol/key` |
| **Your Team Provides** | Data classification policy identifying what constitutes confidential information. Document which secrets are stored in `.env` files (database credentials, API keys, etc.). Key custodian list — who has access to the encryption key. |

### C1.2 — The entity disposes of confidential information to meet the entity's objectives related to confidentiality.

| | |
|---|---|
| **Protocol Control** | After encryption, plaintext `.env` files are deleted and gitignored. Key rotation procedure (`protocol secrets:setup` → re-encrypt → distribute) replaces old encryption keys. When using `decryptToTempFile()`, temporary plaintext is created in `/dev/shm` (RAM-backed, not persisted to disk) and cleaned up after use. |
| **Evidence** | Absence of plaintext `.env` files in git history, key rotation records |
| **Evidence Command** | `git log --all --diff-filter=A -- '*.env'` (should show no results), key file modification date (`stat ~/.protocol/key`) |
| **Your Team Provides** | Data retention and disposal policy. Procedure for securely decommissioning nodes (wipe encryption keys, revoke SSH keys, remove from deploy key list). |

---

## Evidence Collection Checklist

Run these commands before your audit and save the output. Do this on **every production node**.

```bash
# Save all evidence to a timestamped directory
EVIDENCE_DIR="soc2-evidence-$(date +%Y-%m-%d)"
mkdir -p "$EVIDENCE_DIR"

# SOC 2 readiness check (CC6, CC7, A1, C1)
protocol soc2:check > "$EVIDENCE_DIR/soc2-check.txt" 2>&1

# Security audit (CC6, CC7)
protocol security:audit > "$EVIDENCE_DIR/security-audit.txt" 2>&1

# Full status dashboard (A1, CC7)
protocol status > "$EVIDENCE_DIR/status.txt" 2>&1

# Deployment audit trail (CC7, CC8)
protocol deploy:log > "$EVIDENCE_DIR/deploy-log.txt" 2>&1

# SIEM agent health (CC7)
protocol siem:status > "$EVIDENCE_DIR/siem-status.txt" 2>&1

# Key file permissions (CC6, C1)
ls -la ~/.protocol/key >> "$EVIDENCE_DIR/permissions.txt" 2>&1
ls -la ~/.protocol/ >> "$EVIDENCE_DIR/permissions.txt" 2>&1

# Crontab reboot recovery (A1)
crontab -l > "$EVIDENCE_DIR/crontab.txt" 2>&1

# Encryption key age (CC6)
stat ~/.protocol/key >> "$EVIDENCE_DIR/key-age.txt" 2>&1

# Dependency audit (CC6, CC7)
composer audit > "$EVIDENCE_DIR/composer-audit.txt" 2>&1

# Git remote verification (CC7)
git remote -v > "$EVIDENCE_DIR/git-remote.txt" 2>&1

# Docker container health (A1)
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" > "$EVIDENCE_DIR/containers.txt" 2>&1

echo "Evidence collected in $EVIDENCE_DIR/"
```

## Documents Your Team Maintains

Protocol handles the technical controls. These organizational documents are your responsibility — auditors will ask for them:

| Document | SOC 2 Criteria | What It Covers |
|---|---|---|
| **Access Control Policy** | CC6.1, CC6.2, CC6.3 | Who has access to what, how access is granted/revoked, review schedule |
| **Change Management Policy** | CC8.1 | How changes are approved, tested, and deployed. Reference [Deployment SOPs](deployment-sops.md) |
| **Incident Response Plan** | CC7.4, CC7.5 | Already provided: [Incident Response Runbook](incident-response.md). Fill in the contact list |
| **Key Rotation Records** | CC6.1, C1.1 | Log of each key rotation: date, who performed it, which nodes were updated |
| **Data Classification Policy** | C1.1, C1.2 | What data is confidential, how it's labeled, retention and disposal rules |
| **Business Continuity Plan** | A1.2, A1.3 | RPO/RTO targets, backup procedures, disaster recovery test results |
| **Vendor Management Policy** | CC6.6 | Third-party risk assessment for GitHub, Docker Hub, hosting providers |
| **Employee Security Training** | CC6.2 | Training records showing team members understand security policies |
| **Risk Assessment** | All | Annual risk assessment identifying threats, vulnerabilities, and mitigations |

## Audit Period Evidence

SOC 2 Type II covers a review period (typically 6–12 months). Auditors want to see controls operating **consistently** over that period, not just at a point in time. Here's what to preserve throughout the audit period:

| Evidence Type | How to Preserve | Retention |
|---|---|---|
| Deployment audit log | Automatically rotated and archived by Protocol (52 weekly gzip archives) | 12 months |
| CI/CD pipeline results | GitHub Actions retains workflow run history | Per GitHub retention settings |
| PR approval history | GitHub retains all PR data indefinitely | Indefinite |
| Security scan results | Run evidence collection script monthly, save to secure storage | 12 months |
| SIEM alerts and logs | Wazuh retains per your SIEM retention policy | 12 months minimum |
| Key rotation records | Manual log maintained by your team | 12 months |
| Incident reports | Created using the template in the Incident Response Runbook | 12 months |
| Access review records | Manual log maintained by your team | 12 months |

## Related Documentation

| Document | Purpose |
|---|---|
| [SOC 2 Ready](soc2.md) | High-level overview of Protocol's SOC 2 capabilities |
| [Security & Hardening](security.md) | Detailed security controls, encryption internals |
| [Incident Response Runbook](incident-response.md) | Detection through post-incident review |
| [Key Rotation Procedure](key-rotation.md) | Step-by-step key rotation with rollback plan |
| [Deployment SOPs](deployment-sops.md) | Standard operating procedures for all deployment scenarios |
