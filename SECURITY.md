# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 2.x     | ✓         |
| 1.x     | security fixes only |

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.

Send a description of the vulnerability to **support@laravelsmartocr.com** with:
- A description of the issue and potential impact
- Steps to reproduce
- Affected versions
- Any suggested fix

We aim to respond within 72 hours and to release a fix within 14 days for critical issues.

## Security Considerations

- Cloud and LLM drivers transmit document contents to third-party APIs. Use Tesseract or the
  PDF driver for sensitive documents that must stay on-premise.
- API keys are read from config and never logged or included in exception messages.
- The `RemoteDocumentResolver` blocks private, loopback, link-local and cloud-metadata IP ranges
  by default. Remote URL fetching is disabled unless explicitly enabled in config.
