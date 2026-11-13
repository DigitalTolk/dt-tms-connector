# Changelog

All notable changes to this project are listed here. The project uses [semantic versioning](https://semver.org).

## [0.1.0] - 2026-11-13

### Added

- Backend module Web, Translations: send pages, preview translations, import and cancel.
- Command `dttms:sync`: send queued packages, poll, download, import and report each language.
- Signed callback endpoint `/dt-tms/callback`.
- Import into review in the module, or at once into a workspace.
- Flag for items that changed after the package was sent.
- Release ZIP that installs from a Composer artifact folder or with the Extension Manager.

### Security

- Never send "HTML" content elements, and keep the source text of fields where a translation adds scripts or active HTML.
- Allow signed links only to the adapter host and the configured storage hosts, uploads only with PUT, and downloads up to 50 MB. Add time limits to every request.
- Allow plain http to local hosts only in the TYPO3 Development context.
- Keep the target of "external URL" pages from the source.
- Check edit rights, file reference rights, locked pages and language access for send, preview, import and cancel.
