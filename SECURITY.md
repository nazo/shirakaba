# Security policy

Shirakaba implements security-sensitive code itself instead of taking it from libraries: HTTP
message parsing, JWT verification, sessions and CSRF protection, an SSRF-safe HTTP client, an
SMTP client, a Redis client and SQL generation. Reports about any of it are welcome.

## Reporting a vulnerability

Please do not open a public issue for a vulnerability. Report it privately through GitHub:
open the repository's **Security** tab and choose **Report a vulnerability**. Include the
affected package and version or commit, what an attacker can do, and the smallest input or
code that shows it.

Once a fix is released, the report is published as a security advisory that credits you,
unless you prefer otherwise.

## Supported versions

Shirakaba is an experiment and has not had a stable release. Only the latest tagged version and
the `main` branch receive fixes.

## What counts

A report should show how someone with less privilege (a client, another tenant, a server the
application calls, a network attacker) reaches something the framework is meant to protect.
Missing hardening without such a path, and problems that need the application's own code or
configuration to be hostile, are ordinary issues.
