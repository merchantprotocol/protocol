# Incident Response Runbook

This document defines the incident response process for systems managed by Protocol. Every phase must be followed in order. Do not skip phases. Do not begin remediation without insurance authorization.

---

## Prerequisites: Webhook Notifications

Protocol can send automated notifications to Slack, PagerDuty, or any webhook-compatible service. Configure this in your `protocol.json` **before** you need it:

```json
{
    "notifications": {
        "webhook": "https://hooks.slack.com/services/YOUR/SLACK/WEBHOOK",
        "webhooks": [
            "https://hooks.slack.com/services/YOUR/SLACK/WEBHOOK",
            "https://events.pagerduty.com/integration/YOUR/KEY"
        ],
        "events": ["deploy", "security_audit", "soc2_check", "incident", "rollback", "key_rotation"]
    }
}
```

When configured, Protocol automatically notifies on:
- Deploy success/failure (`protocol deploy:push`)
- Security audit failures (`protocol start`, `protocol security:audit`)
- SOC 2 check failures (`protocol start`, `protocol soc2:check`)
- Incident reports (`protocol incident:report`)

If `events` is omitted or empty, all event types are enabled.

---

## Quick Reference: Incident Commands

| Command | When to use | What it does |
|---------|-------------|--------------|
| `protocol incident:status` | Phase 1 (Detection) — first thing | Live incident dashboard showing detected issues, containers, users, changed files |
| `protocol incident:snapshot` | Phase 2 (Triage) — preserve evidence | Captures forensic evidence: logs, processes, network, containers, git state |
| `protocol incident:report 1 "msg"` | Phase 2 (Triage) — after assessment | Creates full report + snapshot, opens GitHub issue, sends to webhooks. Severity auto-detected if omitted |
| `protocol security:audit` | Phase 4 (Scope assessment) | Scans for malicious code, permission issues, suspicious processes |
| `protocol security:trojansearch` | Phase 4 (Scope assessment) | Deep scan for trojans and backdoors |
| `protocol security:changedfiles` | Phase 4 (Scope assessment) | Lists recently modified files outside git |
| `protocol deploy:log` | Phase 4 (Scope assessment) | Review deployment history for unauthorized changes |
| `protocol siem:status` | Phase 4 (Scope assessment) | Check SIEM agent health |
| `protocol deploy:rollback` | Phase 5 (Eradication) — after insurance approval | Roll back to previous known-good release |
| `protocol secrets:setup` | Phase 4 (Credential rotation) | Generate new encryption key |
| `protocol status` | Phase 6 (Recovery) | Verify node health before returning to production |

---

## Severity Levels

| Level | Description | Response Time | Examples |
|-------|-------------|---------------|----------|
| **P1 — Critical** | Production down, data breach, active exploitation | 15 minutes | Service outage, unauthorized access, data exfiltration |
| **P2 — High** | Degraded service, security vulnerability discovered | 1 hour | Failed deployments, exposed credentials, dependency CVE |
| **P3 — Medium** | Non-critical issue, potential risk | 4 hours | SOC 2 check failures, audit log anomalies, permission drift |
| **P4 — Low** | Informational, improvement needed | 24 hours | Security scan warnings, stale dependencies |

---

## Phase 1: Detection

Incidents may be detected through:

- **Protocol security audit** — `protocol security:audit` flags malicious code, suspicious processes, permission issues
- **Protocol SOC 2 check** — `protocol soc2:check` detects readiness drift
- **Wazuh SIEM alerts** — Real-time file integrity, rootkit detection, log anomalies
- **Deployment audit log** — Unexpected entries in `protocol deploy:log`
- **External monitoring** — Uptime checks, health endpoint failures
- **Team reports** — Manual observation or customer reports

### Automated Detection Commands

