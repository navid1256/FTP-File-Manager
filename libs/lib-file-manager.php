<?php defined('BASE_PATH') or die("Permision Denied!");

class FileManagerException extends RuntimeException {}

define('FM_LOCAL_ROOT', BASE_PATH . 'users');

function fmGetLocalRoot()
{
    $root = FM_LOCAL_ROOT;

    if (!is_dir($root) && !mkdir($root, 0775, true)) {
        throw new FileManagerException('Could not create local file manager root.');
    }

    $realRoot = realpath($root);
    if ($realRoot === false || !is_dir($realRoot)) {
        throw new FileManagerException('Local file manager root is not available.');
    }

    return $realRoot;
}

function fmGetCurrentUserFolderName()
{
    $user = getLoggedInUser();
    if (($user->storage_driver ?? '') === 'ftp') {
        // This slice provisions FTP homes; do not silently create a second local home.
        throw new FileManagerException('فضای FTP این حساب ساخته شده است؛ مدیریت فایل‌های راه دور هنوز در این نسخه فعال نیست.');
    }
    $name = $user->name ?? '';

    if (trim((string)$name) === '') {
        throw new FileManagerException('Current user does not have a folder name.');
    }

    return fmCleanSingleName($name, 'User folder name');
}

function fmEnsureCurrentUserHome()
{
    $folderName = fmGetCurrentUserFolderName();
    $path = fmBuildLocalPath($folderName);

    if (!is_dir($path) && !mkdir($path, 0775, true)) {
        throw new FileManagerException('Could not create current user folder.');
    }

    return $folderName;
}

function fmGetUserHomes()
{
    $root = fmGetLocalRoot();
    $homes = [];

    foreach (fmFetchUsersForHomes() as $user) {
        try {
            $folderName = fmCleanSingleName($user->name ?? '', 'User folder name');
        } catch (FileManagerException $e) {
            continue;
        }

        $path = $root . DIRECTORY_SEPARATOR . $folderName;
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        if (!is_dir($path)) {
            continue;
        }

        $modified = filemtime($path);
        $homes[] = [
            'name' => $folderName,
            'display_name' => fmGetUserDisplayName($user),
            'profile_image' => fmGetUserProfileImage($user),
            'type' => 'dir',
            'path' => $folderName,
            'size' => null,
            'size_label' => '-',
            'modified' => $modified === false ? '-' : date('Y-m-d H:i', $modified),
            'is_current_user' => $folderName === fmGetCurrentUserFolderName()
        ];
    }

    return $homes;
}

function fmGetUserDisplayName($user)
{
    $firstName = trim((string)($user->first_name ?? ''));
    $lastName = trim((string)($user->last_name ?? ''));
    $fullName = trim($firstName . ' ' . $lastName);
    if ($fullName !== '') {
        return $fullName;
    }

    $name = trim((string)($user->name ?? ''));
    return $name !== '' ? $name : (string)($user->email ?? 'Unknown user');
}

function fmGetUserProfileImage($user)
{
    $imageFields = ['profile_image', 'avatar', 'photo', 'profile_photo'];
    foreach ($imageFields as $field) {
        $image = trim((string)($user->$field ?? ''));
        if ($image === '') {
            continue;
        }

        if (preg_match('/^https?:\/\//i', $image)) {
            return $image;
        }

        return site_url(ltrim(str_replace('\\', '/', $image), '/'));
    }

    return site_url('assets/img/profile%20img.avif');
}

function fmCanWriteFolder($folder)
{
    return fmCleanSingleName($folder, 'Folder') === fmGetCurrentUserFolderName();
}

function fmAssertCanWriteFolder($folder)
{
    if (!fmCanWriteFolder($folder)) {
        throw new FileManagerException('This folder is read-only for your account.');
    }
}

function fmAssertKnownUserFolder($folder)
{
    $folder = fmCleanSingleName($folder, 'Folder');
    foreach (fmGetUserHomes() as $home) {
        if ($home['name'] === $folder) {
            return $folder;
        }
    }

    throw new FileManagerException('Selected user was not found.');
}

