# Contributing

This is the Moodle activity plugin for Skilland (`mod_skilland`). It lives in its own
repository (`Skilland-ai/skilland-lms-moodle`) and is developed as a normal Moodle plugin:
the actual plugin code is `src/` (installed as `mod/skilland`), the repository root holds
tooling (npm scripts, CI, PHPUnit stub harness).

## Setup

```bash
git clone git@github.com:Skilland-ai/skilland-lms-moodle.git
cd skilland-lms-moodle
npm ci
composer install   # dev tooling only (PHPUnit, php-jwt for the stub harness); the plugin ships no vendor code
```

See `DEVELOPMENT.md` for wiring the plugin up against a local Skilland stack (API key,
organization id, SSO secret) and `BACKUP.md` for the backup/restore contract.

### Running tests locally

- **Stub PHPUnit suite** (`tests/phpunit/`, no Moodle install needed):
  ```bash
  npm run test:unit
  ```
  This runs plugin classes and functions against lightweight stubs of Moodle's globals
  (`tests/phpunit/stubs/`). It's fast and is what pre-commit / CI's `Lint, PHP syntax &
  PHPUnit` job runs.

- **Real Moodle PHPUnit and Behat suites** (`src/tests/`), via
  [moodle-plugin-ci](https://moodlehq.github.io/moodle-plugin-ci/):
  ```bash
  composer create-project -n --no-dev --prefer-dist moodlehq/moodle-plugin-ci ci ^4
  export PATH="$(cd ci/bin; pwd):$(cd ci/vendor/bin; pwd):$PATH"
  moodle-plugin-ci install --plugin ./src --db-host=127.0.0.1
  moodle-plugin-ci phpunit --fail-on-warning
  moodle-plugin-ci behat --profile chrome
  ```
  This is what the `Moodle Plugin CI` workflow runs, against a matrix of PHP/Moodle/database
  combinations. It also runs the lint job:
  ```bash
  moodle-plugin-ci phpcs --max-warnings 0
  moodle-plugin-ci phpdoc --max-warnings 0
  moodle-plugin-ci validate
  ```

- **ESLint + AMD build freshness + Node harness for the AMD modules**:
  ```bash
  npm run build
  npm run lint
  npm run test:e2e:unit
  ```

- **PHP syntax check** across the whole plugin:
  ```bash
  find src cli scripts -name "*.php" -print0 | xargs -0 -n1 php -l
  ```

## Code style

- Every PHP file under `src/` carries the standard Moodle GPL header and a
  `@package mod_skilland` / `@copyright` / `@license` docblock — see any existing file for
  the exact boilerplate, or `moodle-plugin-ci phpcs` will catch a missing one.
- Short array syntax (`[]`), not `array()`.
- No trailing whitespace.
- New UI strings go through `src/lang/en/skilland.php` (and `es/skilland.php` for the
  Spanish translation) — never hardcoded in PHP, JavaScript or Mustache templates.

## Branches and pull requests

- Branch from `main`: `skl-<issue-number>/<kebab-title>` (e.g. `skl-691/gpl-headers-phpdoc-plugin-ci`),
  matching the corresponding Linear issue in the `SKL` team.
- Conventional commits with an optional scope, e.g. `feat(plugin): ...`, `fix(plugin): ...`,
  `test: ...`, `chore: ...`, `refactor: ...`, `docs: ...`, `ci: ...`. Reference the issue in
  the message (`(SKL-N)`).
- **Bump `$plugin->version` and, when the change is user-visible, `$plugin->release` in
  `src/version.php` exactly once per PR.** CI's `version` job (`scripts/check_version.js`)
  fails the PR otherwise. `$plugin->version` uses the `YYYYMMDDHH` convention already in the
  file. Tooling-only PRs (nothing changed under `src/` or the shipped `cli/` files) need no bump:
  the check passes and `release.yml` skips the release for them.
- Update `CHANGELOG.md` under an `## [Unreleased]` or the new version heading when the change
  is user-visible.
- Keep the AMD build output in `src/amd/build/` in sync with `src/amd/src/` (`npm run build`);
  CI's `AMD build freshness` step fails a PR that forgets this.
- Open the PR against `main`. CI (`ci.yml` and `moodle-plugin-ci.yml`) must be green before
  merge; a push to `main` also runs `release.yml`, which tags and publishes a GitHub release
  built from the bumped version.
