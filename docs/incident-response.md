# Incident Response Runbook

This document defines the incident response process for systems managed by Protocol. It covers detection, triage, containment, resolution, and post-incident review.

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
- **Protocol SOC 2 check** — `protocol soc2:check` detects compliance drift
- **Wazuh SIEM alerts** — Real-time file integrity, rootkit detection, log anomalies
- **Deployment audit log** — Unexpected entries in `protocol deploy:log`
- **External monitoring** — Uptime checks, health endpoint failures
- **Team reports** — Manual observation or customer reports

### Automated Detection Commands

```bash
# Run security audit
protocol security:audit

# Run SOC 2 compliance check
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
4. **Begin a timeline** — document every action taken with timestamps

---

## Phase 3: Containment

### If Credentials Are Compromised

```bash
# 1. Rotate the encryption key immediately
protocol config:init         # Decrypt with old key
protocol secrets:setup       # Generate new key
protocol config:init         # Re-encrypt with new key
protocol config:save         # Push encrypted files

# 2. Distribute new key to all nodes
protocol secrets:key --scp=deploy@prod-node-1
protocol secrets:key --scp=deploy@prod-node-2

# 3. Restart all nodes
# (on each node)
protocol stop && protocol start

# 4. Rotate any application secrets that were in .env files
# (database passwords, API keys, OAuth tokens, etc.)
```

### If a Node Is Compromised

```bash
# 1. Stop the compromised node immediately
protocol stop

# 2. Preserve evidence (do NOT destroy logs)
cp ~/.protocol/deployments.log ~/incident-$(date +%Y%m%d)-audit.log

# 3. Check what changed
protocol security:changedfiles
git -C /opt/public_html diff
git -C /opt/public_html log --oneline -20

# 4. Run security audit to assess scope
protocol security:audit

# 5. If the node can't be trusted, redeploy from scratch
# Provision a new node, deploy from a known-good release tag
protocol start
```

### If Malicious Code Is Found

```bash
# 1. Identify affected files
protocol security:trojansearch

# 2. Check git history for when the code was introduced
git log --all --oneline -- <suspicious-file>

# 3. Roll back to a known-good release
protocol deploy:rollback

# 4. Audit all recent commits and PRs
git log --since="7 days ago" --all --oneline
```

---

## Phase 4: Eradication

1. **Identify root cause** — how did the attacker/issue get in?
2. **Remove the threat** — delete malicious code, revoke compromised credentials, patch vulnerabilities
3. **Verify clean state**:
   ```bash
   protocol security:audit
   protocol soc2:check
   ```
4. **Update dependencies** if a CVE was the entry point:
   ```bash
   composer audit
   composer update
   ```

---

## Phase 5: Recovery

1. **Deploy from a verified clean state**:
   ```bash
   # Use a specific known-good release tag
   protocol deploy
   ```

2. **Verify all systems**:
   ```bash
   protocol status
   protocol security:audit
   protocol soc2:check
   ```

3. **Monitor closely** for 24-48 hours after recovery:
   - Watch deployment logs: `protocol deploy:log`
   - Monitor SIEM dashboard for repeat indicators
   - Check application logs for anomalies

---

## Phase 6: Post-Incident Review

Within 48 hours of resolution, conduct a post-incident review:

### Document

- **Timeline** — what happened, when, and what actions were taken
- **Root cause** — how the incident occurred
- **Impact** — what systems, data, or users were affected
- **Detection gap** — how long between incident start and detection
- **Response effectiveness** — what worked, what didn't

### Action Items

- File tickets for any security improvements identified
- Update monitoring/alerting rules to catch similar incidents earlier
- Update this runbook if the process was inadequate
- If SOC 2 relevant, add the incident to the compliance evidence log

### Template

```
## Incident Report: [TITLE]

Date: YYYY-MM-DD
Severity: P1/P2/P3/P4
Duration: [detection to resolution]
Responder(s): [names]

### Summary
[1-2 sentence description]

### Timeline
- HH:MM — [event]
- HH:MM — [action taken]

### Root Cause
[What caused the incident]

### Impact
[Systems, data, users affected]

### Remediation
[What was done to fix it]

### Action Items
- [ ] [Improvement 1]
- [ ] [Improvement 2]
```

---

## Communication

| Audience | When to Notify | Channel |
|----------|---------------|---------|
| On-call engineer | Immediately (all severities) | PagerDuty / Slack |
| Engineering team | P1/P2 within 30 minutes | Slack channel |
| Management | P1 within 1 hour, P2 within 4 hours | Email / Slack |
| Customers | P1 if data affected, within 72 hours (GDPR) | Email / Status page |
| Legal/Compliance | Any suspected data breach | Direct contact |

---

## Contact List

| Role | Name | Contact |
|------|------|---------|
| Security lead | _[Fill in]_ | _[Fill in]_ |
| On-call rotation | _[Fill in]_ | _[PagerDuty link]_ |
| Infrastructure lead | _[Fill in]_ | _[Fill in]_ |
| Legal contact | _[Fill in]_ | _[Fill in]_ |

Update this table with your team's actual contacts before going to production.