```bash
# Run security audit
protocol security:audit

# Run SOC 2 readiness check
protocol soc2:check

# Check recent deployment activity
protocol deploy:log

# Check SIEM agent status
protocol siem:status

# Scan for trojans and backdoors
protocol security:trojansearch
```

---

## Phase 2: Triage

When an incident is detected:

1. **Assign severity** using the table above
2. **Notify the on-call responder** via your alerting channel (Slack, PagerDuty, email)
3. **Create an incident ticket** with:
   - What was detected
   - When it was detected
   - Which nodes/systems are affected
   - Current severity assessment
4. **Begin a timeline** — document every action taken with timestamps from this point forward. Every decision, command, and observation must be logged with who did it and when.

### Immediate System Snapshot

**Before any other action**, take a full snapshot of all affected systems. This preserves forensic evidence and provides a recovery point.

Protocol provides a single command that captures everything:

```bash
# On each affected node — captures audit logs, processes, network,
# Docker state, git state, system info, SIEM status, and more
protocol incident:snapshot
```

This saves all evidence to `~/.protocol/.node/incidents/snapshot-YYYY-MM-DD-HHMMSS/` with restricted permissions (0700). It captures:
- Protocol audit log and configuration
- All running processes and network connections
- Docker container state and recent logs
- Git history, diff, and reflog
- System info (uptime, disk, memory)
- Crontab entries and SIEM status
- Auth logs and recently modified files

If your infrastructure supports it, also take volume-level snapshots:
```bash
# AWS: Create EBS snapshots of all volumes
# GCP: Create persistent disk snapshots
# Azure: Create managed disk snapshots
# Bare metal: Create LVM snapshot or full disk image
```

**Do NOT destroy, modify, or overwrite any logs or data on affected systems.** Everything is potential evidence.

### Create the Incident Report

Once the snapshot is captured, create the formal incident report. This gathers system state, opens a GitHub issue for tracking, and sends notifications to all configured webhooks:

```bash
# Create the incident report — this is a single command
protocol incident:report 1 "Brief description of what happened"
# Or let Protocol auto-detect severity based on system state:
protocol incident:report "Brief description of what happened"
```

This will:
1. Run security audit and SOC 2 checks automatically
2. Gather deployment info, container status, git state, network connections
3. Save the full report to `~/.protocol/.node/incidents/YYYY-MM-DD-HHMMSS.md`
4. Create a GitHub issue with the full report attached
5. Send the report to all configured webhooks (Slack, PagerDuty, etc.)
6. Log an `INCIDENT` entry in the audit trail

---

## Phase 3: Isolation & Containment

The goal of this phase is to stop the bleeding. Affected nodes must be disconnected from all production systems, networks, and credentials immediately. Speed matters — every minute a compromised node stays connected is a minute the attacker has to move laterally.

### Step 1: Take Affected Nodes Offline

Remove compromised nodes from the production loop immediately. They must not be able to serve traffic, access databases, reach internal APIs, or use stored credentials.

```bash
# On each affected node:

# 1. Remove from load balancer FIRST (stops traffic routing)
#    AWS: Deregister from target group
#    Cloudflare: Remove DNS record or set to maintenance
#    HAProxy/Nginx: Remove upstream entry and reload

# 2. Stop all application containers (severs database/API connections)
protocol stop
docker compose down

# 3. Block all outbound network access (prevents data exfiltration)
#    If you have firewall access:
sudo iptables -A OUTPUT -j DROP 2>/dev/null
#    Or via cloud security group: remove all outbound rules

# 4. Revoke the node's SSH keys from other systems
#    Remove its public key from authorized_keys on all other nodes
#    Revoke any deploy keys this node had access to

# 5. Do NOT power off or reboot — preserve memory state for forensics
```

### Step 2: Verify Isolation

Confirm the node is fully disconnected:

