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
    DigitalTolk gives you all three. The environment variables
    ``DT_TMS_ADAPTER_URL``, ``DT_TMS_TOKEN`` and ``DT_TMS_CONNECTION_KEY``
    replace the settings, so the secrets can stay out of
    ``config/system/settings.php``.

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
need edit rights on every page of the package, modify rights on ``pages`` and
``tt_content``, and access to the target language.

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
