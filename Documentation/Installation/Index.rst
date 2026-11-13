..  include:: /Includes.rst.txt

============
Installation
============

Requirements
============

*   TYPO3 12.4 or 13.4. TYPO3 12.4 gets security fixes only through paid
    extended support (ELTS).
*   PHP 8.3 or 8.4 with the ``ftp`` extension, which l10nmgr 13 needs.
*   The extension ``localizationteam/l10nmgr`` version 13.
*   The TYPO3 Scheduler, or a cron job.
*   A DigitalTolk account. DigitalTolk gives you the API address, a gateway
    token and a connection key.

The release ZIP
===============

Each release is one ZIP file, ``dt_tms_connector_<version>.zip``.
DigitalTolk sends it to you. Version 0.1.0 is also in the TYPO3 Extension
Repository. You need no account, hosting or token to install it. Updates are manual: you
install the new ZIP.

A. Site with Composer: artifact folder (recommended)
=====================================================

#.  Put the ZIP into a folder of the project, for example ``packages/zip/``.
#.  Declare the folder once as a Composer artifact repository:
    ``composer config repositories.dt-tms-connector artifact ./packages/zip``
#.  Run ``composer require digitaltolk/dt-tms-connector:^0.1``.
#.  If the site pins ``config.platform.php`` below 8.3, raise it to 8.3.
#.  Run ``vendor/bin/typo3 extension:setup``.

To update, put the new ZIP into the same folder and run
``composer update digitaltolk/dt-tms-connector``.

B. Site without Composer: Extension Manager
===========================================

#.  Install the Localization Manager (l10nmgr) 13 from the TYPO3 Extension
    Repository first.
#.  Open Admin Tools, Extensions, and click "Upload Extension".

    ..  figure:: /Images/install-01-upload-extension.png
        :alt: The Upload Extension button in the Extension Manager
        :class: with-shadow

#.  Pick ``dt_tms_connector_<version>.zip`` and click "Upload!".

    ..  figure:: /Images/install-02-pick-zip.png
        :alt: The ZIP picked in the upload form
        :class: with-shadow

#.  The Extension Manager installs and activates the extension. The module
    Web, Translations appears in the menu.

    ..  figure:: /Images/install-03-extension-active.png
        :alt: The extension installed and active
        :class: with-shadow

To update, upload the new ZIP and select "Overwrite". A TYPO3 site that runs
with Composer cannot upload extensions in the Extension Manager. Use A there.
