# Contributing

Thank you for helping with WireCore REST Control. Bug reports, ideas and pull requests are welcome.

## Before you start

- For anything bigger than a small fix, open an issue first so we can agree on the approach before you spend time on it.
- Questions about using the plugin belong in the [support forum on wordpress.org](https://wordpress.org/support/plugin/wirecore-rest-control/).
- Security problems go through the private channel in [SECURITY.md](SECURITY.md), not through issues.

## Development

The setup – Docker for WordPress, Node.js for Prettier and the WireLayer CSS – is described in [README.md](README.md#development).

Before a pull request:

```bash
npm run format:check
composer phpcs
docker compose exec -w /var/www/html/wp-content/plugins/wirecore-rest-control wordpress vendor/bin/phpunit
```

The CI runs the same on PHP 7.4 – 8.5, plus Plugin Check on the built package. Add or adjust tests for what you change.

## Guidelines

- Code, comments, UI source strings and commit messages are in English. German translations come from translate.wordpress.org; after changing UI strings, regenerate the template `languages/wirecore-rest-control.pot` (see [README.md](README.md#translations)).
- The plugin supports WordPress 5.8+ and PHP 7.4 – 8.5: no newer functions, nothing deprecated up to PHP 8.5.
- Everything global carries the prefix `wirecore_rc_` / `WIRECORE_RC_` / `wirecore-rc-`.
- Everything that is stored goes through `WireCore_RC_Settings::sanitize()`, and existing export files must keep importing.
- Rules are evaluated only in `WireCore_RC_Guard::rule()` and `decide()`; `assets/admin.js` mirrors the inheritance for the display – change both together.
- Plugin Check stays free of errors and warnings.

## License

By contributing you agree that your contribution is licensed under the [GPL-2.0-or-later](LICENSE), like the rest of the project.

Every commit carries a `Signed-off-by` line (`git commit -s`). With it you confirm the [Developer Certificate of Origin](https://developercertificate.org): you wrote the change yourself, or it is based on work under a compatible open source license, and you have the right to contribute it under the GPL. The DCO check on the pull request fails without it. To add it afterwards: `git commit --amend -s` for the last commit, `git rebase --signoff <base>` for several, then push with `--force-with-lease`.
