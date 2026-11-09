<?php

/**
 * SPDX-FileCopyrightText: 2026 CDNTV
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var \OCP\IL10N $l */
/** @var array $_ */
$maxSizeMB = $_['maxSizeMB'] ?? 20;
$autoEnableDefault = $_['autoEnableDefault'] ?? true;
$canUploadAll = $_['canUploadAll'] ?? false;
$updatableApps = $_['updatableApps'] ?? [];
?>

<h2 class="aum-section-title"><?php p($l->t('Upload')); ?></h2>
<?php if ($canUploadAll): ?>
<p class="aum-subtitle">
    <?php p($l->t('Upload Nextcloud app packages (.zip) to install or update. The app ID and version are detected automatically from info.xml.')); ?>
</p>
<?php else: ?>
<p class="aum-subtitle">
    <?php p($l->t('You may update the following apps: %s', [implode(', ', $updatableApps)])); ?>
</p>
<p class="aum-hint">
    <?php p($l->t('New apps cannot be installed. A package is only accepted if it is newer than the installed version and meets all requirements of this server (Nextcloud and PHP version, PHP extensions).')); ?>
</p>
<?php endif; ?>

<div id="aum-message" class="aum-alert aum-alert--hidden" role="status" aria-live="polite"></div>

<form id="aum-form"
      method="post"
      action="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('appdrop.admin.install')); ?>"
      enctype="multipart/form-data"
      novalidate>

    <input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
    <input type="hidden" id="aum-max-size-mb" value="<?php p($maxSizeMB); ?>">

    <!-- Dropzone wrapper -->
    <div id="aum-dropzone" class="aum-dropzone">
        <div class="aum-dropzone__content">
            <span class="aum-dropzone__icon" aria-hidden="true">📦</span>
            <p class="aum-dropzone__text">
                <?php p($l->t('Drag & drop .zip files here or click to browse')); ?>
            </p>
            <input id="aum-zipfile"
                   name="zipFile"
                   type="file"
                   class="aum-dropzone__input"
                   accept=".zip,application/zip"
                   multiple
                   required>
        </div>
        <span class="aum-hint">
            <?php p($l->t('Max %s MB per file. Must contain appinfo/info.xml. Existing apps are automatically backed up before update.', [$maxSizeMB])); ?>
        </span>
    </div>

    <!-- File queue -->
    <div id="aum-file-queue" class="aum-file-queue aum-file-queue--hidden"></div>

    <?php if ($canUploadAll): ?>
    <!-- Options -->
    <div class="aum-field aum-field--inline">
        <label class="aum-checkbox-label">
            <input id="aum-auto-enable" type="checkbox" <?php if ($autoEnableDefault): ?>checked<?php endif; ?>>
            <?php p($l->t('Auto-enable app after install')); ?>
        </label>
    </div>
    <?php endif; ?>

    <!-- Submit -->
    <div class="aum-field">
        <button id="aum-submit"
                type="submit"
                class="aum-btn aum-btn--primary">
            <span class="aum-btn__icon" aria-hidden="true">⇪</span>
            <?php p($canUploadAll ? $l->t('Install / Update') : $l->t('Update')); ?>
        </button>
        <span id="aum-spinner" class="aum-spinner aum-spinner--hidden" aria-hidden="true"></span>
    </div>

</form>
