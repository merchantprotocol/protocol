# Installation Guide

## System Requirements

| Requirement | Minimum Version | Purpose |
|---|---|---|
| PHP | 8.1+ | Protocol runtime |
| Git | 2.x | Repository management |
| Docker | 20.x+ | Container orchestration |
| Docker Compose | v2+ | Multi-container management |
| Composer | 2.x | PHP dependency management (bundled with Protocol) |

## Quick Install

Run the install script which handles platform detection and dependency installation:

```bash
sudo curl -L "https://raw.githubusercontent.com/merchantprotocol/protocol/master/bin/install" | bash
```

This script supports:
- **macOS** — Uses Homebrew for dependencies
- **Ubuntu/Debian** — Uses apt
- **Amazon Linux** — Uses yum

Verify the installation:

```bash
protocol -v
```

## Manual Install

If you prefer to install manually or the install script doesn't work for your platform:

```bash
# Clone the repository
git clone https://github.com/merchantprotocol/protocol.git $HOME/protocol

# Make the binary executable
chmod +x $HOME/protocol/protocol

# Install PHP dependencies
cd $HOME/protocol && php bin/composer.phar install --ignore-platform-reqs

# Make protocol globally available
protocol self:global
```

Or manually create a symlink:

```bash
sudo ln -s $HOME/protocol/protocol /usr/local/bin/protocol
```

## Post-Installation Setup

### 1. Set Your Environment

```bash
protocol config:env localhost-yourgithubhandle
```

This sets the global environment name for this machine. See [Configuration](configuration.md) for naming conventions.

### 2. Generate SSH Keys (for deployment)

If this machine will be pulling from private repositories:

```bash
protocol key:generate
```

This creates an ed25519 key at `~/.ssh/id_ed25519_ContinuousDeliverySystem` and outputs the public key. Add the public key to your GitHub/GitLab account as a deploy key.

### 3. Initialize Your Project

Navigate to your project repository and run:

```bash
cd /path/to/your/project
protocol init
```

This creates `protocol.json` with your project's git and Docker settings. Commit this file to your repository.

### 4. Initialize Configuration Repository (Optional)

If you want to manage environment-specific config files:

```bash
protocol config:init
```

This creates a sibling directory (`yourproject-config/`) with its own git repository. See [Configuration](configuration.md) for details.

## Setting Up a Production Node

### First-Time Setup

```bash
# 1. Clone your application repository
git clone git@github.com:org/myapp.git /opt/myapp
cd /opt/myapp

# 2. Set the environment
protocol config:env production

# 3. Start everything
protocol start
```

`protocol start` will:
- Pull latest code from the remote
- Set up the config repository and link config files
- Start slave mode (continuous deployment)
- Pull the Docker image and start containers
- Install composer dependencies

### Surviving Reboots

Add Protocol to crontab so it restarts automatically after a server reboot:

```bash
protocol cron:add
```

This adds: `@reboot php /path/to/protocol restart /path/to/repo`

You can verify with:

```bash
protocol status
```

### Stopping a Node

```bash
protocol stop
```

This stops slave mode, unlinks config files, brings down Docker containers, and removes the crontab entry.

## Updating Protocol

Protocol can update itself:

```bash
protocol self:update
```

This fetches the latest changes from the Protocol repository and resets to the latest version.

## Uninstalling

```bash
# Remove the global symlink
sudo rm /usr/local/bin/protocol

# Remove the protocol directory
rm -rf $HOME/protocol

# Remove crontab entries (run from each managed project)
protocol cron:remove
```

## Platform Notes

### macOS

- Docker Desktop must be installed and running
- Homebrew is used for PHP and Git installation
- Protocol works with both Intel and Apple Silicon Macs

### Ubuntu/Debian

- The install script adds the ondrej/php PPA for PHP 8.1
- Docker is installed from the official Docker repository
- You may need to add your user to the `docker` group: `sudo usermod -aG docker $USER`

### Amazon Linux

- Uses amazon-linux-extras for Docker
- PHP is installed from the amzn2-core repository
- Docker service needs to be enabled: `sudo systemctl enable docker`

### Windows (WSL2)

Protocol is not tested on native Windows. Use WSL2 with Ubuntu for Windows development. Ensure Docker Desktop is configured to use the WSL2 backend.
