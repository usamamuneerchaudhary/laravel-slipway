# Contributing to Slipway

Thanks for helping. This project aims to be small, predictable and safe, because the files it generates run your
deployments.

## Ground rules

* **Deterministic output.** The same config must always compile to the same bytes. No timestamps, randomness or
  unordered maps. The drift guard depends on it.
* **Secrets by name only.** Nothing may print, log, interpolate or store a secret value. Tests that need
  credential-looking text must assemble it at runtime so the repository itself stays clean under `slipway:scan`.
* **Generated shell is POSIX `sh`** and must run on a minimal Alpine container as well as on a GitHub runner. Quote
  everything that comes from configuration.
* **Reject, don't ignore.** Unknown options, bad types and unsafe values are errors with a config path in the message.
* **Honest capabilities.** If a provider can only partly express a feature, mark it `Partial` and explain what the
  user must do.
* **The core stays framework-free.** Only `src/Console` and `SlipwayServiceProvider` may use Illuminate.

## Development

```bash
git clone https://github.com/usamamuneerchaudhary/laravel-slipway
cd laravel-slipway
composer install
vendor/bin/pest
```

Run one suite with `vendor/bin/pest --testsuite=Unit|Conformance|Feature`.

This repository uses Slipway for its own CI. After changing `slipway.php`, regenerate the workflow:

```bash
vendor/bin/testbench slipway:compile
```

and commit both files. CI fails when they disagree.

## Pull requests

1. Open an issue first for anything bigger than a bug fix, so we can agree on the approach.
2. Add tests. A bug fix needs a test that fails without the fix. Generated scripts should be *executed* in a test
   (see `tests/Support/ShellHarness.php`) rather than only compared as strings.
3. A new provider driver must pass the conformance suite in `tests/Conformance` and be documented in
   `docs/writing-a-driver.md` terms: capabilities with honest notes, deterministic output, no inlined secrets.
4. Update `CHANGELOG.md` under "Unreleased" and the README when behaviour or configuration changes.
5. Keep commits focused. Describe the *why* in the message.

## Reporting security problems

Do not open a public issue. See [SECURITY.md](SECURITY.md).

## Code of conduct

Be kind and assume good faith. Harassment and personal attacks are not tolerated, and maintainers may remove
comments or contributions that cross that line.
