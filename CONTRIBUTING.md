# Contributing

Thanks for helping to improve the SDK.

## Setup

```bash
composer install
composer check
```

`composer check` runs the code-style check, static analysis (PHPStan), the dependency analysis and the tests. Other scripts:

| Script | What it does |
|---|---|
| `composer lint` | Code style, dry run with a diff (`composer fix` applies it). |
| `composer analyse` | PHPStan. |
| `composer deps` | Checks that dependencies used in the code are declared. |
| `composer test` | PHPUnit without the `e2e` group. |
| `composer test:e2e` | PHPUnit, end-to-end group only. |

## Test suites

Defined in `phpunit.xml.dist`:

| Suite | Directory | Needs |
|---|---|---|
| `unit` | `tests/Unit` | Nothing: uses `MockTransport`. |
| `bridge` | `tests/Bridge` | Laravel and Symfony dev dependencies (installed by `composer install`). |
| `e2e` | `tests/E2E` | A running API and a **test** project. Opt-in and skipped by default. |

```bash
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite bridge
ENIGMOSS_E2E_BASE_URL=... ENIGMOSS_E2E_PROJECT_ID=... ENIGMOSS_E2E_PAYMENT_KEY=... [ENIGMOSS_E2E_PAYOUT_KEY=...] composer test:e2e
```

The end-to-end tests create real invoices and wallets: never run them against a production project.

Add tests for every change. Use `MockTransport` and `Fixtures` for resource behaviour.

## PHP 8.1 compatibility rules

The SDK supports PHP 8.1 to 8.5 and the code must run on 8.1. Therefore **do not use**:

- `readonly class` (8.2): declare `readonly` on each property;
- DNF types such as `(A&B)|null` (8.2);
- standalone `true`, `false` or `null` types (8.2): use `bool` and `?T`;
- typed class constants (8.3): `public const string X = ...` is not allowed;
- the `#[\Override]` attribute (8.3);
- `json_validate()` (8.3): decode and check instead;
- `Random\Randomizer` (8.2): use `random_bytes()` and friends;
- anything else newer than 8.1.

PHPStan is configured with `phpVersion: 80100` to catch most of these.

## Dependencies

The SDK has **no runtime dependencies** beyond `ext-json`. Do not add `require` entries. Code outside `src/Bridge` must not import classes from any package. Framework and PSR integrations live only in `src/Bridge` and are optional (listed under `suggest`); their packages are `require-dev` only. `composer deps` and the architecture test enforce this.

## Style and API

- Follow the existing style; `composer fix` applies the formatter.
- Public API changes follow [Versioning and BC](docs/versioning-and-bc.md): keep constructor parameter names of request DTOs stable, mark internals `@internal`, keep new enum cases additive.
- Documentation is in English. Examples use the placeholder host `https://api.example.com`.
- Update `CHANGELOG.md` under `## [Unreleased]`.

## Pull requests

Keep changes focused, explain the problem and the solution, and make sure `composer check` passes.
