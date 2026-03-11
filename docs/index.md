# Protocol Documentation

Protocol is a CLI tool for continuous deployment and configuration management of highly available PHP applications. It manages git-based deployment, Docker orchestration, and environment-specific configuration through a simple command-line interface.

## Documentation

| Document | Description |
|---|---|
| [Architecture Overview](architecture.md) | System design, components, data flow, namespace structure, and known architectural issues |
| [Installation Guide](installation.md) | System requirements, quick install, manual install, platform notes, production node setup |
| [Command Reference](commands.md) | Complete reference for all CLI commands with arguments, options, and behavior |
| [Configuration Reference](configuration.md) | `protocol.json` schema, config repo pattern, environment branching, symlink mechanics |
| [Security & SOC2 Compliance](security.md) | SOC2 Type II mapping, vulnerability inventory, hardening checklist, audit logging guidance |
| [Troubleshooting](troubleshooting.md) | Common issues and fixes for config, slave mode, Docker, git, and permissions |

## Quick Start

```bash
# Install
sudo curl -L "https://raw.githubusercontent.com/merchantprotocol/protocol/master/bin/install" | bash

# Set your environment
protocol config:env localhost-yourname

# Initialize a project
cd /path/to/your/repo
protocol init

# Start everything (slave mode + Docker + config)
protocol start

# Check status
protocol status
```

## Key Concepts

- **Slave Mode** — Polls a remote git branch and auto-deploys changes to the local node
- **Config Repository** — A separate git repo that stores environment-specific files (`.env`, nginx configs, etc.) with branches for each environment
- **Config Linking** — Symlinks config files from the config repo into the application directory
- **Environment** — A named configuration context (e.g., `production`, `staging`, `localhost-dev`) mapped to a config repo branch

## Support

For issues and feature requests, visit the [GitHub repository](https://github.com/merchantprotocol/protocol).
