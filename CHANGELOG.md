# Olive Menus release notes

## 5.3.0 - 2026-11-28

### Added

- Assign native Craft field layouts to menus and nodes, with field content saved separately for each site.
- Create linked site variants and sync their structure while keeping localized content.
- Save a private menu draft, preview it in the control panel, and publish it when ready.
- Review published revisions and restore an earlier version as a draft.
- Set node visibility by site, audience, user group, or date and time.
- Export a menu as JSON and review an import before creating a new menu.
- Set two, three, or four columns on a group for mega menu layouts. Site templates control the visual styling.

### Fixed

- Open duplicated menus and older menu items when optional link fields are empty.
- Submit Copy to site from the Craft menu and copy a consistent snapshot of the source.
- Keep site variants editable after deleting their source. Repair older disconnected variants on sync.
- Prevent an older field form from overwriting a more recent save.
- Preserve native URLs and date time zones during transfer. Report incompatible fields before import and keep exported files within the supported transfer limits.
- Enable menu builder actions once the editor is ready.

## 5.2.0 - 2026-11-28

### Added

- Create headings, groups and site links from the menu editor.
- Fill a group with published entries from the menu's site. Choose the source section, sort order and maximum, with manually added items kept first.
- Use `getMenuContext()` to render the current or selected item's siblings, ancestors and branch in Twig.
- Query node types and identify dynamically generated links through GraphQL.
- Add query parameters and an anchor to a linked element URL while following the element's current address (issue #49).

## 5.1.1 - 2026-11-27

### Changed

- Removed an experimental CLI integration from the package.

## 5.1.0 - 2026-11-27

### Added

- Pick entries, categories, and assets using Craft's search and selection dialog. Craft Commerce products appear when Commerce is enabled.
- Hide individual items and their descendants without deleting them. Linked items can follow their element's title and availability.
- Duplicate a menu with its nested items and link settings, on the current site or another site you can edit.
- Move items up, down, in, and out with keyboard-accessible controls.
- Render breadcrumbs with `craft.olivemenus.getBreadcrumbs()` and query menus with GraphQL when the schema permits it.
- Register additional linkable element types from a Craft module or plugin.
- Set an accessible label with the `nav-label` Twig option.

### Changed

- Updated the Olive Studio icons and reorganized the editor for Craft 5.
- Updated Sortable to jQuery UI 1.14.2 and removed unused widgets.
- Require Craft's Olive Menus permission for non-admin users in the control panel.
- Expand public HTTP(S) aliases in custom links and keep filesystem aliases private.
- Limit custom link attributes to `data-*` and `aria-*`. Olive Menus sets `aria-current` for the active link and ignores unsafe URL schemes.
- Keep unsaved menu changes in the editor when a save fails.

### Fixed

- Resolve linked elements on the menu's own site and allow the same menu handle on multiple sites after a fresh install.
- Keep item saves within the selected menu. Invalid or outdated submissions roll back without changing the saved tree.
- Show detached items from older menus in the editor so they can be reattached.

## 5.0.1 - 2026-06-12
### Improvements
- Craft 5 improvements

## 5.0.0 - 2024-07-29
### Added
- Craft 5 compatibility & matched styling

## 4.0.0 - 2022-11-11
### Added
- Added Craft 4

## 1.2.2 - 2022-11-11
### Updated release for Craft 3

## 1.2.1 - 2020-05-20
### Fixed
- Added "site_id" to the install migration

## 1.2.0 - 2020-05-18
### Added
- extra config options for HTML output: without-container and without-ul

## 1.1.10 - 2020-05-15
### Fixed
- migration/down for multi-site
- set default site ID for multi-site migrations #44
- fix URL routing for multi-site (index and create) #43

## 1.1.9 - 2020-05-15
### Fixed
- Migrations are not run when updating the plugin #42

## 1.1.8 - 2020-05-04
### Only version bump

## 1.1.7 - 2020-02-28
### Added multisite option

## 1.1.6 - 2020-02-26
### Fixed update plugin + custom child item break
- Custom Child Item breaks the link of parent item #38

## 1.1.1 - 2020-02-10
### Fixed composer.json to have the correct version

## 1.1.0 - 2020-01-06
### Fixed issue showing deleted entries + added new tab option ability
- 35 Entries still showing on olivemenu admin listing even after deleted from entries page
- 32 Is there a way that you can assign a menu item to open in a new tab?

## 1.0.9 - 2019-04-25
### Fixed issues
- 30 Plugin breaks when content is deleted

## 1.0.8 - 2019-02-07
### Fixed issues
- 28 'sub-menu-ul-class' throwing exception on 1.0.6+
- 29 Undefined index: sub-menu error

## 1.0.7 - 2018-12-07
### Fixed uninstall issue with droping tables

## 1.0.6 - 2018-12-07
### Added the ability to add a submenu class during initiation

## 1.0.5 - 2018-10-22
### Added category support to menu

## 1.0.4 - 2018-10-04
### Fix for List of entries not shown

## 1.0.3 - 2018-09-16
### Various fixes

## 1.0.2 - 2018-08-30
### Custom Menu item will receive an entry child item's ID if saved at same time

## 1.0.1 - 2018-07-30
### Added category support

## 1.0.0 - 2018-07-26
### Added
- Initial release
