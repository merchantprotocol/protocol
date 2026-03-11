# Protocol Documentation

Protocol is a CLI tool for continuous deployment and configuration management of highly available PHP applications. It manages git-based deployment, Docker orchestration, and environment-specific configuration through a simple command-line interface.

## Documentation

| Document | Description |
|---|---|
| [Getting Started](getting-started.md) | Start here — install, init, config, deploy, all explained step by step |
| [Deployment Strategies](deployment-types.md) | Branch, release, and shadow mode — how they work and when to use each |
| [Architecture Overview](architecture.md) | System design, components, data flow, namespace structure, and known architectural issues |
| [Installation Guide](installation.md) | System requirements, quick install, manual install, platform notes, production node setup |
| [Command Reference](commands.md) | Complete reference for all CLI commands with arguments, options, and behavior |
| [Configuration Reference](configuration.md) | `protocol.json` schema, config repo pattern, environment branching, symlink mechanics |
| [Secrets Management](secrets.md) | Encrypting secrets, transferring keys, auto-decryption on deploy, GitHub Actions integration |
| [SOC 2 Ready](soc2.md) | Trust Service Criteria mapping, automated checks, hardening checklist, auditor-ready documentation |
| [SOC 2 Controls Matrix](soc2-controls-matrix.md) | Auditor-facing evidence matrix — every numbered SOC 2 criterion mapped to controls and evidence |
| [Security & Hardening](security.md) | Security controls, encryption internals, audit log format, vulnerability scanning |
| [Shadow Deployment](blue-green.md) | Zero-downtime blue-green deploys with instant rollback |
| [Incident Response](incident-response.md) | Severity levels, detection, triage, containment, resolution, post-incident review |
| [Key Rotation](key-rotation.md) | Step-by-step key rotation procedure, rollback plan, automation |
| [Deployment SOPs](deployment-sops.md) | Standard operating procedures for deploys, rollbacks, hotfixes, and incidents |
| [Migration Guide](migration.md) | Upgrade path from branch-based to release-based deployment |
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
