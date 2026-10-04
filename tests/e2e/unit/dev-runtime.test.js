const { test } = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const { spawn, spawnSync } = require('node:child_process')
const { setTimeout: delay } = require('node:timers/promises')
const { once } = require('node:events')

const root = path.resolve(__dirname, '../../..')

function fixture(t) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'skilland-dev-'))
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }))
  return dir
}

function write(dir, file, content) {
  fs.mkdirSync(path.dirname(path.join(dir, file)), { recursive: true })
  fs.writeFileSync(path.join(dir, file), content)
}

test('build and watch retain the bind mount inode and propagate edits, additions and deletions', { timeout: 30000 }, async t => {
  const dir = fixture(t)
  fs.copyFileSync(path.join(root, 'Gruntfile.js'), path.join(dir, 'Gruntfile.js'))
  fs.copyFileSync(path.join(root, 'package.json'), path.join(dir, 'package.json'))
  fs.symlinkSync(path.join(root, 'node_modules'), path.join(dir, 'node_modules'), 'dir')
  write(dir, 'src/version.php', '<?php // fixture')
  write(dir, 'src/amd/src/example.js', 'define([], function() { return 1; });')
  write(dir, 'src/amd/build/example.min.js', 'stale tracked twin')
  write(dir, 'src/templates/example.mustache', 'first')
  write(dir, 'cli/example.php', '<?php // fixture')
  write(dir, 'dist/.stale', 'stale')
  const inode = fs.statSync(path.join(dir, 'dist')).ino
  const grunt = path.join(root, 'node_modules/grunt-cli/bin/grunt')
  const build = spawnSync(process.execPath, [grunt, 'build'], { cwd: dir, encoding: 'utf8', timeout: 15000 })
  assert.equal(build.status, 0, build.stdout + build.stderr)
  assert.equal(fs.statSync(path.join(dir, 'dist')).ino, inode)
  assert.equal(fs.existsSync(path.join(dir, 'dist/.stale')), false)
  assert.ok(fs.existsSync(path.join(dir, 'dist/amd/build/example.min.js')))
  const watcher = spawn(process.execPath, [grunt, 'watch', '--no-color'], { cwd: dir, stdio: ['ignore', 'pipe', 'pipe'] })
  let output = ''
  watcher.stdout.on('data', data => { output += data })
  watcher.stderr.on('data', data => { output += data })
  t.after(async () => {
    if (watcher.exitCode === null) {
      const exited = once(watcher, 'exit')
      watcher.kill('SIGTERM')
      await exited
    }
  })
  async function until(check) {
    const deadline = Date.now() + 8000
    while (!check()) {
      assert.equal(watcher.exitCode, null, output)
      assert.ok(Date.now() < deadline, output)
      await delay(50)
    }
  }
  await until(() => output.includes('Waiting...'))
  write(dir, 'src/templates/example.mustache', 'changed')
  write(dir, 'src/pix/added.svg', '<svg/>')
  await until(() => fs.existsSync(path.join(dir, 'dist/pix/added.svg')) &&
    fs.existsSync(path.join(dir, 'dist/templates/example.mustache')) &&
    fs.readFileSync(path.join(dir, 'dist/templates/example.mustache'), 'utf8') === 'changed')
  await until(() => output.split('Waiting...').length >= 3)
  fs.rmSync(path.join(dir, 'src/amd/src/example.js'))
  fs.rmSync(path.join(dir, 'src/templates/example.mustache'))
  fs.rmSync(path.join(dir, 'cli/example.php'))
  await until(() => !fs.existsSync(path.join(dir, 'dist/amd/build/example.min.js')) &&
    !fs.existsSync(path.join(dir, 'dist/templates/example.mustache')) &&
    !fs.existsSync(path.join(dir, 'dist/cli/example.php')))
  assert.equal(fs.existsSync(path.join(dir, 'dist/amd/src/example.js')), false)
  assert.equal(fs.statSync(path.join(dir, 'dist')).ino, inode)
})

