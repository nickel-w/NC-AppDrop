<?php

/**
 * SPDX-FileCopyrightText: 2026 CDNTV
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var \OCP\IL10N $l */
?>

<h2 class="aum-section-title"><?php p($l->t('Permissions')); ?></h2>
<p class="aum-subtitle">
    <?php p($l->t('Administrators always have full access. Add groups or individual users below to grant upload permission.')); ?>
</p>

<div id="aum-perm-message" class="aum-alert aum-alert--hidden" role="status" aria-live="polite"></div>

<!-- Allowed Groups -->
<div class="aum-perm-block">
    <h3 class="aum-perm-heading"><?php p($l->t('Allowed Groups')); ?></h3>
    <div class="aum-perm-search">
        <input id="aum-perm-group-search" type="text" class="aum-input"
               placeholder="<?php p($l->t('Search groups...')); ?>" autocomplete="off">
        <div id="aum-perm-group-results" class="aum-autocomplete"></div>
    </div>
    <ul id="aum-perm-group-list" class="aum-perm-list"></ul>
</div>

<!-- Allowed Users -->
<div class="aum-perm-block">
    <h3 class="aum-perm-heading"><?php p($l->t('Allowed Users')); ?></h3>
    <div class="aum-perm-search">
        <input id="aum-perm-user-search" type="text" class="aum-input"
               placeholder="<?php p($l->t('Search users...')); ?>" autocomplete="off">
        <div id="aum-perm-user-results" class="aum-autocomplete"></div>
    </div>
    <ul id="aum-perm-user-list" class="aum-perm-list"></ul>
</div>

<!-- Per-app update permissions -->
<div class="aum-perm-block">
    <h3 class="aum-perm-heading"><?php p($l->t('Update permissions for individual apps')); ?></h3>
    <p class="aum-hint">
        <?php p($l->t('Users and groups listed here may only update the selected app — they cannot install new apps. An update is only accepted if the package is newer than the installed version and meets all requirements of this server (Nextcloud and PHP version, PHP extensions).')); ?>
    </p>
    <div class="aum-grant-form">
        <select id="aum-grant-app" class="aum-input" aria-label="<?php p($l->t('App')); ?>">
            <option value=""><?php p($l->t('Select app...')); ?></option>
        </select>
        <select id="aum-grant-type" class="aum-input" aria-label="<?php p($l->t('Type')); ?>">
            <option value="group"><?php p($l->t('Group')); ?></option>
            <option value="user"><?php p($l->t('User')); ?></option>
        </select>
        <div class="aum-perm-search">
            <input id="aum-grant-search" type="text" class="aum-input"
                   placeholder="<?php p($l->t('Search users or groups...')); ?>" autocomplete="off">
            <div id="aum-grant-results" class="aum-autocomplete"></div>
        </div>
    </div>
    <ul id="aum-grant-list" class="aum-perm-list"></ul>
</div>