function fmAssertCanSendFileToFolder($folder)
{
    $folder = fmAssertKnownUserFolder($folder);
    if (fmCanWriteFolder($folder)) {
        throw new FileManagerException('Send File is only available for other users.');
    }

    return $folder;
}

function fmCleanRelativePath($path, $allowEmpty = true)
{
    $path = str_replace('\\', '/', (string)$path);
    $path = trim($path, '/');

    if ($path === '') {
        if ($allowEmpty) {
            return '';
        }
        throw new FileManagerException('Invalid path.');
    }

    if (strpos($path, "\0") !== false) {
        throw new FileManagerException('Invalid path.');
    }

    $clean = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            throw new FileManagerException('Invalid path.');
        }
        $clean[] = $part;
    }

    return implode('/', $clean);
}

function fmCleanSingleName($name, $label = 'Name')
{
    $name = trim((string)$name);

    if ($name === '' || strpos($name, "\0") !== false || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
        throw new FileManagerException("$label is invalid.");
    }

    if ($name === '.' || $name === '..') {
        throw new FileManagerException("$label is invalid.");
    }

    return $name;
}

/**
 * @return array<int, object>
 */
function fmFetchUsersForHomes()
{
    global $pdo;

    $stmt = $pdo->query("select *, username AS name from users order by username");
    if ($stmt === false) {
        throw new FileManagerException('Could not load users.');
    }

    $users = $stmt->fetchAll(PDO::FETCH_OBJ);
    return is_array($users) ? $users : [];
}

/**
 * @return array<int, string>
 */
function fmReadDirectoryNames($dir)
{
    if (!is_dir($dir)) {
        return [];
    }

    $items = scandir($dir);
    return is_array($items) ? array_values($items) : [];
}

function fmNormalizeAbsolutePath($path)
{
    $normalized = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$path), DIRECTORY_SEPARATOR);
    if ($normalized === '') {
        $normalized = DIRECTORY_SEPARATOR;
    }

    return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
}

function fmFormatStorageLabel($bytes)
{
    $label = fmFormatBytes($bytes);
    return preg_replace('/\.0 ([A-Z]+)$/', ' $1', $label);
}

function fmGetDirectorySize($dir)
{
    if (!is_dir($dir)) {
        return 0;
    }

    $size = 0;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink() || !$item->isFile()) {
                continue;
            }

            $fileSize = $item->getSize();
            if ($fileSize !== false) {
                $size += (int)$fileSize;
            }
        }
    } catch (UnexpectedValueException $e) {
        return 0;
    }

    return $size;
}

function fmGetFolderStorageUsage($folder)
{
    $folder = fmCleanSingleName($folder, 'Folder');
    $localPath = fmBuildLocalPath($folder, true);
    return fmGetDirectorySize($localPath);
}

function fmIsPathInsideRoot($path, $root, $allowRoot = true)
{
    $path = fmNormalizeAbsolutePath($path);
    $root = fmNormalizeAbsolutePath($root);

    if ($allowRoot && $path === $root) {
        return true;
    }

    $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return strpos($path . DIRECTORY_SEPARATOR, $rootPrefix) === 0;
}

function fmJoinRelativePath(...$parts)
{
    $joined = [];
    foreach ($parts as $part) {
        $part = trim((string)$part, '/');
        if ($part !== '') {
            $joined[] = $part;
        }
    }

    return fmCleanRelativePath(implode('/', $joined));
}

function fmBuildLocalPath($relativePath = '', $mustExist = false)
{
    $root = fmGetLocalRoot();
    $relativePath = fmCleanRelativePath($relativePath);

    if ($relativePath === '') {
        return $root;
    }

    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    if ($mustExist) {
        $realPath = realpath($path);
        if ($realPath === false) {
            throw new FileManagerException('Selected path was not found.');
        }

        if (!fmIsPathInsideRoot($realPath, $root)) {
            throw new FileManagerException('Invalid path.');
        }

        return $realPath;
    }

    $parent = realpath(dirname($path));
    if ($parent === false) {
        throw new FileManagerException('Parent folder was not found.');
    }

    if (!fmIsPathInsideRoot($parent, $root)) {
        throw new FileManagerException('Invalid path.');
    }

    return $path;
}

