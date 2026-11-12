..  include:: /Includes.rst.txt

==============
Known problems
==============

The connection test fails
    Check the adapter URL, the gateway token and the connection key. The URL
    must use https.

A package stays queued
    The Scheduler task does not run, or DigitalTolk cannot be reached. The
    error column shows the reason. The next sync tries again.

The import failed
    The language stays downloaded and shows the error. Fix the cause, then
    click Import again.

Updates go live at once
    l10nmgr writes updates of existing translations directly. Use the import
    mode ``workspace`` if every change needs a review.
