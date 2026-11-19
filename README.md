# FwChangelog

A per-page changelog for ProcessWire. Every time a page is saved, the module
records which fields changed, the value before and after, who made the change
and when. The history of each page is shown in the **Settings** tab of the page
editor, for every template, and a dashboard lists the changes across all pages.

## Features

- Before and after values for each changed field, with the user and the date.
- Multi-language fields: one entry per language that changed.
- Page properties: name, status (published, hidden, locked), parent and template.
- Page events: created, moved to the trash, restored.
- Files and images: added, removed, replaced and description changes. File
  contents are never stored, only the file name.
- Page reference fields: the titles and ids of the selected pages.
- Repeater, RepeaterMatrix and FieldsetPage: changes inside items are logged on
  the page that owns them, including items added, removed and switched on or off.
  RepeaterMatrix items are identified by their type label.
- Combo fields: one entry per changed subfield.
- Toggle and checkbox values are shown with their labels instead of 0 and 1.
- Password fields are logged as changed, without their values.
- Consecutive changes to the same field by the same user are merged into one
  entry, so autosaves and quick re-saves do not flood the history.
- Old entries are deleted automatically after a configurable retention period.

## Requirements

- ProcessWire 3.0.200 or newer.
- The core LazyCron module, for the automatic cleanup of old entries. Without
  it everything else works, but entries are never deleted.

## Installation

1. Copy the `FwChangelog` folder to `/site/modules/`.
2. In the admin, go to **Modules > Refresh**.
3. Install **FwChangelog**.

Installing creates the `fw_changelog` database table, the `fw-changelog-view`
and `fw-changelog-dashboard` permissions, and installs the dashboard
(**FwChangelog Dashboard**, under **Setup > Changelog**).

### Installing from the admin

You can also install it from **Modules > New**, either from the modules
directory (class name `FwChangelog`) or with **Add Module From URL**:

```
https://github.com/joaochoupinaa/FwChangelog/archive/refs/heads/master.zip
```

Since ProcessWire 3.0.163 these options are off by default. To enable them, add
this to `/site/config.php`:

```php
$config->moduleInstall = array(
    'directory' => true, // install from the modules directory
    'upload' => false,   // install by uploading a zip file
    'download' => true,  // install by downloading from a URL
);
```

## Usage

Edit any page and open the **Settings** tab. How the **Changelog** block works
depends on the **Display** setting:

- **Modal** (default): the block shows the number of logged changes and a link
  that opens the history in a modal.
- **Inline**: the block is closed and shows the history when you open it. Like
  the core "What pages link to this page?" block, the history is only loaded
  when the block is opened.

Only changes made by a logged-in user are logged. Saves made by command-line
scripts and importers running as the guest user are ignored.

### Dashboard

**Setup > Changelog** lists the changes logged across all pages, newest first,
with filters by user, date range, template and branch (a page and every page
below it). Each entry shows the page it belongs to, with a link to edit it.
The filters are in the URL, so a filtered list can be bookmarked or shared.

### Who can see the history

- Of one page: superusers, and users with the `fw-changelog-view` permission
  who can edit the page.
- In the dashboard: superusers, and users with the `fw-changelog-dashboard`
  permission. The dashboard shows the changes to **all** pages, including their
  values, so give this permission only to roles that may see them.

### Configuration

| Setting | Default | Description |
| --- | --- | --- |
| Display | Modal | Where the history is shown: in a modal, or inline in the Settings tab. |
| Retention (months) | 12 | Entries older than this are deleted daily. 0 keeps them forever. |
| Maximum length per value (characters) | 20000 | Longer before/after values are cut. 0 means no limit. |
| Merge consecutive changes (minutes) | 10 | Changes by the same user to the same field within this interval are kept as one entry. 0 never merges. |
| Ignored templates | admin, user, role, permission, language | Pages using these templates are not logged. |
| Ignored fields | none | Changes to these fields are not logged. |

### Pausing the log from code

To skip logging during bulk changes made while a user is logged in:

```php
FwChangelog::pause();
// ... bulk changes ...
FwChangelog::resume();
```

## Notes

- **Uninstalling the module drops the `fw_changelog` table and all history.**
- Reordering repeater items, files or images is not logged.
- A replaced file is detected by a change in file size.
- PageTable items are regular pages and have their own changelog.
- Entries logged before version 1.2.0 get their template from the page when
  upgrading. Entries of pages that were already deleted have no template, so
  the template filter does not find them.
- The front end is not affected: the module only does work when a page is saved
  and when the history is opened in the page editor or the dashboard.