function fmFormatBytes($bytes)
{
    $bytes = max(0, (int)$bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;

    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes = $bytes / 1024;
        $index++;
    }

    return ($index === 0 ? (string)$bytes : number_format($bytes, 1)) . ' ' . $units[$index];
}

function fmListLocalDirectory($dir, $baseRelativePath = '')
{
    $entries = [];
    foreach (fmReadDirectoryNames($dir) as $name) {
        if ($name === '.' || $name === '..' || $name === '.gitkeep') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $name;
        $isDir = is_dir($path);
        $relativePath = fmJoinRelativePath($baseRelativePath, $name);
        $modified = filemtime($path);
        $size = $isDir ? null : filesize($path);

        $entries[] = [
            'name' => $name,
            'type' => $isDir ? 'dir' : 'file',
            'path' => $relativePath,
            'size' => $size === false ? null : (int)$size,
            'size_label' => $isDir ? '-' : fmFormatBytes($size === false ? 0 : (int)$size),
            'modified' => $modified === false ? '-' : date('Y-m-d H:i', $modified)
        ];
    }

    usort($entries, function ($a, $b) {
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'dir' ? -1 : 1;
        }
        return strcasecmp($a['name'], $b['name']);
    });

    return $entries;
}

function fmGetDashboardData($requestedFolder = '', $requestedPath = '')
{
    $currentUserFolder = fmEnsureCurrentUserHome();
    $rootFolders = fmGetUserHomes();

    $currentFolder = '';
    $currentUserDisplayName = '';
    if ($requestedFolder !== '') {
        $requestedFolder = fmCleanSingleName($requestedFolder, 'Folder');
        foreach ($rootFolders as $folder) {
            if ($folder['name'] === $requestedFolder) {
                $currentFolder = $requestedFolder;
                $currentUserDisplayName = $folder['display_name'];
                break;
            }
        }
        if ($currentFolder === '') {
            throw new FileManagerException('Selected folder was not found.');
        }
    } else {
        $currentFolder = $currentUserFolder;
        foreach ($rootFolders as $folder) {
            if ($folder['name'] === $currentFolder) {
                $currentUserDisplayName = $folder['display_name'];
                break;
            }
        }
    }

    $currentPath = fmCleanRelativePath($requestedPath);
    $entries = [];
    $storageUsedBytes = fmGetFolderStorageUsage($currentFolder);
    $storageQuotaBytes = defined('FM_STORAGE_QUOTA_BYTES') ? (int)FM_STORAGE_QUOTA_BYTES : 16106127360;
    $storagePercent = $storageQuotaBytes > 0 ? (int)min(100, ceil(($storageUsedBytes / $storageQuotaBytes) * 100)) : 0;
    if ($currentFolder !== '') {
        $localDir = fmBuildLocalPath(fmJoinRelativePath($currentFolder, $currentPath), true);
        if (!is_dir($localDir)) {
            throw new FileManagerException('Selected path was not found.');
        }
        $entries = fmListLocalDirectory($localDir, $currentPath);
    }

    return [
        'rootFolders' => $rootFolders,
        'currentFolder' => $currentFolder,
        'currentUserDisplayName' => $currentUserDisplayName,
        'currentPath' => $currentPath,
        'entries' => $entries,
        'currentUserFolder' => $currentUserFolder,
        'canWriteCurrentFolder' => fmCanWriteFolder($currentFolder),
        'storageUsedBytes' => $storageUsedBytes,
        'storageQuotaBytes' => $storageQuotaBytes,
        'storagePercent' => $storagePercent,
        'storageUsedLabel' => fmFormatStorageLabel($storageUsedBytes),
        'storageQuotaLabel' => fmFormatStorageLabel($storageQuotaBytes)
    ];
}

