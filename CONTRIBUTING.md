# Contributing to Protocol

Thank you for your interest in contributing to Protocol. This document provides guidelines for contributing to this project.

## Code of Conduct

Be respectful and constructive. We are building tools that organizations depend on for production deployments.

## Getting Started

### Development Setup

```bash
# Fork and clone the repository
git clone git@github.com:your-username/protocol.git
cd protocol

# Install dependencies
php bin/composer.phar install --ignore-platform-reqs

# Make the binary executable
chmod +x protocol

# Verify it works
./protocol -v
```

### Project Structure

```
protocol/
├── protocol              # CLI entry point
├── src/
│   ├── Commands/         # CLI commands (auto-registered)
│   │   └── Init/         # Project initializer classes
│   ├── Helpers/          # Domain logic (static methods)
│   └── Utils/            # Data persistence layer
├── bin/                  # Shell scripts
├── config/               # Protocol's own configuration
├── templates/            # Template files for new projects
└── docs/                 # Documentation
```

See [docs/architecture.md](docs/architecture.md) for a detailed architecture overview.

### Namespace

All classes use the `Gitcd\` namespace (PSR-4 mapped to `src/`). This is a legacy name — use it for consistency with existing code.

## How to Contribute

### Reporting Issues

- Use the appropriate [issue template](.github/ISSUE_TEMPLATE/)
- For security vulnerabilities, follow the process in [SECURITY.md](SECURITY.md)

### Submitting Changes

1. **Fork** the repository and create a feature branch from `master`
2. **Make your changes** following the coding standards below
3. **Test** your changes locally against a real git repository
4. **Submit a pull request** using the [PR template](.github/PULL_REQUEST_TEMPLATE.md)

### Pull Request Process

1. Fill out the PR template completely, including the security checklist
2. Ensure your changes don't break existing commands
3. Update documentation in `docs/` if you change command behavior or add features
4. A maintainer will review your PR and may request changes

## Coding Standards

### PHP

- Follow PSR-12 coding style
- Use type hints where possible
- Keep methods focused — one responsibility per method
- Use meaningful variable and method names

### Security Requirements

These are non-negotiable for all contributions:

- **Shell commands:** Always use `escapeshellarg()` for variables interpolated into shell commands
- **File paths:** Validate that resolved paths stay within expected directories
- **User input:** Sanitize all input from CLI arguments, options, and interactive prompts
- **No debug code:** Never commit `var_dump()`, `print_r()`, `die()`, or `dd()` statements
- **No credentials:** Never hardcode or commit secrets, tokens, passwords, or API keys
- **Error handling:** Handle errors gracefully — don't suppress errors with `@` operator without good reason

### Commands

When adding new commands:

- Place the file in `src/Commands/` (it will be auto-registered)
- Extend `Symfony\Component\Console\Command\Command`
- Set `$defaultName` and `$defaultDescription`
- Use the `--dir` option pattern for directory specification (default to `Git::getGitLocalFolder()`)
- Add help text via `setHelp()`
- Return `Command::SUCCESS` or `Command::FAILURE`
- Document the command in `docs/commands.md`

### Helpers

When modifying or adding helpers:

- All methods should be `static`
- Delegate shell execution to `Shell::run()` or `Shell::passthru()`
- Handle missing dependencies gracefully (check if files/directories exist before operating)
- Return meaningful values — avoid returning `void` when a success/failure indicator is useful

## Testing

Protocol does not currently have an automated test suite. When testing your changes:

1. Test against a real git repository with a remote
2. Test both with and without `protocol.json` present
3. Test both with and without a config repository
4. Test with and without Docker installed
5. Verify `protocol status` reports correctly after your changes

If you're adding automated tests, we welcome PHPUnit-based test suites.

## Documentation

- Update `docs/commands.md` when adding or changing commands
- Update `docs/configuration.md` when changing config schema
- Update `docs/architecture.md` when changing system design
- Update `docs/security.md` when changes have security implications
- Keep documentation factual and concise

## Release Process

Protocol uses semantic versioning. The current version is defined in the `protocol` entry point file.

- **Patch** (0.3.x): Bug fixes, security patches
- **Minor** (0.x.0): New features, non-breaking changes
- **Major** (x.0.0): Breaking changes

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE).
