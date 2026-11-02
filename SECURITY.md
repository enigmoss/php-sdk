# Security policy

## Supported versions

Security fixes are released for the latest minor version of the current major release.

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Email **security@example.com** with:

- a description of the problem and its impact,
- steps or a minimal script to reproduce it,
- the SDK version, PHP version and the framework (if any).

You will get an acknowledgement within a few working days. We will keep you informed while the issue is fixed, publish a patched release and credit you in the changelog if you wish.

Never include real API keys in a report. If you think a key leaked, rotate it in the dashboard right away.

## Handling API keys

- Keep keys in environment variables or a secrets manager, never in source control.
- Always pass keys to the SDK wrapped in `Enigmoss\Secret`.
- Use only the keys you need. Keep the payout key out of web-facing code.
- Rotate a key if you suspect it leaked. After rotating the payment key, verify webhooks with both the old and the new secret until the rotation is complete.
- Do not log request or response bodies.

More in [docs/security.md](docs/security.md).