function fmUploadFile($folder, $path, array $file)
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new FileManagerException('Upload failed.');
    }

    $folder = fmCleanSingleName($folder, 'Folder');
    fmAssertCanWriteFolder($folder);

    $path = fmCleanRelativePath($path);
    $fileName = fmCleanSingleName(basename((string)$file['name']), 'File name');
    $tmpPath = $file['tmp_name'] ?? '';

    if (!is_uploaded_file($tmpPath)) {
        throw new FileManagerException('Upload file is invalid.');
    }

    $localDir = fmBuildLocalPath(fmJoinRelativePath($folder, $path), true);
    if (!is_dir($localDir)) {
        throw new FileManagerException('Upload folder was not found.');
    }

    $target = $localDir . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($tmpPath, $target)) {
        throw new FileManagerException('Could not upload file.');
    }

    return true;
}

function fmSendFileToUser($folder, array $file)
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new FileManagerException('Upload failed.');
    }

    $folder = fmAssertCanSendFileToFolder($folder);
    $fileName = fmCleanSingleName(basename((string)$file['name']), 'File name');
    $tmpPath = $file['tmp_name'] ?? '';

    if (!is_uploaded_file($tmpPath)) {
        throw new FileManagerException('Upload file is invalid.');
    }

    $localDir = fmBuildLocalPath($folder, true);
    if (!is_dir($localDir)) {
        throw new FileManagerException('Target user folder was not found.');
    }

    $target = $localDir . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($tmpPath, $target)) {
        throw new FileManagerException('Could not send file.');
    }

    fmLogSentFile($folder, $target, $fileName);

    return true;
}

function fmDeleteFile($folder, $path)
{
    return fmDeleteItem($folder, $path);
}

function fmDeleteItem($folder, $path)
{
    $folder = fmCleanSingleName($folder, 'Folder');
    fmAssertCanWriteFolder($folder);

    fmDeleteLocalItem(fmResolveOwnedItemLocalPath($folder, $path));
    return true;
}

function fmDeleteItems($folder, array $items)
{
    $folder = fmCleanSingleName($folder, 'Folder');
    fmAssertCanWriteFolder($folder);

    $resolvedItems = [];
    foreach (fmNormalizeBulkItems($items) as $item) {
        $resolvedItems[] = [
            'path' => $item['path'],
            'localPath' => fmResolveOwnedItemLocalPath($folder, $item['path'])
        ];
    }

    usort($resolvedItems, function ($a, $b) {
        return strlen($b['path']) <=> strlen($a['path']);
    });

    foreach ($resolvedItems as $item) {
        if (file_exists($item['localPath']) || is_link($item['localPath'])) {
            fmDeleteLocalItem($item['localPath']);
        }
    }

    return count($resolvedItems);
}

function fmResolveOwnedItemLocalPath($folder, $path)
{
    $path = fmCleanRelativePath($path, false);
    $userRoot = fmBuildLocalPath($folder, true);
    $localPath = $userRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

    if (!file_exists($localPath) && !is_link($localPath)) {
        throw new FileManagerException('Selected item was not found.');
    }

    if (!is_link($localPath)) {
        $realPath = realpath($localPath);
        if ($realPath === false || !fmIsPathInsideRoot($realPath, $userRoot, false)) {
            throw new FileManagerException('Invalid path.');
        }
    }

    return $localPath;
}

function fmDeleteLocalItem($localPath)
{
    if (is_link($localPath) || is_file($localPath)) {
        if (!unlink($localPath)) {
            throw new FileManagerException('Could not delete file.');
        }
        return true;
    }

    if (is_dir($localPath)) {
        fmDeleteDirectory($localPath);
        return true;
    }

    throw new FileManagerException('Selected item cannot be deleted.');
}

function fmDeleteDirectory($dir)
{
    try {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $path = $item->getPathname();
            if ($item->isLink() || $item->isFile()) {
                if (!unlink($path)) {
                    throw new FileManagerException('Could not delete file.');
                }
                continue;
            }

            if ($item->isDir() && !rmdir($path)) {
                throw new FileManagerException('Could not delete folder.');
            }
        }
    } catch (UnexpectedValueException $e) {
        throw new FileManagerException('Could not read folder.');
    }

    if (!rmdir($dir)) {
        throw new FileManagerException('Could not delete folder.');
    }

    return true;
}

