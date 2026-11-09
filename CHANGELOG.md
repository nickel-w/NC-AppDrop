# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Nextcloud 34 and 35 support (`max-version="35"`)
- Per-app update permissions: admins can allow individual users or groups to update specific installed apps (Permissions tab). These users only see the Upload tab, cannot install new apps or change whether an app is enabled, and an update is only accepted if the package is newer than the installed version and meets all requirements of the server (Nextcloud min/max version, PHP min/max version, `<lib>` PHP extensions)
- Health check compares the package's Nextcloud range with the running server and checks PHP max-version and required PHP extensions
- PHP 8.5 added to the CI test matrix (PHP 8.2 removed from CI: `nextcloud/ocp` 35 requires PHP 8.3+)

### Changed
- Development dependency `nextcloud/ocp` raised to `^35.0` (Composer platform PHP 8.3); release-signing container uses `nextcloud:35`

## [1.4.0] - 2026-09-09

### Added
- Nextcloud 33 support (`max-version="33"`); verified end-to-end on 33.0.8 and 30.0.17

### Fixed
- Updating an app (or restoring a backup / re-installing after a remove) left `installed_version` behind, so Nextcloud answered 503 "upgrade needed" to every request until `occ upgrade` was run manually. AppDrop now runs the app's upgrade routine itself (`IAppManager::upgradeApp()` on NC 32+, `OC_App::updateApp()` on NC 30/31)
- `AppInstallException` moved to its own file: it was only autoloadable after `AppInstallService` had been loaded, which turned errors in the Backups/Apps tabs into a fatal "Class not found"
- Generated app skeletons hard-coded `max-version="32"` and could not be enabled on NC 33; the value now follows the running server
- Settings tab showed a stale hard-coded version label

### Changed
- Controllers use `#[NoAdminRequired]` / `#[NoCSRFRequired]` attributes instead of docblock annotations (deprecated, logged on every request by NC 33); the generated skeleton controller does the same
- Upload form uses the template `requesttoken` instead of the deprecated `Util::callRegister()`
- Dev dependency `nextcloud/ocp` pinned to `^33.0` (`dev-master` now requires PHP 8.3); CI matrix is PHP 8.2–8.4. Runtime requirement stays PHP 8.1+ for NC 30

## [1.3.4] - 2026-05-28

### Fixed
- Use the App Store schema's enumerated SPDX casing (`AGPL-3.0-or-later`) in `info.xml` — the lowercase form was rejected by the store's `info.xsd` validation

## [1.3.3] - 2026-05-28

### Fixed
- Sign the App Store package against the shipped tree instead of the dev tree, so Nextcloud's integrity check no longer reports missing files at install time
- Unify packaging excludes into a single `.nextcloudignore` consumed by both the script and the release workflow

### Added
- `--register-sig` helper to print the one-time signature required by the App Store "Register app" form
- Package contract test (`tests/test-package.sh`) wired into CI

## [1.3.2] - 2026-03-18

### Fixed
- Strengthen badge colors for status indicators (Enabled/Disabled/Error) to be legible in both dark and light themes

## [1.3.1] - 2026-03-18

### Fixed
- Remove max-width cap on content sections so they fill the full viewport
- Fix apps table using `max-content` width instead of filling available space

## [1.3.0] - 2026-03-04

### Added
- Detailed health check checklist with visual indicators and per-check status
- Fix hints in health check results to guide users on resolving issues
- Self-update prevention: blocks uploading AppDrop through itself

### Fixed
- Always register navigation entry to prevent Nextcloud 32 crash
- Icon color for light theme compatibility
- Hide navigation from users without upload permission
- Add `@NoAdminRequired` to controllers to allow permitted non-admin users
- Strengthen warning/error colors for better contrast in light theme

## [1.2.0] - 2026-03-04

### Added
- Nextcloud-standard sidebar navigation (replaces horizontal tabs)
- Permission system: admin can grant upload access to specific users and groups
- Permissions management UI with user/group search autocomplete
- PermissionService for access control via IConfig app values
- PermissionController with CRUD and search API endpoints

### Changed
- UI redesigned to follow Nextcloud's `#app-navigation` / `#app-content` layout pattern
- Admin-only sections (Apps, Backups, Permissions) hidden from non-admin users via PHP
- Upload, History, Generator sections accessible to permitted users (not just admins)
- Generator section improved: renamed to "App Skeleton Generator", added file structure preview
- All upload-related controllers now use `canUpload()` instead of `denyIfNotAdmin()`
- Bumped version to 1.2.0

### Removed
- Horizontal tab navigation (tabs.php, tabs.js)
- Legacy admin.js upload handler

## [1.1.0] - 2026-03-04

### Added
- Tab-based UI with 5 sections: Upload, Apps, History, Generator, Backups
- Drag & drop upload zone for .zip files
- Multi-file upload with sequential processing and per-file status
- Pre-install health check validation (errors and warnings)
- App Manager: list, enable, disable, remove custom apps
- Backup Management: view, restore, delete backups created during updates
- Template Generator: generate and download Nextcloud app skeleton .zip
- Upload History with database persistence and paginated view
- Dark mode support via Nextcloud CSS variables
- Responsive design for mobile screens

### Changed
- Extracted `AppPathResolver` as shared utility service
- Extracted `AdminAuthTrait` for consistent admin checks across controllers
- Expanded CSS with tables, badges, dropzone, and responsive styles
- Bumped version to 1.1.0

## [1.0.0] - 2026-03-04

### Added
- Upload and install Nextcloud app packages (.zip) via web UI
- Automatic validation of uploaded packages (Zip Slip protection, MIME type, file size, app ID format)
- Automatic backup of existing app before updates
- Admin-only access enforcement
- Settings panel integration in Nextcloud admin settings
- Client-side file validation (extension, size)
- Inline success/error messaging
- Support for top-level wrapper directory detection in zip files