```bash
# From another trusted system, verify the node is unreachable
curl -s -o /dev/null -w "%{http_code}" https://<affected-node-ip>/ # Should fail/timeout

# Verify it's removed from DNS/load balancer
dig <your-domain> +short  # Should not list the affected node's IP

# Verify remaining production nodes are healthy
# (from a trusted node)
protocol status
protocol security:audit
```

### Step 3: Preserve the Affected Node

The isolated node must remain intact for forensic analysis. Do not:
- Delete files
- Clear logs
- Reinstall the OS
- Run cleanup scripts
- Reboot the machine

If the infrastructure provider supports it, create a forensic disk image of the node at this point.

---

## Phase 4: War Room & Coordinated Response

Immediately establish a war room. This phase runs multiple workstreams in parallel. Only individuals who need to be involved should be present — this is need-to-know until the scope is understood.

### Step 1: Assemble the Response Team

Convene only the people required. Use a private, dedicated channel (not a public Slack channel).

| Role | Responsibility |
|------|---------------|
| **Incident Commander** | Coordinates all workstreams, makes decisions, owns the timeline |
| **Security Lead** | Leads forensic analysis, assesses scope, identifies attack vector |
| **Infrastructure Lead** | Handles node isolation, credential rotation, system recovery |
| **Legal/Compliance** | Manages notification obligations, coordinates with insurance |
| **Communications Lead** | Drafts internal and external communications (only when authorized) |

### Step 2: Contact Insurance Immediately

**This is not optional.** Contact your cyber liability insurance carrier before any remediation begins. Many policies require:

- Notification within 24-72 hours of discovery
- Use of insurance-approved forensic investigators
- Use of insurance-approved legal counsel (breach coach)
- Insurance authorization before engaging third-party vendors
- Preservation of evidence (no cleanup until authorized)

**CRITICAL: Do not begin eradication, remediation, or recovery until insurance has been contacted and has authorized next steps.** Unauthorized remediation can void coverage.

```
Insurance Contact Information:
  Carrier: _[Fill in]_
  Policy Number: _[Fill in]_
  Claims Hotline: _[Fill in]_
  Breach Coach (if pre-assigned): _[Fill in]_
```

### Step 3: Assess Scope Across All Systems (Security Lead)

While waiting for insurance response, the security team assesses the full scope. This is investigation only — no changes to systems.

```bash
# On every production node (not just the affected ones):

# Check for indicators of compromise
protocol security:audit
protocol security:trojansearch
protocol security:changedfiles

# Review deployment history for unauthorized changes
protocol deploy:log

# Check git history for suspicious commits
git -C /opt/public_html log --oneline -50
git -C /opt/public_html diff

# Review SIEM alerts across all nodes
protocol siem:status

# Check for unauthorized SSH access
last -50
cat /var/log/auth.log | grep -i "accepted\|failed" | tail -100
```

Document every finding. For each node, record:
- Is there evidence of compromise? (Yes / No / Inconclusive)
- What indicators were found?
- When did the earliest indicator appear?
- What data could this node access?

### Step 4: Credential Rotation (Infrastructure Lead)

Begin rotating credentials in priority order. Start with the highest-privilege credentials first, because those provide the broadest access if compromised.

**Priority 1 — Highest Privilege (rotate immediately):**
- [ ] Cloud provider root/admin credentials (AWS root, GCP org admin)
- [ ] Infrastructure SSH keys (deploy keys, bastion keys)
- [ ] Database root/admin passwords
- [ ] Protocol encryption key (`protocol secrets:setup` to generate new key)
- [ ] CI/CD secrets (GitHub Actions secrets, deployment tokens)

**Priority 2 — Service Level (rotate within hours):**
- [ ] Application database credentials (per-service DB users)
- [ ] Third-party API keys (payment processors, email services, etc.)
- [ ] OAuth client secrets
- [ ] Internal service-to-service tokens
- [ ] CDN/DNS management credentials

