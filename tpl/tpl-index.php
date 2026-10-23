<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= SITE_TITLE ?></title>
  <link rel="stylesheet" href="<?= site_url('assets/vendor/Font%20Awesome/css/fa-solid-subset.css') ?>">
  <link rel="stylesheet" href="<?= site_url('assets/vendor/Font%20Awesome/css/fa-regular-subset.css') ?>">
  <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css?v=file-manager-7">
</head>

<body>
  <?php
  $rootFolders = $rootFolders ?? [];
  $entries = $entries ?? [];
  $currentFolder = $currentFolder ?? '';
  $currentUserDisplayName = $currentUserDisplayName ?? '';
  $currentPath = $currentPath ?? '';
  $ftpError = $ftpError ?? '';
  $currentUserFolder = $currentUserFolder ?? '';
  $canWriteCurrentFolder = $canWriteCurrentFolder ?? false;
  $canSendFile = $currentFolder && !$canWriteCurrentFolder;
  $storageUsedBytes = (int)($storageUsedBytes ?? 0);
  $storageQuotaBytes = (int)($storageQuotaBytes ?? FM_STORAGE_QUOTA_BYTES);
  $storageWarningBytes = (int)($storageWarningBytes ?? FM_STORAGE_WARNING_BYTES);
  $storagePercent = (int)($storagePercent ?? 0);
  $storageUsedLabel = $storageUsedLabel ?? fmFormatStorageLabel($storageUsedBytes);
  $storageQuotaLabel = $storageQuotaLabel ?? fmFormatStorageLabel($storageQuotaBytes);
  $csrfToken = getCsrfToken();
  $dashboardUrl = function ($folder, $path = '') {
    $params = ['folder' => $folder];
    if ($path !== '') {
      $params['path'] = $path;
    }
    return site_url('?' . http_build_query($params));
  };
  $currentLocationTitle = $currentFolder ? (($currentUserDisplayName ?: $currentFolder) . ($currentPath ? '/' . $currentPath : '')) : 'Files';
  $profileName = function_exists('fmGetUserDisplayName') ? fmGetUserDisplayName($user ?? null) : trim((string)($user->name ?? ''));
  $profileImage = function_exists('fmGetUserProfileImage') ? fmGetUserProfileImage($user ?? null) : site_url('assets/img/profile%20img.avif');
  $profileSubtitle = $currentFolder ? $currentLocationTitle : 'File Manager Workspace';
  ?>
  <div class="page">
    <div class="pageHeader">
      <div class="title">File Manager</div>
      <div class="userPanel">
        <button type="button" class="profile-card" aria-label="User profile">
          <span class="profile-avatar">
            <img src="<?= esc($profileImage) ?>" width="40" height="40" alt="">
          </span>
          <span class="profile-copy">
            <span class="profile-name"><?= esc($profileName !== '' ? $profileName : 'Unknown'); ?></span>
            <span class="profile-subtitle"><?= esc($profileSubtitle); ?></span>
          </span>
          <span class="profile-arrow"><i class="fas fa-caret-down"></i></span>
        </button>
        <a href="<?= site_url("?logout=1") ?>" class="logout-link" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
      </div>
    </div>
    <div class="main">
      <div class="nav">
        <div class="searchbox">
          <div><i class="fas fa-search"></i>
            <input type="search" placeholder="Search users" />
          </div>
        </div>
        <div class="menu">
          <div class="title">Users</div>
          <ul class="folder-list">
            <?php if (sizeof($rootFolders)): ?>
              <?php foreach ($rootFolders as $folder): ?>
                <li class="folder-item <?= ($currentFolder === $folder['name']) ? 'active' : '' ?>"
                  data-search="<?= esc(strtolower(trim(($folder['display_name'] ?? '') . ' ' . ($folder['name'] ?? '')))) ?>">
                  <a href="<?= esc($dashboardUrl($folder['name'])) ?>">
                    <span class="folder-user-avatar">
                      <img src="<?= esc($folder['profile_image'] ?? site_url('assets/img/profile%20img.avif')) ?>" width="28" height="28" alt="">
                    </span>
                    <span class="folder-user-name"><?= esc($folder['display_name'] ?? $folder['name']) ?></span>
                  </a>
                </li>
              <?php endforeach; ?>
              <li class="empty-folder no-match-users" style="display:none;">No matching users</li>
            <?php else: ?>
              <li class="empty-folder">No users</li>
            <?php endif; ?>
          </ul>
        </div>
        <div class="storage-widget <?= $storageUsedBytes >= $storageWarningBytes ? 'is-warning' : '' ?>">
          <div class="storage-widget__head">
            <span class="storage-widget__label"><?= esc($storageUsedLabel) ?> of <?= esc($storageQuotaLabel) ?> used</span>
            <span class="storage-widget__icon" aria-hidden="true"></span>
          </div>
          <div class="storage-widget__bar" aria-hidden="true">
            <span style="width: <?= max(0, min(100, $storagePercent)) ?>%"></span>
          </div>
        </div>
      </div>
      <div class="view">
        <div class="viewHeader">
          <div class="title current-title">
            <?= esc($currentLocationTitle) ?>
            <?php if ($currentFolder && !$canWriteCurrentFolder): ?>
              <span class="access-badge">Read only</span>
            <?php elseif ($currentFolder): ?>
              <span class="access-badge writeable">My folder</span>
            <?php endif; ?>
          </div>
          <div class="functions">
            <button type="button" class="button active" id="uploadBtn" <?= $canSendFile ? '' : 'disabled' ?>>Send File</button>
            <button type="button" class="button" id="downloadBtn" disabled>Download</button>
            <button type="button" class="button delete" id="deleteBtn" disabled>Delete</button>
            <button type="button" class="button download-log" id="downloadLogBtn">Download Log</button>
            <button type="button" class="button send-file-log" id="sendFileLogBtn">Send File Log</button>

            <input type="file" id="uploadInput" hidden>
          </div>
        </div>
        <div class="content">
          <div class="list file-browser">
            <?php if ($ftpError): ?>
              <div class="empty-state error-state"><?= esc($ftpError) ?></div>
            <?php elseif (!$currentFolder): ?>
              <div class="empty-state">No users found.</div>
            <?php else: ?>
              <div class="file-selection-bar">
                <label><input type="checkbox" class="select-all-items" <?= $canWriteCurrentFolder ? '' : 'disabled' ?>> Select all</label>
              </div>
              <ul class="file-list">
                <li class="file-list-header">
                  <span class="file-select-all"><input type="checkbox" class="select-all-items" <?= $canWriteCurrentFolder ? '' : 'disabled' ?>></span>
                  <span class="file-header-name">Name</span>
                  <span class="file-header-size">Size</span>
                  <span class="file-header-modified">Modified</span>
                  <span class="file-header-actions"></span>
                </li>
                <?php if (sizeof($entries)): ?>
                  <?php foreach ($entries as $entry): ?>
                    <li class="file-item <?= $entry['type'] === 'dir' ? 'can-open' : '' ?>"
                      data-type="<?= esc($entry['type']) ?>"
                      data-path="<?= esc($entry['path']) ?>"
                      data-name="<?= esc($entry['name']) ?>"
                      data-open-url="<?= $entry['type'] === 'dir' ? esc($dashboardUrl($currentFolder, $entry['path'])) : '' ?>">
                      <label class="file-select">
                        <input type="checkbox"
                          class="item-checkbox"
                          data-type="<?= esc($entry['type']) ?>"
                          data-path="<?= esc($entry['path']) ?>"
                          data-name="<?= esc($entry['name']) ?>"
                          <?= $canWriteCurrentFolder ? '' : 'disabled' ?>>
                      </label>
                      <i class="fas <?= $entry['type'] === 'dir' ? 'fa-folder' : 'fa-file' ?>"></i>
                      <span class="file-name"><?= esc($entry['name']) ?></span>
                      <span class="file-size"><?= esc($entry['size_label']) ?></span>
                      <span class="file-modified"><?= esc($entry['modified']) ?></span>
                      <span class="file-actions">
                        <?php if ($canWriteCurrentFolder): ?>
                          <button type="button" class="remove delete-file" data-type="<?= esc($entry['type']) ?>" data-path="<?= esc($entry['path']) ?>" data-name="<?= esc($entry['name']) ?>"><i class="fas fa-trash-alt"></i></button>
                        <?php endif; ?>
                      </span>
                    </li>
                  <?php endforeach; ?>
                <?php else: ?>
                  <li class="empty-state">This folder is empty.</li>
                <?php endif; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="download-log-modal" id="downloadLogModal" hidden>
    <div class="download-log-modal__backdrop" data-close-download-log></div>
    <div class="download-log-modal__panel" role="dialog" aria-modal="true" aria-labelledby="downloadLogTitle">
      <div class="download-log-modal__header">
        <h3 id="downloadLogTitle">Download Log</h3>
        <button type="button" class="download-log-modal__close" data-close-download-log aria-label="Close">&times;</button>
      </div>
      <div class="download-log-modal__body">
        <div class="download-log-loading">Loading...</div>
        <div class="download-log-content"></div>
      </div>
    </div>
  </div>
  <script src='https://cdnjs.cloudflare.com/ajax/libs/jquery/2.1.3/jquery.min.js'></script>
  <script>
    window.FileManagerConfig = {
      csrfToken: <?= json_encode($csrfToken) ?>,
      currentFolder: <?= json_encode($currentFolder) ?>,
      canWriteCurrentFolder: <?= json_encode((bool)$canWriteCurrentFolder) ?>,
      canSendFile: <?= json_encode((bool)$canSendFile) ?>
    };
  </script>
  <script src="<?= site_url('assets/js/file-manager.js?v=file-manager-6') ?>"></script>
</body>

</html>
