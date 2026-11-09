<?php

/**
 * SPDX-FileCopyrightText: 2026 CDNTV
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/** @var \OCP\IL10N $l */
/** @var array $_ */

$error = $_['error'] ?? '';
$isAdmin = $_['isAdmin'] ?? false;
$canUploadAll = $_['canUploadAll'] ?? false;

\OCP\Util::addStyle('appdrop', 'admin');
\OCP\Util::addScript('appdrop', 'navigation');
\OCP\Util::addScript('appdrop', 'upload');
\OCP\Util::addScript('appdrop', 'apps');
if ($canUploadAll) {
	\OCP\Util::addScript('appdrop', 'history');
	\OCP\Util::addScript('appdrop', 'templates');
}
\OCP\Util::addScript('appdrop', 'backups');
if ($isAdmin) {
	\OCP\Util::addScript('appdrop', 'permissions');
}
?>

<div id="app-navigation">
        <ul>
            <li class="active" data-section="upload">
                <a href="#upload">
                    <svg class="aum-nav-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="16 16 12 12 8 16"></polyline>
                        <line x1="12" y1="12" x2="12" y2="21"></line>
                        <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"></path>
                        <polyline points="16 16 12 12 8 16"></polyline>
                    </svg>
                    <?php p($l->t('Upload')); ?>
                </a>
            </li>
            <?php if ($isAdmin): ?>
            <li data-section="apps">
                <a href="#apps">
                    <svg class="aum-nav-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="7"></rect>
                        <rect x="14" y="3" width="7" height="7"></rect>
                        <rect x="14" y="14" width="7" height="7"></rect>
                        <rect x="3" y="14" width="7" height="7"></rect>
                    </svg>
                    <?php p($l->t('Apps')); ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if ($canUploadAll): ?>
            <li data-section="history">
                <a href="#history">
                    <svg class="aum-nav-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <?php p($l->t('History')); ?>
                </a>
            </li>
            <li data-section="generator">
                <a href="#generator">
                    <svg class="aum-nav-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15 4V2"></path>
                        <path d="M15 16v-2"></path>
                        <path d="M8 9h2"></path>
                        <path d="M20 9h2"></path>
                        <path d="M17.8 11.8L19 13"></path>
                        <path d="M15 9h0"></path>
                        <path d="M17.8 6.2L19 5"></path>
                        <path d="M3 21l9-9"></path>
                        <path d="M12.2 6.2L11 5"></path>
                    </svg>
                    <?php p($l->t('Generator')); ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
            <li data-section="backups">
                <a href="#backups">
                    <svg class="aum-nav-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                        <path d="M12 4v16"></path>
                        <path d="M2 12h20"></path>
                    </svg>
                    <?php p($l->t('Backups')); ?>
                </a>
            </li>
            <li data-section="permissions">
                <a href="#permissions">
                    <svg class="aum-nav-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                    <?php p($l->t('Permissions')); ?>
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <div id="app-settings">
            <div id="app-settings-header">
                <button class="settings-button" data-apps-slide-toggle="#app-settings-content">
                    <?php p($l->t('Settings')); ?>
                </button>
            </div>
            <div id="app-settings-content">
                <p class="aum-settings-version">AppDrop v<?php p(\OCP\Server::get(\OCP\App\IAppManager::class)->getAppVersion('appdrop')); ?></p>
            </div>
        </div>
    </div>

<div id="app-content">
        <?php if ($error): ?>
            <div class="aum-alert aum-alert--error"><?php p($error); ?></div>
        <?php endif; ?>

        <div class="aum-section aum-section--active" data-section="upload">
            <?php require __DIR__ . '/partials/upload.php'; ?>
        </div>
        <?php if ($isAdmin): ?>
        <div class="aum-section" data-section="apps">
            <?php require __DIR__ . '/partials/apps.php'; ?>
        </div>
        <?php endif; ?>
        <?php if ($canUploadAll): ?>
        <div class="aum-section" data-section="history">
            <?php require __DIR__ . '/partials/history.php'; ?>
        </div>
        <div class="aum-section" data-section="generator">
            <?php require __DIR__ . '/partials/templates.php'; ?>
        </div>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
        <div class="aum-section" data-section="backups">
            <?php require __DIR__ . '/partials/backups.php'; ?>
        </div>
        <div class="aum-section" data-section="permissions">
            <?php require __DIR__ . '/partials/permissions.php'; ?>
        </div>
        <?php endif; ?>
</div>
