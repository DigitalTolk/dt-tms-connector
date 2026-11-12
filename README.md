# DigitalTolk TMS connector for TYPO3

The extension sends TYPO3 pages to [DigitalTolk](https://www.digitaltolk.com) for translation and imports the translations. It uses the export and import of the Localization Manager (`l10nmgr`).

- Extension key: `dt_tms_connector`. Composer package: `digitaltolk/dt-tms-connector`.
- TYPO3 12.4 or 13.4, PHP 8.3 or 8.4, `localizationteam/l10nmgr` ^13. `l10nmgr` 13 needs the PHP `ftp` extension.
- You need a DigitalTolk account. DigitalTolk gives you the API address, a gateway token and a connection key.

## How it works

1. An editor opens Web, Translations, picks a page and a depth, picks the target languages, and clicks Send. The module stores the package as `queued`.
2. The command `dttms:sync` exports the pages with `l10nmgr` (CATXML, for the first target language). It uploads the file to DigitalTolk and completes the package with one item for each page and content element.
3. DigitalTolk translates the package. The sync polls the package status. If DigitalTolk calls the site back, the next sync polls that package at once.
4. When a language is ready, the sync downloads the file. The language waits in the module as `downloaded`.
5. The editor previews the translation and clicks Import. The next sync exports a fresh skeleton for that language, fills it from the translation, and imports it with `l10nmgr`. Then it reports the language as imported.

The module flags items that changed or were removed after sending. Their translations are based on an older version.

### Why a fresh skeleton

The `l10nmgr` export writes keys such as `NEW/1/7` for missing translations. An import with old `NEW` keys deletes an existing translation and creates it again. So the sync exports again for each target language and copies the translated text into that export by table, record and field. One file from DigitalTolk then serves every target language. Fields that hold only a number in the fresh export, such as file references, keep their value.

### Import modes

- **Review in the module** (default): an editor imports each language. New page and content translations are imported hidden, so an editor can check them before they go live. Updates of existing translations go live at once, because `l10nmgr` writes them directly.
- **Workspace**: the sync imports each language at once into the configured workspace. Editors publish through the TYPO3 workspace workflow.

## Install

1. `composer require digitaltolk/dt-tms-connector`
2. If the site pins `config.platform.php` below 8.3, raise it to 8.3.
3. `vendor/bin/typo3 extension:setup`
4. Set the extension configuration (Settings, Extension Configuration, `dt_tms_connector`):
   - **Adapter URL**, **Gateway token** and **Connection key**: DigitalTolk gives you all three. The environment variables `DT_TMS_ADAPTER_URL`, `DT_TMS_TOKEN` and `DT_TMS_CONNECTION_KEY` replace the settings, so the secrets can stay out of `config/system/settings.php`.
   - **Import mode**, **Workspace UID**, **Hide new translations** and **Poll interval**.
5. Add a Scheduler task "Execute console commands" for `dttms:sync`, every 5 minutes. Or run `vendor/bin/typo3 dttms:sync` from cron.
6. Give editors access to the module Web, Translations.
7. Open the module and click Test connection.

### Callback (optional)

DigitalTolk can call `https://<your site>/dt-tms/callback` when a language is delivered or cancelled. Give DigitalTolk this URL. The extension checks the `X-DT-Signature` header with the connection key, and refuses requests older than 5 minutes. A callback only marks the package for the next sync. Without callbacks, the sync polls every open package at the poll interval.

## Data sent to DigitalTolk

- The text of the pages and content elements in the package, as the `l10nmgr` export holds it.
- For each page and content element: the table and record number, the title, the page URL, the content type and the last change time.
- The source and target languages, the due date and the note that the editor writes.

The extension sends nothing else, and it calls DigitalTolk only when an editor acts or the sync runs.

## Notes

- The import runs as the CLI user `_cli_`, which is an admin. So the module checks the editor first: Send, Import and Cancel need edit rights on every page of the package, modify rights on `pages` and `tt_content`, access to the target language, and the same workspace as the package (live in review mode).
- Editors see only packages of pages they can read.
- Hidden content elements are not sent (`--noHidden`). Hidden pages and content with a start or end time are sent, so you can translate them before they go live.
- Folders, spacers and recycler pages are not sent. Pages inside a folder are sent.
- Two target languages with the same language code (for example de-DE and de-AT) cannot go in one package.
- If DigitalTolk cannot be reached, the package stays queued and the next sync tries again. If a sync stops while it sends a package, a later sync sends the package again after 30 minutes.
- One package holds at most 2000 items.
- Each package writes its own `l10nmgr` configuration record on the site root page, named "DigitalTolk <reference>".

## Development

```
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix --dry-run --diff
```

The unit tests need no TYPO3 database. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Release

1. Update `CHANGELOG.md` and the version in `ext_emconf.php`.
2. Push an annotated tag such as `0.2.0`.
3. The `publish` workflow makes a GitHub release with the extension zip. When the repository secret `TYPO3_API_TOKEN` is set, it also publishes the version to the TYPO3 Extension Repository. Packagist reads the tag on its own.

## Security

Report security problems privately. See [SECURITY.md](SECURITY.md).

## Licence

Copyright (c) 2026 DigitalTolk.

This program is free software. You can redistribute it and modify it under the terms of the GNU General Public License, version 2 or (at your option) any later version. See [LICENSE.txt](LICENSE.txt).

TYPO3 is a registered trademark of the TYPO3 Association. This project is not affiliated with or endorsed by the TYPO3 Association.
