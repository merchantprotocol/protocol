# Deployment Standard Operating Procedures

This document defines the standard procedures for deploying, rolling back, and managing production systems with Protocol. Follow these procedures to maintain SOC 2 compliance and operational reliability.

---

## SOP 1: Standard Production Deployment

### When
A new release is ready to deploy to production.

### Prerequisites
- [ ] All CI checks pass (syntax, security scan, dependency audit)
- [ ] PR reviewed and approved by at least one team member
- [ ] Release tag created via `protocol release:create`
- [ ] Config changes (if any) encrypted and pushed to config repo
- [ ] Deployment window confirmed with the team

### Procedure

```bash
# 1. Verify the release exists
protocol release:list

# 2. Run pre-deployment checks
protocol security:audit
protocol soc2:check

# 3. Deploy (release-based strategy)
protocol deploy

# 4. Verify the deployment
protocol status
protocol deploy:log

# 5. Run post-deployment health checks
# Check your application's health endpoints
# Verify key functionality manually or via smoke tests
```

### Success Criteria
- `protocol status` shows all containers running
- `protocol security:audit` passes
- `protocol deploy:log` shows the deployment with `status=success`
- Application health endpoints return 200

### If Deployment Fails
Proceed to **SOP 3: Rollback**.

---

## SOP 2: First-Time Node Setup

### When
Provisioning a new production node.

### Procedure

```bash
# 1. Install Protocol
curl -sL https://raw.githubusercontent.com/merchantprotocol/protocol/master/bin/install | bash

# 2. Generate SSH key for git access
protocol key:generate
# Add the public key to your GitHub deploy keys

# 3. Clone the project repository
git clone git@github.com:your-org/your-app.git /opt/public_html
cd /opt/public_html

# 4. Initialize Protocol
protocol init

# 5. Set up the encryption key
# Option A: Copy from another node
protocol secrets:setup "your-64-char-hex-key"

# Option B: Receive via SCP from a node that has the key
# (run on the sending node)
# protocol secrets:key --scp=deploy@this-new-node

# 6. Install Wazuh SIEM agent
protocol siem:install --manager=wazuh.example.com

# 7. Start the node
protocol start

# 8. Verify
protocol status
protocol security:audit
protocol soc2:check
protocol siem:status
```

### Post-Setup Checklist
- [ ] Node appears in Wazuh dashboard
- [ ] `protocol soc2:check` all pass
- [ ] Crontab reboot recovery installed (`protocol cron:add` — done by `start`)
- [ ] Node is reachable by load balancer / monitoring

---

## SOP 3: Rollback

### When
A deployment caused issues and needs to be reverted.

### Procedure

```bash
# 1. Identify the current and previous versions
protocol deploy:log

# 2. Roll back to the previous version
protocol deploy:rollback

# 3. Verify the rollback
protocol status
protocol deploy:log
# Confirm the application is functioning on the previous version

# 4. Investigate the failed deployment
# Check application logs inside the container
protocol docker:logs

# Check the deployment audit trail
protocol deploy:log
```

### After Rollback
- Create an incident ticket documenting why the rollback was needed
- Fix the issue on a branch and go through the normal deployment process
- Do **not** attempt to re-deploy the failed version without fixing the root cause

---

## SOP 4: Blue-Green (Shadow) Deployment

### When
Zero-downtime deployment is required, or you want to validate before switching traffic.

### Procedure

```bash
# 1. Initialize shadow deployment
protocol shadow:init

# 2. Build the new version in the shadow slot
protocol shadow:build

# 3. Check shadow status
protocol shadow:status

# 4. Verify the shadow deployment is healthy
# (test against the shadow port if applicable)

# 5. Promote the shadow to active
protocol shadow:start

# 6. Verify
protocol status
protocol deploy:log
```

### If Shadow Fails

```bash
# Roll back the shadow promotion
protocol shadow:rollback

# Check status
protocol shadow:status
```

---

## SOP 5: Configuration Changes

### When
Environment variables, secrets, or config files need to change.

### Procedure

```bash
# 1. Switch to the correct config branch
protocol config:switch <environment>

# 2. Edit the .env file
# (make your changes)

# 3. Encrypt the updated secrets
protocol config:init
# Select "Encrypt secrets"

# 4. Save and push
protocol config:save

# 5. On each production node, refresh the config
protocol config:refresh

# 6. Restart containers to pick up new env vars
protocol docker:compose rebuild
```

### Important
- Never commit plaintext `.env` files
- Always verify encryption worked: the `.env.enc` file should be updated, `.env` should be gitignored
- Config changes are logged to the audit trail automatically

---

## SOP 6: Emergency Hotfix

### When
A critical bug needs to be fixed immediately outside the normal release cycle.

### Procedure

```bash
# 1. Create a hotfix branch from the current production tag
git checkout -b hotfix/critical-fix v1.2.0

# 2. Make the fix, commit, push

# 3. Create a patch release
protocol release:create
# This creates v1.2.1

# 4. Deploy immediately
protocol deploy

# 5. Verify
protocol status
protocol security:audit

# 6. Merge the hotfix back to main
git checkout main
git merge hotfix/critical-fix
git push
```

### After Hotfix
- Create a post-incident review ticket
- Ensure the fix is in the main branch
- Verify the next regular release includes the hotfix

---

## SOP 7: Key Rotation

See [Key Rotation Procedure](key-rotation.md) for the full process.

Quick reference:

```bash
protocol config:init         # Decrypt with old key
protocol secrets:setup       # Generate new key
protocol config:init         # Re-encrypt with new key
protocol config:save         # Push to config repo
# Distribute key to all nodes via:
protocol secrets:key --scp=deploy@node
# Restart each node
protocol stop && protocol start
```

---

## SOP 8: Security Incident Response

See [Incident Response Runbook](incident-response.md) for the full process.

Quick reference:

```bash
# Detect
protocol security:audit
protocol soc2:check
protocol security:trojansearch

# Contain
protocol stop                    # Stop compromised node
protocol deploy:rollback         # Roll back to known-good version

# Recover
protocol start                   # Restart from clean state
protocol security:audit          # Verify clean
```

---

## Deployment Checklist (Print-Friendly)

Use this checklist for every production deployment:

```
Pre-Deployment:
  [ ] CI pipeline green
  [ ] PR approved
  [ ] Release tag created
  [ ] Security scan passed
  [ ] Config changes encrypted and pushed (if applicable)

Deployment:
  [ ] protocol deploy executed
  [ ] protocol status shows all green
  [ ] protocol deploy:log shows success
  [ ] Application health check passes

Post-Deployment:
  [ ] Notify team of deployment
  [ ] Monitor for 15 minutes
  [ ] Check error rates in application logs
  [ ] Verify SIEM dashboard shows no new alerts
```

---

## Schedule

| Activity | Frequency | Command |
|----------|-----------|---------|
| Security audit | Every deployment + weekly | `protocol security:audit` |
| SOC 2 check | Every deployment | `protocol soc2:check` |
| Key rotation | Quarterly (90 days) | See [key-rotation.md](key-rotation.md) |
| Dependency audit | Weekly (automated via CI) | `composer audit` |
| SIEM review | Daily | Wazuh dashboard |
| Incident response drill | Annually | See [incident-response.md](incident-response.md) |
