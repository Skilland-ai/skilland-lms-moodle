/**
 * Vendor drift guard for the committed `vendor/` directory.
 *
 * `vendor/composer/*` is committed (production autoload wiring for firebase/php-jwt),
 * but the rest of `vendor/` is a build artifact of `composer install`. If anything ever
 * writes a dev install (phpunit and its transitive deps) into the committed `vendor/`
 * directory — for example an older or bypassed `test:unit` that installed with dev
 * dependencies enabled — the committed `vendor/composer/*` files drift to reference
 * packages that aren't actually on disk (their directories are gitignored), which is
 * fatal in production.
 *
 * This script re-runs a clean `composer install --no-dev` into a throwaway vendor
 * directory inside the repo (Docker only mounts the repo root, so the throwaway dir
 * must be a relative path under it, never /tmp) and compares it against the committed
 * `vendor/composer/*`:
 *   - the autoload maps that use only `dirname(__DIR__)`-relative paths
 *     (autoload_classmap.php, autoload_psr4.php, autoload_namespaces.php,
 *     autoload_files.php, installed.php) are compared byte-for-byte;
 *   - autoload_real.php / autoload_static.php embed a hash derived from the vendor
 *     directory's own path, which legitimately differs between "vendor" and a
 *     throwaway directory name, so the `ComposerAutoloaderInit<hash>` /
 *     `ComposerStaticInit<hash>` tokens are normalized before comparing them;
 *   - installed.json is compared by its set of installed package names + versions,
 *     not byte-for-byte, since registry metadata (source URLs) can shift between
 *     requests independent of what's actually installed.
 * Any of those checks failing means the committed vendor/ was built with something a
 * clean --no-dev install would not produce — almost always dev dependencies.
 *
 * SKIP_VENDOR_DRIFT_CHECK=1 skips this check.
 */
const fs = require('fs')
const path = require('path')
const { execFileSync } = require('child_process')

if (process.env.SKIP_VENDOR_DRIFT_CHECK) {
  console.log('SKIP_VENDOR_DRIFT_CHECK set. Skipping vendor drift check.')
  process.exit(0)
}

const REPO_ROOT = path.resolve(__dirname, '..')
const COMMITTED_DIR = path.join(REPO_ROOT, 'vendor', 'composer')
const THROWAWAY_DIR_NAME = '.vendor-drift-check'
const THROWAWAY_DIR = path.join(REPO_ROOT, THROWAWAY_DIR_NAME)
const FRESH_COMPOSER_DIR = path.join(THROWAWAY_DIR, 'composer')

// Files whose content is only ever built from dirname(__DIR__)-relative paths:
// safe to compare byte-for-byte regardless of the vendor directory's own name.
const EXACT_MATCH_FILES = [
  'autoload_classmap.php',
  'autoload_psr4.php',
  'autoload_namespaces.php',
  'autoload_files.php',
  'installed.php',
]

// Files that embed a hash derived from the vendor directory's path: normalize the
// hash before comparing.
const HASHED_FILES = ['autoload_real.php', 'autoload_static.php']
const HASH_PATTERN = /Composer(?:Autoloader|Static)Init[0-9a-f]{32}/g

function rmThrowaway() {
  fs.rmSync(THROWAWAY_DIR, { recursive: true, force: true })
}

function readIfExists(file) {
  return fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : null
}

function normalizeHashed(content) {
  return content.replace(HASH_PATTERN, (m) => m.replace(/[0-9a-f]{32}$/, '<hash>'))
}

// Sorted "name@version" pairs from installed.json's package list.
function packageVersionsFromInstalledJson(file) {
  const data = JSON.parse(fs.readFileSync(file, 'utf8'))
  const packages = Array.isArray(data) ? data : data.packages || []
  return packages.map((p) => `${p.name}@${p.version}`).sort()
}

const errors = []

try {
  rmThrowaway()

  execFileSync(
    'docker',
    [
      'run',
      '--rm',
      '-v',
      `${REPO_ROOT}:/app`,
      '-w',
      '/app',
      '--user',
      `${process.getuid()}:${process.getgid()}`,
      '-e',
      'COMPOSER_HOME=/tmp/composer-cache',
      '-e',
      `COMPOSER_VENDOR_DIR=${THROWAWAY_DIR_NAME}`,
      'composer:2',
      'install',
      '--no-dev',
      '--no-interaction',
      '--quiet',
    ],
    { stdio: 'inherit' },
  )

  if (!fs.existsSync(FRESH_COMPOSER_DIR)) {
    errors.push(`Expected ${THROWAWAY_DIR_NAME}/composer to exist after a clean composer install`)
  } else {
    for (const name of EXACT_MATCH_FILES) {
      const committed = readIfExists(path.join(COMMITTED_DIR, name))
      const fresh = readIfExists(path.join(FRESH_COMPOSER_DIR, name))
      if (committed !== fresh) {
        errors.push(`vendor/composer/${name} differs from a clean --no-dev install`)
      }
    }

    for (const name of HASHED_FILES) {
      const committed = readIfExists(path.join(COMMITTED_DIR, name))
      const fresh = readIfExists(path.join(FRESH_COMPOSER_DIR, name))
      if (committed === null || fresh === null) {
        if (committed !== fresh) errors.push(`vendor/composer/${name} differs from a clean --no-dev install`)
        continue
      }
      if (normalizeHashed(committed) !== normalizeHashed(fresh)) {
        errors.push(`vendor/composer/${name} differs from a clean --no-dev install (beyond its directory-derived hash)`)
      }
    }

    const committedInstalledJson = packageVersionsFromInstalledJson(path.join(COMMITTED_DIR, 'installed.json'))
    const freshInstalledJson = packageVersionsFromInstalledJson(path.join(FRESH_COMPOSER_DIR, 'installed.json'))
    const extraJson = committedInstalledJson.filter((p) => !freshInstalledJson.includes(p))
    const missingJson = freshInstalledJson.filter((p) => !committedInstalledJson.includes(p))
    for (const p of extraJson) errors.push(`installed.json lists '${p}', which a clean --no-dev install does not (likely a dev dependency)`)
    for (const p of missingJson) errors.push(`installed.json is missing '${p}', which a clean --no-dev install produces`)
  }
} catch (e) {
  errors.push(`Could not run the vendor drift check: ${e.message}`)
} finally {
  rmThrowaway()
}

if (errors.length > 0) {
  console.error('Vendor drift detected: committed vendor/composer/* no longer matches a clean `composer install --no-dev`.')
  console.error('Something (likely a bypassed or older test:unit) wrote dev dependencies into the committed vendor directory.')
  console.error('')
  for (const msg of errors) {
    console.error(process.env.GITHUB_ACTIONS ? `::error::${msg}` : `ERROR: ${msg}`)
  }
  console.error('')
  console.error('Regenerate with a clean production install and commit the result:')
  console.error('  docker run --rm -v "$(pwd)":/app -w /app composer:2 install --no-dev --no-interaction --quiet')
  process.exit(1)
}

console.log('Vendor drift check passed: vendor/composer/* matches a clean composer install --no-dev.')
process.exit(0)
