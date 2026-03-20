# Key Rotation Procedure

This document describes how to rotate the Protocol encryption key used for secrets management. Key rotation should be performed on a regular schedule and immediately after any suspected compromise.

---

## When to Rotate

| Trigger | Action |
|---------|--------|
| **Scheduled** | Every 90 days (quarterly) |
| **Team member leaves** | Immediately if they had key access |
| **Suspected compromise** | Immediately |
| **After an incident** | As part of remediation |
| **Audit requirement** | As specified by your compliance framework |

---

## Prerequisites

Before rotating:

- You have SSH access to all production nodes
- You have the current encryption key (or access to a node that does)
- All config changes are committed and pushed
- No deployments are in progress

---

## Rotation Steps

### Step 1: Decrypt All Secrets with the Current Key

On a machine that has the current key:

```bash
protocol config:init
# Select "Decrypt secrets" when prompted
```

This decrypts `.env.enc` files back to plaintext `.env` files in your config repo working copy.

### Step 2: Generate a New Key

```bash
protocol secrets:setup
```

This generates a new 256-bit AES key and stores it at `~/.protocol/.node/key`. The old key is overwritten.

**Important:** Copy the new key to your password manager or vault before proceeding.

### Step 3: Re-Encrypt with the New Key

```bash
protocol config:init
# Select "Encrypt secrets" when prompted
```

This encrypts all `.env` files using the new key.

### Step 4: Commit and Push Encrypted Files

```bash
protocol config:save
```

This commits the re-encrypted `.env.enc` files and pushes to the config repo.

### Step 5: Distribute the New Key to All Nodes

```bash
# Using SCP (recommended for production)
protocol secrets:key --scp=deploy@prod-node-1
protocol secrets:key --scp=deploy@prod-node-2
protocol secrets:key --scp=deploy@prod-node-3

# Or push to GitHub Secrets for CI/CD
protocol secrets:key --push
```

### Step 6: Restart All Nodes

On each node, restart to pick up the new key:

```bash
protocol stop && protocol start
```

### Step 7: Verify

On each node:

```bash
protocol soc2:check
```

Confirm that the "Encrypted secrets" check passes on every node.

---

## Rollback

If something goes wrong during rotation:

1. **If you still have the old key** — restore it to `~/.protocol/.node/key` and re-encrypt
2. **If you've already pushed new encrypted files but nodes can't decrypt** — restore the old `.env.enc` files from git history:
   ```bash
   git -C /path/to/config-repo log --oneline
   git -C /path/to/config-repo checkout <previous-commit> -- .
   ```
3. **If the old key is lost** — you must re-create all secrets manually from their original sources (database passwords, API keys, etc.)

---

## Key Storage Best Practices

| Location | Purpose |
|----------|---------|
| `~/.protocol/.node/key` on each node | Runtime decryption (permissions: `0600`) |
| Password manager (1Password, Bitwarden, etc.) | Backup and recovery |
| GitHub Secrets (`PROTOCOL_ENCRYPTION_KEY`) | CI/CD pipelines |

**Never store the key in:**
- Git repositories (even private ones)
- Slack messages or email
- Shared documents or wikis
- Plaintext files on shared drives

---

## Audit Trail

Key rotation events are automatically logged to `~/.protocol/.node/deployments.log` via the CONFIG action type. After rotation, verify the log:

```bash
protocol deploy:log
```

You should see entries like:
```
2024-06-15T10:00:00Z CONFIG repo=/opt/myapp action='encrypt' detail='encrypted 3 files' user='deploy'
```

---

## Automation

For teams managing many nodes, consider scripting the distribution:

```bash
#!/bin/bash
# rotate-key.sh — Run from a machine with the new key

NODES=(
    deploy@prod-node-1
    deploy@prod-node-2
    deploy@prod-node-3
)

for node in "${NODES[@]}"; do
    echo "Distributing key to $node..."
    protocol secrets:key --scp="$node"
done

echo "Key distributed to ${#NODES[@]} nodes."
echo "SSH into each node and run: protocol stop && protocol start"
```

---

## Compliance Notes

- SOC 2 Type II auditors will ask about your key rotation schedule
- Document each rotation in your change management log
- Keep evidence of rotation (audit log entries, git commits, ticket/issue references)
- The `protocol soc2:check` command validates that keys exist and have correct permissions, but does not track rotation frequency — maintain a separate rotation log or calendar reminder
