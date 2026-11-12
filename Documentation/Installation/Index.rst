..  include:: /Includes.rst.txt

============
Installation
============

Requirements
============

*   TYPO3 12.4 or 13.4.
*   PHP 8.3 or 8.4 with the ``ftp`` extension, which l10nmgr 13 needs.
*   The extension ``localizationteam/l10nmgr`` version 13.
*   The TYPO3 Scheduler, or a cron job.
*   A DigitalTolk account. DigitalTolk gives you the API address, a gateway
    token and a connection key.

Install
=======

#.  Run ``composer require digitaltolk/dt-tms-connector``.
#.  If the site pins ``config.platform.php`` below 8.3, raise it to 8.3.
#.  Run ``vendor/bin/typo3 extension:setup``.