function fmResolveItemLocalPath($folder, $path)
{
    $folder = fmCleanSingleName($folder, 'Folder');
    $path = fmCleanRelativePath($path);
    return fmBuildLocalPath(fmJoinRelativePath($folder, $path), true);
}

function fmPrepareFileDownload($folder, $path)
{
    $folder = fmCleanSingleName($folder, 'Folder');
    fmAssertCanWriteFolder($folder);

    $localPath = fmResolveItemLocalPath($folder, $path);
    if (!is_file($localPath)) {
        throw new FileManagerException('Selected item is not a file.');
    }

    return [
        'path' => $localPath,
        'name' => basename($localPath),
        'type' => 'application/octet-stream',
        'delete_after_send' => false
    ];
}

function fmPrepareFolderDownload($folder, $path)
{
    $folder = fmCleanSingleName($folder, 'Folder');
    fmAssertCanWriteFolder($folder);

    $localPath = fmResolveItemLocalPath($folder, $path);
    if (!is_dir($localPath)) {
        throw new FileManagerException('Selected item is not a folder.');
    }

    $zipPath = tempnam(sys_get_temp_dir(), 'fm_zip_');
    $zip = new ZipArchive();
    if ($zipPath === false || $zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
        throw new FileManagerException('Could not create zip file.');
    }

    $baseName = basename($localPath);
    $zip->addEmptyDir($baseName);
    fmAddLocalDirectoryToZip($localPath, $zip, $baseName);
    $zip->close();

    return [
        'path' => $zipPath,
        'name' => $baseName . '.zip',
        'type' => 'application/zip',
        'delete_after_send' => true
    ];
}

function fmPrepareItemsDownload($folder, array $items)
{
    $folder = fmCleanSingleName($folder, 'Folder');
    fmAssertCanWriteFolder($folder);

    $items = fmNormalizeBulkItems($items);
    if (count($items) === 1) {
        $item = $items[0];
        $localPath = fmResolveItemLocalPath($folder, $item['path']);
        if (is_file($localPath)) {
            return [
                'path' => $localPath,
                'name' => basename($localPath),
                'type' => 'application/octet-stream',
                'delete_after_send' => false
            ];
        }

        if (is_dir($localPath)) {
            return fmPrepareFolderDownload($folder, $item['path']);
        }
    }

    $zipPath = tempnam(sys_get_temp_dir(), 'fm_zip_');
    $zip = new ZipArchive();
    if ($zipPath === false || $zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
        throw new FileManagerException('Could not create zip file.');
    }

    foreach ($items as $item) {
        fmAddItemToZip($folder, $item['path'], $zip);
    }
    $zip->close();

    return [
        'path' => $zipPath,
        'name' => 'selected-items.zip',
        'type' => 'application/zip',
        'delete_after_send' => true
    ];
}

function fmNormalizeBulkItems(array $items)
{
    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new FileManagerException('Selected items are invalid.');
        }

        $path = fmCleanRelativePath($item['path'] ?? '', false);
        $type = ($item['type'] ?? '') === 'dir' ? 'dir' : 'file';

        $normalized[$path] = [
            'path' => $path,
            'type' => $type,
            'name' => trim((string)($item['name'] ?? basename($path)))
        ];
    }

    if (!count($normalized)) {
        throw new FileManagerException('Select at least one item.');
    }

    return array_values($normalized);
}

function fmAddItemToZip($folder, $path, ZipArchive $zip)
{
    $path = fmCleanRelativePath($path, false);
    $localPath = fmResolveItemLocalPath($folder, $path);

    if (is_dir($localPath)) {
        $zip->addEmptyDir($path);
        fmAddLocalDirectoryToZip($localPath, $zip, $path);
        return;
    }

    if (is_file($localPath)) {
        $zip->addFile($localPath, $path);
        return;
    }

    throw new FileManagerException('Selected item cannot be downloaded.');
}

