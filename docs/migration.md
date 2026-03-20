# Migrating to Release-Based Deployment

Protocol v2.0.0 introduces **release-based deployment** as the recommended strategy. This guide walks you through migrating from the legacy branch-based approach.

## Branch-Based vs Release-Based

| | Branch-Based (legacy) | Release-Based (v2.0.0+) |
|---|---|---|
| **Deploy unit** | Branch tip (latest commit) | Git tag (semver) |
| **How nodes update** | Poll branch, `git pull` | Poll GitHub variable, `git checkout tag` |
| **Rollback** | Revert commit + push | `deploy:rollback` (instant) |
| **Audit trail** | Git log only | Structured audit log (`~/.protocol/.node/deployments.log`) |
| **Version tracking** | None | VERSION file, protocol.lock, GitHub Releases |
| **Multi-node deploy** | Push to branch | Set one variable, all nodes update |

## Quick Migration

The fastest way to migrate is the interactive wizard:

```bash
protocol migrate
```

This walks you through every step and handles edge cases. The rest of this guide explains what happens under the hood.

## Manual Migration

### Step 1: Update Protocol

```bash
protocol self:update
```

Verify you're on v2.0.0+:

```bash
protocol --version
```

### Step 2: Set Deployment Strategy

Update your `protocol.json`:

```json
{
    "deployment": {
        "strategy": "release",
        "pointer": "github_variable",
        "pointer_name": "PROTOCOL_ACTIVE_RELEASE"
    }
}
```

Or use the init command to update interactively:

```bash
protocol init
# Select "Change deployment strategy" → "Release-based"
```

### Step 3: Create Your First Release

Tag the current state of your code as the first release:

```bash
protocol release:create 1.0.0
```

This will:
1. Write `1.0.0` to the `VERSION` file
2. Commit the change
3. Create a git tag `1.0.0`
4. Push to remote
5. Create a GitHub Release

### Step 4: Set the Active Release

Point all nodes to your first release:

```bash
protocol deploy:push 1.0.0
```

This sets the `PROTOCOL_ACTIVE_RELEASE` GitHub repository variable. Any running nodes will detect the change and deploy automatically.

### Step 5: Set Up Encrypted Secrets (Optional)

If you have `.env` files in your config repo, you can encrypt them:

```bash
# Generate encryption key (do this on ONE machine)
protocol secrets:setup

# Copy the displayed key to other nodes:
# protocol secrets:setup "your-hex-key-here"

# Encrypt your .env file
protocol secrets:encrypt

# Remove the plaintext .env from the config repo
rm ../myapp-config/.env

# Commit the encrypted version
protocol config:save
```

Update `protocol.json` to use encrypted secrets:

```json
{
    "deployment": {
        "secrets": "encrypted"
    }
}
```

### Step 6: Restart Nodes

On each node, restart to pick up the new strategy:

```bash
protocol stop
protocol start
```

The node will now run `deploy:slave` (release watcher) instead of `git:slave` (branch watcher).

## Verifying the Migration

After migration, check that everything is working:

```bash
# Check node status
protocol status

# Verify the active release
protocol deploy:status

# View the audit log
protocol deploy:log
```

The status output should show:
- **Deployment Strategy**: `release`
- **Current Release**: Your version tag
- **Release Watcher**: Running with a PID

## Deploying After Migration

The deployment workflow changes from push-to-branch to tag-and-deploy:

### Old Workflow (Branch-Based)

```bash
git push origin master
# Nodes auto-pull within seconds
```

### New Workflow (Release-Based)

```bash
# 1. Create a release (tags + pushes + GitHub Release)
protocol release:create

# 2. Test on staging (optional)
protocol node:deploy 1.2.3  # On staging node only

# 3. Deploy to all nodes
protocol deploy:push 1.2.3

# 4. Something wrong? Instant rollback
protocol deploy:rollback
```

## Rolling Back

Rollback is now instant and doesn't require reverting commits:

```bash
# Roll back ALL nodes
protocol deploy:rollback

# Roll back a single node (e.g. staging)
protocol node:rollback
```

The previous version is tracked in `protocol.lock`, so rollback is always one command away.

## Keeping Branch-Based Mode

If you prefer the legacy approach, you don't have to migrate. Branch-based mode continues to work in v2.0.0. Just make sure your `protocol.json` has:

```json
{
    "deployment": {
        "strategy": "branch"
    }
}
```

Or simply omit the `deployment.strategy` key — it defaults to `branch`.

## Troubleshooting

### "No GitHub CLI found"

Release-based deployment requires the `gh` CLI for managing repository variables. Install it:

```bash
# macOS
brew install gh

# Ubuntu/Debian
sudo apt install gh

# Then authenticate
gh auth login
```

### "Variable not found" errors

Make sure `gh` is authenticated with access to your repository:

```bash
gh auth status
gh repo view
```

### Nodes not picking up releases

1. Check the release watcher is running: `protocol status`
2. Check the active release: `protocol deploy:status`
3. Check the audit log for errors: `protocol deploy:log`
4. Restart the watcher: `protocol stop && protocol start`
