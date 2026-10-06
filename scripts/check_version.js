/**
 * Version gate for src/version.php.
 *
 * Every merge to main publishes a release tagged v<$plugin->version>, so a change must:
 *   1. raise $plugin->version above the base,
 *   2. change the $plugin->release string,
 *   3. keep $plugin->release and $plugin->maturity in agreement
 *      (alpha -> MATURITY_ALPHA, beta -> MATURITY_BETA, rc -> MATURITY_RC, else MATURITY_STABLE).
 *
 * The base is `git show $BASE_REF:src/version.php` (CI passes the PR base sha); without
 * BASE_REF it is HEAD, i.e. the last commit, for local use. SKIP_VERSION_CHECK=1 skips it.
 *
 * Tooling-only changes are exempt: when BASE_REF is set and nothing that ends up in the release
 * ZIP changed between BASE_REF and HEAD (src/, minus the dev-only src/cli/configure_api.php,
 * mirroring Gruntfile.js), no bump is required.
 */
const fs = require('fs')
const { execFileSync } = require('child_process')

const VERSION_FILE = 'src/version.php'

if (process.env.SKIP_VERSION_CHECK) {
  console.log('SKIP_VERSION_CHECK set. Skipping version check.')
  process.exit(0)
}

// Paths shipped in the release ZIP (see Gruntfile.js copy tasks).
const SHIPPED_PATHSPEC = ['src/', ':(exclude)src/cli/configure_api.php']

if (process.env.BASE_REF) {
  try {
    const changed = execFileSync(
      'git',
      ['diff', '--name-only', `${process.env.BASE_REF}...HEAD`, '--', ...SHIPPED_PATHSPEC],
      { stdio: ['ignore', 'pipe', 'pipe'] },
    ).toString().trim()
    if (changed === '') {
      console.log('No shipped file under src/ changed: version bump not required.')
      process.exit(0)
    }
  } catch (e) {
    // Cannot tell what changed: fall through to the full check.
  }
}

function parse(content) {
  const version = content.match(/\$plugin->version\s*=\s*(\d+)/)
  const release = content.match(/\$plugin->release\s*=\s*'([^']+)'/)
  const maturity = content.match(/\$plugin->maturity\s*=\s*(MATURITY_\w+)/)
  return {
    version: version ? parseInt(version[1], 10) : null,
    release: release ? release[1] : null,
    maturity: maturity ? maturity[1] : null,
  }
}

// Pre-release suffix: -alpha, -beta or -rc, optionally followed by a number (-rc1, -beta.2).
const PRERELEASE_SUFFIX = /-(alpha|beta|rc)(\.?\d+)?$/i

function expectedMaturity(release) {
  const m = release.match(PRERELEASE_SUFFIX)
  return m ? `MATURITY_${m[1].toUpperCase()}` : 'MATURITY_STABLE'
}

const errors = []
const fail = (msg) => errors.push(msg)

const head = parse(fs.readFileSync(VERSION_FILE, 'utf8'))
if (head.version === null) fail(`Could not find $plugin->version in ${VERSION_FILE}`)
if (head.release === null) fail(`Could not find $plugin->release in ${VERSION_FILE}`)
if (head.maturity === null) fail(`Could not find $plugin->maturity in ${VERSION_FILE}`)

if (head.release !== null && head.maturity !== null) {
  const expected = expectedMaturity(head.release)
  if (head.maturity !== expected) {
    fail(`Release '${head.release}' requires ${expected}, but maturity is ${head.maturity}`)
  }
}

const baseRef = process.env.BASE_REF || 'HEAD'
let baseContent = null
try {
  baseContent = execFileSync('git', ['show', `${baseRef}:${VERSION_FILE}`], {
    stdio: ['ignore', 'pipe', 'pipe'],
  }).toString()
} catch (e) {
  fail(`Could not read ${VERSION_FILE} at ${baseRef}: ${e.stderr ? e.stderr.toString().trim() : e.message}`)
}

if (baseContent !== null) {
  const base = parse(baseContent)
  if (base.version === null || base.release === null) {
    fail(`Could not parse $plugin->version / $plugin->release at ${baseRef}`)
  } else if (head.version !== null && head.release !== null) {
    if (head.version <= base.version) {
      fail(`$plugin->version must increase: base ${base.version}, head ${head.version}`)
    }
    if (head.release === base.release) {
      fail(`$plugin->release must change: still '${head.release}' (as at ${baseRef})`)
    }
    if (errors.length === 0) {
      console.log(`Version bumped: ${base.version} (${base.release}) -> ${head.version} (${head.release}, ${head.maturity})`)
    }
  }
}

if (errors.length > 0) {
  for (const msg of errors) {
    console.error(process.env.GITHUB_ACTIONS ? `::error file=${VERSION_FILE}::${msg}` : `ERROR: ${msg}`)
  }
  console.error('')
  console.error('Every merge to main publishes a release tagged v<$plugin->version>. In src/version.php update:')
  console.error('  $plugin->version  (YYYYMMDDXX, higher than main)')
  console.error('  $plugin->release  (new version string, e.g. 0.9.8-beta)')
  console.error('  $plugin->maturity (MATURITY_ALPHA|BETA|RC|STABLE, matching the release string)')
  process.exit(1)
}