function fmAddLocalDirectoryToZip($dir, ZipArchive $zip, $zipDir)
{
    foreach (fmReadDirectoryNames($dir) as $item) {
        if ($item === '.' || $item === '..' || $item === '.gitkeep') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;
        $zipPath = $zipDir . '/' . $item;

        if (is_dir($path)) {
            $zip->addEmptyDir($zipPath);
            fmAddLocalDirectoryToZip($path, $zip, $zipPath);
            continue;
        }

        if (is_file($path)) {
            $zip->addFile($path, $zipPath);
        }
    }
}

function fmGetCurrentUserLogDirectory()
{
    $userFolder = fmGetCurrentUserFolderName();
    $dir = BASE_PATH . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'users' . DIRECTORY_SEPARATOR . $userFolder;
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new FileManagerException('Could not create user log directory.');
        }
    }

    return $dir;
}

function fmGetCurrentUserLogPath($fileName)
{
    $fileName = fmCleanSingleName($fileName, 'Log file name');
    $path = fmGetCurrentUserLogDirectory() . DIRECTORY_SEPARATOR . $fileName;

    if (!file_exists($path)) {
        $handle = fopen($path, 'ab');
        if (!$handle) {
            throw new FileManagerException('Could not create user log file.');
        }
        fclose($handle);
    }

    return $path;
}

function fmGetDownloadLogPath()
{
    return fmGetCurrentUserLogPath('downloads.jsonl');
}

function fmGetSendFileLogPath()
{
    return fmGetCurrentUserLogPath('sent-files.jsonl');
}

function fmGetLegacyDownloadLogPath()
{
    $dir = BASE_PATH . 'storage' . DIRECTORY_SEPARATOR . 'logs';
    return $dir . DIRECTORY_SEPARATOR . 'downloads.jsonl';
}

function fmGetLegacySendFileLogPath()
{
    $dir = BASE_PATH . 'storage' . DIRECTORY_SEPARATOR . 'logs';
    return $dir . DIRECTORY_SEPARATOR . 'sent-files.jsonl';
}

function fmBuildDownloadLogItems(array $request, array $download)
{
    $items = [];

    if (isset($request['items']) && is_array($request['items'])) {
        foreach (fmNormalizeBulkItems($request['items']) as $item) {
            $items[] = [
                'name' => $item['name'] !== '' ? $item['name'] : basename($item['path']),
                'path' => $item['path'],
                'type' => $item['type']
            ];
        }
        return $items;
    }

    $path = fmCleanRelativePath($request['path'] ?? '', false);
    $items[] = [
        'name' => basename($path),
        'path' => $path,
        'type' => ($request['type'] ?? '') === 'dir' ? 'dir' : 'file'
    ];

    return $items;
}

