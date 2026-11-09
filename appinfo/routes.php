<?php

/**
 * SPDX-FileCopyrightText: 2026 CDNTV
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * AppDrop – routes
 *
 * Admin-only routes use denyIfNotAdmin(); upload-permission routes use denyIfCannotUpload().
 * CSRF protection is active by default on POST routes.
 */
return [
	'routes' => [
		// Main page
		['name' => 'admin#index',     'url' => '/admin',           'verb' => 'GET'],
		['name' => 'admin#install',   'url' => '/admin/install',   'verb' => 'POST'],

		// Health Check (pre-install validation)
		['name' => 'health_check#validate', 'url' => '/admin/validate', 'verb' => 'POST'],

		// Template Generator
		['name' => 'template_generator#generate', 'url' => '/admin/generate', 'verb' => 'POST'],

		// App Manager (admin-only)
		['name' => 'app_manager#list',    'url' => '/admin/apps',          'verb' => 'GET'],
		['name' => 'app_manager#enable',  'url' => '/admin/apps/enable',   'verb' => 'POST'],
		['name' => 'app_manager#disable', 'url' => '/admin/apps/disable',  'verb' => 'POST'],
		['name' => 'app_manager#remove',  'url' => '/admin/apps/remove',   'verb' => 'POST'],

		// Upload History
		['name' => 'history#list', 'url' => '/admin/history', 'verb' => 'GET'],

		// Backup Management (admin-only)
		['name' => 'backup#list',    'url' => '/admin/backups',          'verb' => 'GET'],
		['name' => 'backup#restore', 'url' => '/admin/backups/restore',  'verb' => 'POST'],
		['name' => 'backup#delete',  'url' => '/admin/backups/delete',   'verb' => 'POST'],

		// Settings (admin-only)
		['name' => 'settings#get',  'url' => '/admin/settings',      'verb' => 'GET'],
		['name' => 'settings#save', 'url' => '/admin/settings/save', 'verb' => 'POST'],

		// Permissions (admin-only)
		['name' => 'permission#get',           'url' => '/admin/permissions',              'verb' => 'GET'],
		['name' => 'permission#addUser',       'url' => '/admin/permissions/users',        'verb' => 'POST'],
		['name' => 'permission#removeUser',    'url' => '/admin/permissions/users/remove', 'verb' => 'POST'],
		['name' => 'permission#addGroup',      'url' => '/admin/permissions/groups',       'verb' => 'POST'],
		['name' => 'permission#removeGroup',   'url' => '/admin/permissions/groups/remove','verb' => 'POST'],
		['name' => 'permission#getUpdateGrants',    'url' => '/admin/permissions/updates',        'verb' => 'GET'],
		['name' => 'permission#addUpdateGrant',     'url' => '/admin/permissions/updates',        'verb' => 'POST'],
		['name' => 'permission#removeUpdateGrant',  'url' => '/admin/permissions/updates/remove', 'verb' => 'POST'],
		['name' => 'permission#searchUsers',   'url' => '/admin/permissions/search/users', 'verb' => 'GET'],
		['name' => 'permission#searchGroups',  'url' => '/admin/permissions/search/groups','verb' => 'GET'],
	],
];