function startup(t, { built = true, installed = true, database = true, fail = '' } = {}) {
  const dir = fixture(t)
  if (built) write(dir, 'html/mod/skilland/version.php', '<?php // fixture')
  const entrypoint = fs.readFileSync(path.join(root, '00_development/entrypoint.sh'), 'utf8')
    .replaceAll('/var/www/html', path.join(dir, 'html'))
  write(dir, 'entrypoint.sh', entrypoint)
  const commands = {
    timeout: '#!/bin/bash\nshift\nexec "$@"\n',
    mysql: '#!/bin/bash\necho mysql >> "$CALLS"\n[ "$DATABASE" = true ] || exit 1\nif [[ "$*" == *mdl_config* ]] && [ "$INSTALLED" != true ]; then exit 1; fi\necho 1\n',
    sleep: '#!/bin/bash\nexit 0\n',
    runuser: '#!/bin/bash\necho "runuser $*" >> "$CALLS"\nif [ -n "$FAIL" ] && [[ "$*" == *"$FAIL"* ]]; then exit 7; fi\n',
    'apache2-foreground': '#!/bin/bash\necho apache >> "$CALLS"\n'
  }
  for (const [name, script] of Object.entries(commands)) {
    write(dir, `bin/${name}`, script)
    fs.chmodSync(path.join(dir, `bin/${name}`), 0o755)
  }
  const calls = path.join(dir, 'calls')
  const result = spawnSync('bash', [path.join(dir, 'entrypoint.sh')], {
    encoding: 'utf8', timeout: 5000,
    env: { ...process.env, PATH: `${dir}/bin:${process.env.PATH}`, CALLS: calls,
      INSTALLED: String(installed), DATABASE: String(database), FAIL: fail,
      MOODLE_DB_HOST: 'moodle-db', MOODLE_DB_USER: 'moodle', MOODLE_DB_PASS: 'fixture', MOODLE_DB_NAME: 'moodle',
      MOODLE_ADMIN_USER: 'admin', MOODLE_ADMIN_PASS: 'fixture', MOODLE_ADMIN_EMAIL: 'admin@example.test',
      MOODLE_SITE_FULLNAME: 'Fixture', MOODLE_SITE_SHORTNAME: 'fixture' }
  })
  return { ...result, calls: fs.existsSync(calls) ? fs.readFileSync(calls, 'utf8') : '' }
}

test('startup rejects an absent build before touching the database', t => {
  const result = startup(t, { built: false })
  assert.equal(result.status, 1)
  assert.match(result.stderr, /npm ci && npm run build/)
  assert.equal(result.calls, '')
})

for (const installed of [true, false]) {
  test(`startup upgrades and purges as www-data before Apache (installed=${installed})`, t => {
    const result = startup(t, { installed })
    assert.equal(result.status, 0, result.stderr)
    assert.equal(result.calls.includes('install_database.php'), !installed)
    assert.equal(result.calls.includes('create_test_users.php'), !installed)
    assert.match(result.calls, /runuser -u www-data -- php .*upgrade.php --non-interactive\nrunuser -u www-data -- php .*purge_caches.php\napache\n$/)
  })
}

for (const fail of ['install_database.php', 'upgrade.php', 'purge_caches.php']) {
  test(`startup refuses to serve after ${fail} fails`, t => {
    const result = startup(t, { installed: false, fail })
    assert.equal(result.status, 7)
    assert.equal(result.calls.includes('apache'), false)
  })
}

test('startup stops after bounded database readiness attempts', t => {
  const result = startup(t, { database: false })
  assert.equal(result.status, 1)
  assert.match(result.stderr, /MariaDB did not become ready/)
  assert.equal(result.calls.trim().split('\n').length, 40)
  assert.equal(result.calls.includes('runuser'), false)
})