function fmLogDownload(array $download, array $request)
{
    try {
        if (empty($download['path']) || !is_file($download['path'])) {
            return false;
        }

        $user = getLoggedInUser();
        $fileSize = filesize($download['path']);
        $record = [
            'downloaded_at' => date('Y-m-d H:i:s'),
            'user_id' => (int)getCurrentUserId(),
            'user_name' => (string)($user->name ?? ''),
            'user_display_name' => function_exists('fmGetUserDisplayName') ? fmGetUserDisplayName($user) : (string)($user->name ?? ''),
            'folder' => fmCleanSingleName($request['folder'] ?? '', 'Folder'),
            'download_name' => (string)($download['name'] ?? basename($download['path'])),
            'download_type' => (string)($download['type'] ?? 'application/octet-stream'),
            'size' => $fileSize === false ? 0 : (int)$fileSize,
            'size_label' => fmFormatBytes($fileSize === false ? 0 : (int)$fileSize),
            'items' => fmBuildDownloadLogItems($request, $download)
        ];

        $path = fmGetDownloadLogPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return false;
        }

        $handle = fopen($path, 'ab');
        if (!$handle) {
            return false;
        }

        try {
            if (flock($handle, LOCK_EX)) {
                fwrite($handle, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
                fflush($handle);
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function fmGetCurrentUserDownloadLogs($limit = 100)
{
    $limit = max(1, min(500, (int)$limit));
    $user = getLoggedInUser();
    $userId = (int)getCurrentUserId();
    $userName = (string)($user->name ?? '');
    $paths = array_unique([fmGetDownloadLogPath(), fmGetLegacyDownloadLogPath()]);

    $logs = [];
    foreach ($paths as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            continue;
        }

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $record = json_decode($lines[$i], true);
            if (!is_array($record)) {
                continue;
            }

            $recordUserId = (int)($record['user_id'] ?? 0);
            $recordUserName = (string)($record['user_name'] ?? '');
            if (($userId > 0 && $recordUserId === $userId) || ($recordUserName !== '' && $recordUserName === $userName)) {
                $logs[] = [
                    'downloaded_at' => (string)($record['downloaded_at'] ?? ''),
                    'download_name' => (string)($record['download_name'] ?? ''),
                    'size_label' => (string)($record['size_label'] ?? ''),
                    'items' => is_array($record['items'] ?? null) ? $record['items'] : []
                ];
            }

            if (count($logs) >= $limit) {
                break 2;
            }
        }
    }

    return $logs;
}

function fmGetUserDisplayNameByFolder($folder)
{
    $folder = fmCleanSingleName($folder, 'Folder');
    foreach (fmGetUserHomes() as $home) {
        if (($home['name'] ?? '') === $folder) {
            return (string)($home['display_name'] ?? $folder);
        }
    }

    return $folder;
}

function fmLogSentFile($targetFolder, $targetPath, $fileName)
{
    try {
        if (empty($targetPath) || !is_file($targetPath)) {
            return false;
        }

        $user = getLoggedInUser();
        $fileSize = filesize($targetPath);
        $targetFolder = fmCleanSingleName($targetFolder, 'Folder');
        $record = [
            'sent_at' => date('Y-m-d H:i:s'),
            'sender_id' => (int)getCurrentUserId(),
            'sender_name' => (string)($user->name ?? ''),
            'sender_display_name' => function_exists('fmGetUserDisplayName') ? fmGetUserDisplayName($user) : (string)($user->name ?? ''),
            'target_folder' => $targetFolder,
            'target_display_name' => fmGetUserDisplayNameByFolder($targetFolder),
            'file_name' => (string)$fileName,
            'size' => $fileSize === false ? 0 : (int)$fileSize,
            'size_label' => fmFormatBytes($fileSize === false ? 0 : (int)$fileSize)
        ];

        $path = fmGetSendFileLogPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return false;
        }

        $handle = fopen($path, 'ab');
        if (!$handle) {
            return false;
        }

        try {
            if (flock($handle, LOCK_EX)) {
                fwrite($handle, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
                fflush($handle);
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function fmGetCurrentUserSendFileLogs($limit = 100)
{
    $limit = max(1, min(500, (int)$limit));
    $user = getLoggedInUser();
    $userId = (int)getCurrentUserId();
    $userName = (string)($user->name ?? '');
    $paths = array_unique([fmGetSendFileLogPath(), fmGetLegacySendFileLogPath()]);

    $logs = [];
    foreach ($paths as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            continue;
        }

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $record = json_decode($lines[$i], true);
            if (!is_array($record)) {
                continue;
            }

            $recordUserId = (int)($record['sender_id'] ?? 0);
            $recordUserName = (string)($record['sender_name'] ?? '');
            if (($userId > 0 && $recordUserId === $userId) || ($recordUserName !== '' && $recordUserName === $userName)) {
                $logs[] = [
                    'sent_at' => (string)($record['sent_at'] ?? ''),
                    'target_display_name' => (string)($record['target_display_name'] ?? ''),
                    'target_folder' => (string)($record['target_folder'] ?? ''),
                    'file_name' => (string)($record['file_name'] ?? ''),
                    'size_label' => (string)($record['size_label'] ?? '')
                ];
            }

            if (count($logs) >= $limit) {
                break 2;
            }
        }
    }

    return $logs;
}
