# Troubleshooting

Something not working? Start here. These are the issues people actually run into, in the order they usually run into them.

## "First initialize this repository..."

You ran a Protocol command but it can't find `protocol.json` in the current directory.

**Fix:** Make sure you're in your project's root directory, then run `protocol init` if you haven't already.

```bash
cd /path/to/your/project
protocol init
```

---

## Config Symlinks Don't Work Inside Docker

Protocol creates relative symlinks that point to `../project-config/`. If Docker doesn't know about that directory, the symlinks point to nothing.

**Fix:** Mount the config directory as a volume in `docker-compose.yml`:

```yaml
volumes:
  - '.:/var/www/html:rw'
  - '../myproject-config/:/var/www/myproject-config:rw'
```

The config directory needs to be a sibling to your project inside the container, matching the relative symlink paths.

---

## Secrets Won't Decrypt

### "No key found" or decryption fails silently

The encryption key isn't on this machine.

**Fix:** Get the key from wherever it lives (your dev machine, your password manager, a teammate) and install it:

```bash
protocol secrets:setup "your-64-character-hex-key"
```

Or if someone else has the key and can SCP it to you:

```bash
# On the machine that has the key:
protocol secrets:key --scp=you@this-machine
```

### Wrong key

If you have a key but decryption produces garbage, you have the wrong key. The key must match the one that was used to encrypt.

**Fix:** Get the correct key from whoever encrypted the secrets. There's no way to recover — AES-256-GCM doesn't guess.

---

## Docker Issues

### Containers Don't Start

**Check Docker is running:**
```bash
docker compose version
```

If that fails, Docker isn't installed or isn't running.

**Check your docker-compose.yml:**
```bash
docker compose config
```

If that shows errors, fix your compose file first.

### "Container name not found" for exec/logs

Protocol needs to know which container to target.

**Fix:** Add `container_name` to your `protocol.json`:

```json
{
    "docker": {
        "container_name": "my-app-container"
    }
}
```

### Containers Don't Survive Reboots

**Fix:** Make sure Protocol is set to restart on reboot, and Docker starts on boot:

```bash
protocol cron:add
sudo systemctl enable docker
```

---

## Watcher/Slave Mode Issues

### Changes Aren't Being Picked Up

The watcher might have died quietly while its PID is still recorded.

**Fix:**
```bash
# See what's actually running
protocol status

# Restart everything
protocol stop
protocol start
```

### "Slave mode is already running"

A previous watcher is still alive, or the PID recorded in NodeConfig is stale.

**Fix:**
```bash
# Try stopping gracefully
protocol git:slave:stop        # for branch mode
protocol deploy:slave:stop     # for release mode

# If that doesn't work, kill it manually
ps aux | grep -E "git-repo-watcher|release-watcher"
kill <pid>

# Restart (Protocol will reset stale state in NodeConfig automatically)
protocol start
```

### Slave Mode Stops After Local Changes

By design. The watcher pauses when it detects uncommitted local changes to avoid overwriting your work. On a production node, there shouldn't be local changes.

**Fix:** Reset the local state and restart:

```bash
git checkout .
git clean -fd
protocol start
```

---

## Git Issues

### "Not a git repository"

You're not in a git repo. Protocol needs git to do almost everything.

**Fix:** Either `cd` into your git repo, or tell Protocol where it is:

```bash
protocol status --dir=/path/to/your/repo
```

### Git Pull Fails or Hangs

Usually an SSH key issue.

**Fix:**
```bash
# Test SSH access
ssh -T git@github.com

# If it fails, generate a deploy key
protocol key:generate
# Add the public key to GitHub as a deploy key

# Test again
git -C /path/to/repo fetch origin
```

### "No GitHub CLI found"

Release-based deployment needs the `gh` CLI for managing repository variables.

**Fix:**
```bash
# macOS
brew install gh

# Ubuntu/Debian
sudo apt install gh

# Then log in
gh auth login
```

---

## Permission Issues

### "Permission denied" Running Protocol

The binary isn't executable.

**Fix:**
```bash
chmod +x /path/to/protocol/protocol
```

### Can't Write Config Files

Protocol's own config file has restrictive permissions.

**Fix:**
```bash
chmod 755 /path/to/protocol/config/config.php
```

### "Unable to create a git repo" During config:init

You don't have write permissions in the parent directory.

**Fix:** Check permissions:
```bash
ls -la $(dirname $(pwd))
```

---

## Protocol Commands Hang

A lock file might be blocking. Protocol uses locks to prevent concurrent execution of certain commands.

**Fix:**
```bash
# Check for stale lock files
ls /tmp/sf.* 2>/dev/null

# Remove them (only if you're sure no other Protocol process is running)
rm /tmp/sf.*
```

---

## Release Watcher Not Deploying

### Check the basics

```bash
# Is the watcher running?
protocol status

# What does it think the active release is?
protocol deploy:status

# Any errors in the log?
protocol deploy:log
```

### "Variable not found"

The GitHub CLI can't access your repository variables.

**Fix:**
```bash
# Make sure gh is authenticated
gh auth status

# Make sure it can see your repo
gh repo view
```

---

## Config File Errors on Startup

Protocol's `config/config.php` might be empty or malformed.

**Fix:** Make sure it contains valid PHP:

```php
<?php return array(
    'env' => 'your-environment-name',
);
```

Or just set your environment again:

```bash
protocol config:env your-environment
```

---

## Still Stuck?

```bash
# List all available commands
protocol list

# Get help on a specific command
protocol <command> --help

# Check everything
protocol status

# Turn on maximum verbosity for debugging
protocol <command> -vvv
```

For bug reports or feature requests, visit the [GitHub repository](https://github.com/merchantprotocol/protocol).
