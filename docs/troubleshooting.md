# Troubleshooting

Common issues and their solutions when working with Protocol.

## Configuration Issues

### "First initialize this repository to work with protocol by running `protocol init`"

**Cause:** You ran a config command but `protocol.json` doesn't exist in the current directory.

**Fix:**
```bash
cd /path/to/your/repo
protocol init
```

### Config file errors on startup

**Cause:** `config/config.php` is empty or doesn't return an array. This happens when Protocol creates the file with `touch()` but it never gets populated.

**Fix:** Ensure the file contains a valid PHP array:
```php
<?php return array(
    'env' => 'your-environment-name',
);
```

Or set your environment which will create it properly:
```bash
protocol config:env your-environment
```

### Config symlinks not working inside Docker

**Cause:** Protocol creates relative symlinks that point to `../project-config/`. If the config directory isn't mounted in Docker, the symlinks resolve to nothing.

**Fix:** Add the config directory as a volume in `docker-compose.yml`:
```yaml
volumes:
  - '.:/var/www/html:rw'
  - '../myproject-config/:/var/www/myproject-config:rw'
```

The config directory must be mounted as a sibling to the application directory, matching the relative symlink paths.

### "Unable to create a git repo" during config:init

**Cause:** Permission issue creating the config directory.

**Fix:** Check that you have write permissions in the parent directory of your project:
```bash
ls -la $(dirname $(pwd))
```

## Slave Mode Issues

### Slave mode shows as running but changes aren't being pulled

**Cause:** The PID in `protocol.lock` may reference a dead process (PID recycling), or the git-repo-watcher script encountered an error.

**Fix:**
```bash
# Check if the process is actually running
protocol status

# Stop and restart slave mode
protocol git:slave:stop
protocol git:slave

# Check the background process log
cat ~/protocol_background_process.log
```

### "Slave mode is already running"

**Cause:** A previous watcher process is still running or its PID is stale in `protocol.lock`.

**Fix:**
```bash
# Stop the existing slave
protocol git:slave:stop

# If that doesn't work, find and kill the process manually
ps aux | grep git-repo-watcher
kill <pid>

# Clean up the lock file
# Delete protocol.lock and restart
rm protocol.lock
protocol git:slave
```

### Slave mode stops after local changes

**Cause:** By design, the git-repo-watcher detects local uncommitted changes and pauses to avoid overwriting them. If the local repository diverges from the remote, slave mode disconnects.

**Fix:** On slave/production nodes, there should be no local changes. If files were modified:
```bash
# Reset local changes (WARNING: destructive)
git checkout .
git clean -fd

# Restart slave mode
protocol git:slave
```

## Docker Issues

### "docker:compose command not found" or compose fails

**Cause:** Protocol tries both `docker compose` (v2) and `docker-compose` (v1). If neither works, Docker Compose may not be installed.

**Fix:**
```bash
# Check which is available
docker compose version
docker-compose --version

# Install Docker Compose v2 (comes with Docker Desktop)
# Or install standalone:
sudo apt-get install docker-compose-plugin
```

### Container name not found for exec/logs

**Cause:** Protocol reads the container name from `protocol.json` (`docker.container_name`) or from `docker-compose.yml`. If neither is set, it can't determine which container to target.

**Fix:** Add `container_name` to your `protocol.json`:
```json
{
    "docker": {
        "container_name": "my-app-container"
    }
}
```

Or ensure your `docker-compose.yml` defines a `container_name` for the service.

### Docker containers don't start after reboot

**Cause:** The crontab restart entry may not be installed, or Docker isn't starting before Protocol.

**Fix:**
```bash
# Ensure crontab entry exists
protocol cron:add

# Ensure Docker starts on boot
sudo systemctl enable docker

# Manually restart
protocol restart /path/to/repo
```

## Git Issues

### "Not a git repository" errors

**Cause:** Protocol commands default to the current directory for the git repo. If you're not in a git repository, commands will fail.

**Fix:** Either `cd` into your git repository, or use the `--dir` option:
```bash
protocol status --dir=/path/to/your/repo
```

### git:pull fails or hangs

**Cause:** SSH key authentication may not be configured, or the remote is unreachable.

**Fix:**
```bash
# Test SSH access
ssh -T git@github.com

# If key isn't configured
protocol key:generate
# Add the output public key to GitHub

# Test manual pull
git -C /path/to/repo fetch origin
```

### Merge conflicts in slave mode

**Cause:** Slave mode does a hard reset (`git reset --hard`), so true merge conflicts shouldn't happen. If they do, the local repo may be in a corrupted state.

**Fix:**
```bash
protocol git:slave:stop
git -C /path/to/repo reset --hard origin/master
protocol git:slave
```

## Process Issues

### Protocol commands hang or time out

**Cause:** A locked process may be blocking. Protocol uses `LockableTrait` on some commands to prevent concurrent execution.

**Fix:**
```bash
# Check for lock files
ls /tmp/sf.* 2>/dev/null

# Remove stale locks (be careful — only if you're sure no other protocol process is running)
rm /tmp/sf.*
```

### Multiple git-repo-watcher processes running

**Cause:** Slave mode was started multiple times without stopping, or the PID tracking in `protocol.lock` lost track of a process.

**Fix:**
```bash
# Find all watcher processes
ps aux | grep git-repo-watcher

# Kill them all
pkill -f git-repo-watcher

# Clean up and restart
rm protocol.lock
protocol git:slave
```

## Permission Issues

### "Permission denied" when running protocol

**Cause:** The protocol binary isn't executable.

**Fix:**
```bash
chmod +x /path/to/protocol/protocol
```

### Can't write to config files

**Cause:** Protocol's `config/config.php` may have restrictive permissions.

**Fix:**
```bash
chmod 755 /path/to/protocol/config/config.php
```

### Crontab operations fail silently

**Cause:** The user running protocol may not have permission to modify crontab, or cron may not be available in a container.

**Fix:**
```bash
# Check current crontab
crontab -l

# Ensure cron service is running
sudo systemctl status cron
```

## Getting Help

```bash
# List all available commands
protocol list

# Get help on a specific command
protocol <command> --help

# Check system status
protocol status

# Increase verbosity for debugging
protocol <command> -vvv
```

For bug reports or feature requests, visit the project repository on GitHub.
