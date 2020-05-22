# Olivemenus Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 1.0.0 - 2018-07-26
### Added
- Initial release

## 1.0.1 - 2018-07-30
### Added category support


## 1.0.2 - 2018-08-30
### Custom Menu item will receive an entry child item's ID if saved at same time

## 1.0.3 - 2018-09-16
### Various fixes

## 1.0.4 - 2018-10-04
### Fix for List of entries not shown

## 1.0.5 - 2018-10-22
### Added category support to menu

## 1.0.6 - 2018-12-07
### Added the ability to add a submenu class during initiation

## 1.0.7 - 2018-12-07
### Fixed uninstall issue with droping tables

## 1.0.8 - 2019-02-07
### Fixed issues
- 28 'sub-menu-ul-class' throwing exception on 1.0.6+
- 29 Undefined index: sub-menu error

## 1.0.9 - 2019-04-25
### Fixed issues
- 30 Plugin breaks when content is deleted

## 1.1.0 - 2020-01-06
### Fixed issue showing deleted entries + added new tab option ability
- 35 Entries still showing on olivemenu admin listing even after deleted from entries page
- 32 Is there a way that you can assign a menu item to open in a new tab?

## 1.1.1 - 2020-02-10
### Fixed composer.json to have the correct version

## 1.1.6 - 2020-02-26
### Fixed update plugin + custom child item break
- Custom Child Item breaks the link of parent item #38

## 1.1.7 - 2020-02-28
### Added multisite option

## 1.1.8 - 2020-05-04
### Only version bump

## 1.1.9 - 2020-05-15
### Fixed
- Migrations are not run when updating the plugin #42

## 1.1.10 - 2020-05-15
### Fixed 
- migration/down for multi-site
- set default site ID for multi-site migrations #44
- fix URL routing for multi-site (index and create) #43

## 1.2.0 - 2020-05-18
### Added 
- extra config options for HTML output: without-container and without-ul

## 1.2.1 - 2020-05-20
### Fixed
- Added "site_id" to the install migration

