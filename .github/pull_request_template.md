## Summary

<!-- What does this PR change, and why? Link the Linear issue (SKL-N) if there is one. -->

## Checklist

- [ ] Branched from `main` as `skl-<issue-number>/<kebab-title>`
- [ ] `$plugin->version` (and `$plugin->release` if user-visible) bumped in `src/version.php`
- [ ] `CHANGELOG.md` updated for user-visible changes
- [ ] New/changed PHP files carry the GPL header and `@package`/`@copyright`/`@license` phpdoc
- [ ] `npm run test:unit` passes
- [ ] `npm run lint` passes and `src/amd/build/` is rebuilt if `src/amd/src/` changed
- [ ] New or updated strings added to both `src/lang/en/skilland.php` and `src/lang/es/skilland.php`

## Test plan

<!-- How did you verify this change? -->
