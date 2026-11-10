# FwChangelog

A per-page changelog for ProcessWire. Every time a page is saved, the module
records which fields changed, the value before and after, who made the change
and when. The history is shown in a modal opened from the **Settings** tab of
the page editor, for every template.

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

Installing creates the `fw_changelog` database table and the
`fw-changelog-view` permission.

## Usage

Edit any page and open the **Settings** tab. The changelog block shows the
number of logged changes and a link that opens the history.

Only changes made by a logged-in user are logged. Saves made by command-line
scripts and importers running as the guest user are ignored.

### Who can see the history

Superusers always can. Other users need the `fw-changelog-view` permission, in
addition to being able to edit the page.

### Configuration

| Setting | Default | Description |
| --- | --- | --- |
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
- The front end is not affected: the module only does work when a page is saved
  and when the history is opened in the page editor.