**Priority 3 — User Level (rotate within 24 hours):**
- [ ] All team member passwords (enforce password reset)
- [ ] Personal access tokens (GitHub, cloud providers)
- [ ] MFA device re-enrollment if MFA may be compromised
- [ ] VPN credentials
- [ ] Any shared passwords or service accounts

**Credential rotation procedure for Protocol encryption key:**

```bash
# On a trusted (non-compromised) machine:

# 1. Decrypt all secrets with the current key
protocol config:init

# 2. Generate a new encryption key
protocol secrets:setup

# 3. Re-encrypt with the new key
protocol config:init
protocol config:save

# 4. Distribute the new key ONLY to verified-clean nodes
protocol secrets:key --scp=deploy@clean-node-1
protocol secrets:key --scp=deploy@clean-node-2

# 5. Do NOT distribute the new key to any node that hasn't been cleared
```

### Step 5: Track Everything

Maintain a live incident document with:
- Timestamped timeline of every action
- Who performed each action
- Findings from each node assessment
- Credential rotation status (which are done, which are pending)
- Insurance communication log
- Decisions made and who authorized them

---

## Phase 5: Eradication & Remediation

**Do not begin this phase until insurance has authorized it.** If insurance requires a third-party forensic investigation, that investigation must complete (or at minimum begin) before systems are altered.

Once authorized:

1. **Identify root cause** — how did the attacker get in?
   - Compromised credentials?
   - Unpatched vulnerability?
   - Supply chain attack (compromised dependency)?
   - Insider threat?
   - Social engineering?

2. **Remove the threat** from all affected systems:
   ```bash
   # If malicious code was found:
   protocol security:trojansearch
   git log --all --oneline -- <suspicious-file>

   # Roll back to a known-good release
   protocol deploy:rollback

   # If a dependency CVE was the entry point:
   composer audit
   composer update
   ```

3. **Patch the vulnerability** that allowed the breach. Do not bring systems back online until the entry point is closed.

4. **Verify clean state** on every node:
   ```bash
   protocol security:audit
   protocol soc2:check
   ```

---

## Phase 6: Recovery

Recovery must be methodical. Do not rush systems back into production.

1. **Deploy from a verified clean state to new infrastructure when possible:**
   ```bash
   # Provision fresh nodes rather than reusing compromised ones
   # Deploy from a specific known-good release tag
   protocol start
   ```

2. **Bring nodes back into production one at a time.** Do not restore all nodes simultaneously.

3. **Verify each node before adding it to the load balancer:**
   ```bash
   protocol status
   protocol security:audit
   protocol soc2:check
   ```

4. **Monitor aggressively** for 30 days after recovery:
   - Watch deployment logs: `protocol deploy:log`
   - Monitor SIEM dashboard for repeat indicators of compromise
   - Review Wazuh file integrity alerts daily
   - Check application logs for anomalies
   - Increase logging verbosity if possible

---

## Phase 7: Notification & Legal Compliance

Data breach notification requirements vary by jurisdiction. Begin this assessment during Phase 4 (War Room) and execute once the scope is known.

### State Attorney General Notification

Many US states require notification to the Attorney General's office. Requirements vary:

| Timeframe | States (examples — verify current law) |
|-----------|---------------------------------------|
| **24 hours** | Some states require notification within 24 hours of discovery for breaches above certain thresholds |
| **30 days** | Many states (e.g., Florida, Colorado) require notification within 30 days |
| **45 days** | Several states allow up to 45 days |
| **60 days** | Some states allow up to 60 days |
| **Varies by scope** | Threshold triggers vary — some require notification for any breach, others only above a record count |

**Consult your breach coach (provided by insurance) for the specific requirements based on:**
- Which states your affected users reside in
- What type of data was exposed (PII, financial, health, etc.)
- How many individuals are affected
- Whether the data was encrypted at the time of breach

### Individual Notification

If personal data was compromised, affected individuals must typically be notified. The notification must include:
- What happened (plain language description)
- What data was involved
- What you're doing about it
- What they can do to protect themselves
- Contact information for questions

