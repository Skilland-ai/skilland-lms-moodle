const fs = require('fs');
const { execSync } = require('child_process');

if (process.env.SKIP_VERSION_CHECK) {
    console.log('⏭️ SKIP_VERSION_CHECK set. Skipping version check.');
    process.exit(0);
}

try {
    // 1. Get current version
    const versionFile = 'src/version.php';
    const content = fs.readFileSync(versionFile, 'utf8');
    const versionMatch = content.match(/\$plugin->version\s*=\s*(\d+)/);

    if (!versionMatch) {
        console.error('❌ Error: Could not find version in src/version.php');
        process.exit(1);
    }

    const currentVersion = parseInt(versionMatch[1]);

    // 2. Get previous version from git (safely)
    let previousContent;
    try {
        previousContent = execSync(`git show HEAD:${versionFile}`, { stdio: ['pipe', 'pipe', 'ignore'] }).toString();
    } catch (e) {
        // If file didn't exist in HEAD (new file), allows pass
        console.log('⚠️ New file or git error, skipping version check.');
        process.exit(0);
    }

    const previousMatch = previousContent.match(/\$plugin->version\s*=\s*(\d+)/);

    if (!previousMatch) {
        console.log('⚠️ Could not find previous version, skipping check.');
        process.exit(0);
    }

    const previousVersion = parseInt(previousMatch[1]);

    // 3. Compare
    if (currentVersion > previousVersion) {
        console.log(`✅ Version bumped: ${previousVersion} -> ${currentVersion}`);
        process.exit(0);
    } else {
        console.error(`\n❌ ERROR: Version bump required!`);
        console.error(`Current:  ${currentVersion}`);
        console.error(`Previous: ${previousVersion}`);
        console.error(`\nPlease update $plugin->version in src/version.php before committing.`);
        console.error(`(To bypass: git commit --no-verify)\n`);
        process.exit(1);
    }

} catch (err) {
    console.error('❌ Version check failed with error:', err.message);
    process.exit(1);
}
