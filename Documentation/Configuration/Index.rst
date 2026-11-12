..  include:: /Includes.rst.txt

=============
Configuration
=============

Site languages
==============

Add each target language to the site configuration. The extension sends the
language code of each target language, for example ``en`` or ``fr``. Two
target languages with the same code cannot go in one package.

Extension configuration
=======================

Open Settings, Extension Configuration, ``dt_tms_connector``.

Adapter URL, Gateway token, Connection key
    DigitalTolk gives you all three. Set them with the environment variables
    ``DT_TMS_ADAPTER_URL``, ``DT_TMS_TOKEN`` and ``DT_TMS_CONNECTION_KEY``, so
    the secrets stay out of ``config/system/settings.php``. The settings
    fields are for sites that cannot use environment variables.

Storage hosts
    The hosts that the signed upload and download links may use, separated by
    commas. The default ``*.amazonaws.com`` fits DigitalTolk's storage.

Import mode
    ``review`` (default): an editor imports each language in the module.
    ``workspace``: the sync imports at once into the workspace below.

Workspace UID
    The workspace for the import mode ``workspace``.

Hide new translations
    In review mode, new page and content translations are imported hidden.

Poll interval
    How often, in minutes, the sync asks for the status of open packages.

Scheduler
=========

Add a Scheduler task "Execute console commands" for ``dttms:sync`` and run it
every 5 minutes. A cron job that runs ``vendor/bin/typo3 dttms:sync`` works
too.

Permissions
===========

Give editors access to the module Web, Translations. Send, Import and Cancel
need edit rights on every page of the package (pages that are locked for
editing are refused), modify rights on ``pages``, ``tt_content`` and
``sys_file_reference``, and access to the languages.

Each package that an editor sends can become a paid order. Give the module
only to people who may order translations.

Callback
========

DigitalTolk can call ``https://<your site>/dt-tms/callback`` when a language
is delivered or cancelled. Give DigitalTolk this URL. The extension checks the
signature with the connection key. Without callbacks, the sync polls every
open package at the poll interval.

Test the connection
===================

Open the module and click Test connection. The message shows the connection
name and its languages.

Security
========

*   Keep the l10nmgr jobs folder outside the web root. Use the default
    ``var/tx_l10nmgr``, do not point ``baseFileStoragePath`` into ``public/``,
    and remove an old ``public/uploads/tx_l10nmgr/`` folder.
*   Turn off the l10nmgr email and FTP options, or every export goes out that
    way too.
*   The extension never sends "HTML" content elements, because TYPO3 prints
    them without escaping. On import it keeps the source text of any field
    where the translation adds scripts or other active HTML, and the import
    summary says so.
*   For extra safety, set
    ``$GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['security.backend.htmlSanitizeRte'] = true``.
*   Updates of existing translations go live at once. Use the import mode
    ``workspace`` if every change needs a review before it goes live.