### Federal Requirements

- **HIPAA** (health data): 60-day notification to HHS, individuals, and media (if >500 affected)
- **GLBA** (financial data): Notify federal banking regulators as soon as possible
- **SEC** (public companies): Material cybersecurity incidents must be disclosed on Form 8-K within 4 business days
- **FTC**: Notification required under Health Breach Notification Rule for non-HIPAA entities

### International

- **GDPR** (EU residents): 72-hour notification to supervisory authority
- **PIPEDA** (Canada): Report to Privacy Commissioner as soon as feasible
- **UK GDPR**: 72-hour notification to ICO

### Notification Checklist

- [ ] Determine which jurisdictions apply based on affected user locations
- [ ] Engage breach coach (via insurance) for legal guidance
- [ ] Draft AG notification letters per jurisdiction requirements
- [ ] Draft individual notification letters
- [ ] Determine if credit monitoring must be offered
- [ ] File notifications within required timeframes
- [ ] Retain copies of all notifications sent with timestamps

---

## Phase 8: Post-Incident Review

Within 5 business days of recovery (or as directed by insurance/legal), conduct a post-incident review with the full response team.

### Document

- **Timeline** — complete, timestamped record of every event and action from detection to recovery
- **Root cause** — how the incident occurred and why it wasn't prevented
- **Attack vector** — how the attacker gained access and moved through systems
- **Scope** — what systems, data, and users were affected
- **Detection gap** — how long between initial compromise and detection
- **Response effectiveness** — what worked, what didn't, what took too long

### Action Items

- File tickets for every security improvement identified
- Update monitoring and alerting rules to catch similar incidents earlier
- Update this runbook if the process was inadequate
- Schedule any required follow-up with insurance
- Update SOC 2 readiness evidence log
- Schedule follow-up review in 30 days to verify all action items are complete

### Incident Report Template

```
## Incident Report: [TITLE]

Date of Discovery: YYYY-MM-DD HH:MM
Date of Containment: YYYY-MM-DD HH:MM
Date of Resolution: YYYY-MM-DD HH:MM
Severity: P1/P2/P3/P4
Duration: [discovery to resolution]
Incident Commander: [name]
Response Team: [names and roles]

### Summary
[1-2 sentence description of what happened]

### Timeline
- YYYY-MM-DD HH:MM — [event/action] — [who]
- YYYY-MM-DD HH:MM — [event/action] — [who]

### Root Cause
[What caused the incident — be specific]

### Attack Vector
[How the attacker gained access, what they did, how they moved laterally]

### Scope & Impact
- Systems affected: [list]
- Data affected: [types, volume]
- Users affected: [count, categories]
- Business impact: [downtime, financial, reputational]

### Containment Actions Taken
[What was done to stop the bleeding]

### Credential Rotations Completed
- [ ] [Credential type] — rotated YYYY-MM-DD by [who]

### Eradication & Remediation
[What was done to fix the root cause]

### Notifications Filed
- [ ] Insurance notified: YYYY-MM-DD
- [ ] AG notifications: [states, dates]
- [ ] Individual notifications: [count, date]
- [ ] Regulatory notifications: [agencies, dates]

### Lessons Learned
[What would we do differently next time]

### Action Items
- [ ] [Improvement 1] — Owner: [name] — Due: [date]
- [ ] [Improvement 2] — Owner: [name] — Due: [date]
```

---

## Communication Matrix

