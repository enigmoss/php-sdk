# Upgrade guide

This file describes what you need to change when you upgrade across a **major** version. Minor and patch releases are backward compatible: see [Versioning and BC](docs/versioning-and-bc.md) and the [changelog](CHANGELOG.md).

There are no breaking changes yet: 1.x is the first major line.

## Template for future majors

### From 1.x to 2.0

- **Requirements.** New minimum PHP version, if any.
- **Removed or renamed.** Classes, methods and request parameters that changed, each with its replacement.
- **Behaviour changes.** Defaults that changed.
- **Steps.** A checklist, in order, to migrate.

Before upgrading, run your test suite against the latest 1.x release with deprecation notices enabled and fix them.
