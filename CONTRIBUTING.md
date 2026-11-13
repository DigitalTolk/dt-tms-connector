# Contributing

## Set up

1. Install PHP 8.3 or 8.4 with the `intl`, `ftp` and `zip` extensions, and Composer.
2. Run `composer install`.

## Checks

Run these before you open a pull request. CI runs the same checks on TYPO3 12.4 and 13.4.

```
composer validate
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/phpstan analyse
vendor/bin/phpunit
```

## Pull requests

- Keep one change in one pull request.
- Add or change a unit test for each change in behaviour.
- Write commit messages as `type: what changed`, for example `fix: keep number fields on import`.
- Add a line under "Unreleased" in `CHANGELOG.md`.

## Releases

Maintainers release with a tag. See the Release section in the README.

## Support and release policy

- A published version is never removed and its tag never moves. Sites that installed it expect it to stay the same.
- If a version has a bug or a security problem, we publish a fixed version. For a security problem we also publish a GitHub security advisory, so `composer audit` warns the sites that use an affected version.
- If we stop maintaining the package, we mark it as abandoned on Packagist and in the TYPO3 Extension Repository, with a replacement if there is one. We do not delete it.
- The owners in `.github/CODEOWNERS` answer issues and review pull requests. Security reports go through SECURITY.md.