| Audience | When to Notify | Channel | Who Authorizes |
|----------|---------------|---------|----------------|
| On-call engineer | Immediately (all severities) | PagerDuty / Slack | Automatic |
| Incident Commander | Immediately (P1/P2) | Phone call | On-call engineer |
| Response team | Within 15 minutes (P1), 1 hour (P2) | Private Slack channel / War room | Incident Commander |
| Insurance carrier | Within 1 hour (P1), 24 hours (P2) | Claims hotline | Incident Commander |
| Executive leadership | P1 within 1 hour, P2 within 4 hours | Direct message / Phone | Incident Commander |
| Legal counsel | Any suspected data breach | Direct contact | Incident Commander |
| Customers | Only after scope is known and legal approves | Email / Status page | Legal + Exec |
| Attorney General | Per jurisdiction requirements | Formal written notice | Legal counsel |
| General public / media | Only if required or strategically necessary | Press release | Legal + Exec |

**IMPORTANT:** Do not communicate externally (customers, public, media, regulators) without authorization from both legal counsel and executive leadership. Premature or inaccurate communication can create additional legal liability.

---

## Contact List

| Role | Name | Contact |
|------|------|---------|
| Incident Commander (primary) | _[Fill in]_ | _[Fill in]_ |
| Incident Commander (backup) | _[Fill in]_ | _[Fill in]_ |
| Security Lead | _[Fill in]_ | _[Fill in]_ |
| Infrastructure Lead | _[Fill in]_ | _[Fill in]_ |
| On-call rotation | _[Fill in]_ | _[PagerDuty link]_ |
| Legal counsel | _[Fill in]_ | _[Fill in]_ |
| Insurance carrier | _[Fill in]_ | _[Policy # and claims hotline]_ |
| Breach coach | _[Fill in]_ | _[Fill in — often assigned by insurance]_ |
| Forensic investigator | _[Fill in]_ | _[Fill in — often assigned by insurance]_ |
| Communications / PR | _[Fill in]_ | _[Fill in]_ |
| Executive sponsor | _[Fill in]_ | _[Fill in]_ |

**Update this table with your actual contacts before going to production. Review quarterly.**

---

## Tabletop Exercise Schedule

SOC 2 auditors expect evidence that this plan has been practiced. Run tabletop exercises quarterly.

### What Is a Tabletop Exercise

A tabletop exercise is a discussion-based simulation. The Incident Commander presents a scenario, and the team walks through their response step by step using this runbook. No actual systems are affected.

### How to Run One

1. **Schedule quarterly** — put it on the calendar for the whole year
2. **Choose a scenario** from the list below (or create your own)
3. **Assemble the response team** — same people who would respond to a real incident
4. **Walk through each phase** of this runbook as it applies to the scenario
5. **Document** — record the exercise using the template below
6. **Identify gaps** — what was unclear, what took too long, what was missing from the runbook
7. **File action items** — update the runbook, fix gaps, assign owners

### Sample Scenarios

| Quarter | Scenario |
|---------|----------|
| Q1 | A developer's laptop is stolen. They had SSH access to production nodes and a local copy of the encryption key. |
| Q2 | Wazuh alerts on file integrity changes to PHP files on two production nodes. The changes include a `base64_decode(eval(...))` pattern. |
| Q3 | A third-party dependency used in the application has a published RCE vulnerability (CVE). Exploit code is publicly available. |
| Q4 | The deployment audit log shows a `DEPLOY` entry at 3:00 AM that nobody on the team initiated. The deployed version doesn't match any known release tag. |

### Exercise Record Template

```
## Tabletop Exercise Record

Date: YYYY-MM-DD
Facilitator: [name]
Participants: [names and roles]
Scenario: [brief description]
Duration: [how long the exercise took]

### Walkthrough Notes
[Phase-by-phase notes on how the team responded]

### Gaps Identified
- [Gap 1 — e.g., "Contact list was outdated"]
- [Gap 2 — e.g., "Nobody knew the insurance claims number"]

### Action Items
- [ ] [Fix 1] — Owner: [name] — Due: [date]
- [ ] [Fix 2] — Owner: [name] — Due: [date]

### Runbook Updates Made
- [Change 1 — e.g., "Updated contact list"]
- [Change 2 — e.g., "Added step for XYZ"]
```
