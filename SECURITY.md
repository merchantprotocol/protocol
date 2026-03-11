# Security Policy

## Supported Versions

| Version | Supported |
|---|---|
| 0.3.x | Yes |
| < 0.3 | No |

## Reporting a Vulnerability

We take security seriously. If you discover a security vulnerability in Protocol, please report it responsibly.

### Private Disclosure (Preferred)

For critical or high-severity vulnerabilities, please use private disclosure:

1. **Email:** Send details to security@merchantprotocol.com
2. **GitHub Security Advisories:** Use the [Security Advisories](https://github.com/merchantprotocol/protocol/security/advisories) feature to report privately

### What to Include

- Description of the vulnerability
- Steps to reproduce
- Affected files and versions
- Potential impact assessment
- Suggested remediation (if any)

### What to Expect

- **Acknowledgment:** Within 48 hours of your report
- **Initial Assessment:** Within 5 business days
- **Resolution Timeline:** Critical issues targeted within 30 days; others based on severity
- **Credit:** We will credit reporters in the fix release notes (unless you prefer anonymity)

### Public Disclosure

For low-severity issues or suggestions, you may open a [GitHub issue](https://github.com/merchantprotocol/protocol/issues/new?template=security_vulnerability.md) using the security vulnerability template.

## Security Practices

### For Users

- Never store credentials in `protocol.json` — use environment variables or a secrets manager
- Keep config repositories private with restricted access
- Enable branch protection on production branches
- Use SSH key authentication exclusively
- Regularly rotate SSH keys and access tokens
- Monitor `protocol status` for unexpected state changes
- Review the [Security & SOC 2 Ready](docs/security.md) documentation

### For Contributors

- Use `escapeshellarg()` for all variables interpolated into shell commands
- Validate and sanitize user inputs (paths, branch names, environment names)
- Never commit credentials, tokens, or secrets
- Follow the security checklist in the PR template
- Report any security concerns found during code review

## Known Issues

See [docs/security.md](docs/security.md) for a current inventory of known security considerations and the SOC 2 readiness hardening checklist.
