# Security policy

Slipway generates files that build, test and deploy software, so security reports are taken seriously.

## Reporting a vulnerability

Please **do not** open a public issue or pull request for a security problem.

Report it privately through GitHub: **Security → Report a vulnerability** on
<https://github.com/usamamuneerchaudhary/laravel-slipway/security/advisories/new>.

Include what you found, the smallest configuration that reproduces it, and the impact you expect. You should get an
acknowledgement within a few days. Fixes are released as quickly as is safe, and reporters are credited in the
advisory unless they prefer otherwise.

## Supported versions

Only the latest released minor version receives security fixes while the project is pre-1.0.

## What counts as a vulnerability

Examples: a configuration that causes a secret value to appear in generated YAML, logs or findings; shell or YAML
injection through a configuration value; a generated pipeline that is less restrictive than documented (for example
broader token permissions, or disabled host-key checking); path traversal when writing generated files.

## Design notes for reviewers

* Secrets are referenced by name; the scanner's findings never include the matched text.
* Values interpolated into shell (hosts, users, paths, URL paths, commands) are validated or quoted.
* The GitHub driver rejects `${{ }}` expressions inside `run` scripts, uses least-privilege `permissions`, and sets
  `persist-credentials: false`.
* SSH deploys use `StrictHostKeyChecking=yes` against a host-key secret.
* File writes refuse absolute paths and `..` segments.
