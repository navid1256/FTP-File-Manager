<?php
include_once "../bootstrap/init.php";

if(!isLoggedIn()){
    diePage("Unauthorized!");
}

$request = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;

if(!verifyCsrfToken($request['csrf_token'] ?? '')){
    diePage("Invalid CSRF token!");
}

$download = null;

try {
    if(isset($request['items'])){
        if(!is_array($request['items'])){
            throw new FileManagerException('Selected items are invalid.');
        }
        $download = fmPrepareItemsDownload($request['folder'] ?? '', $request['items']);
    } else {
        $type = $request['type'] ?? 'file';
        if($type === 'dir'){
            $download = fmPrepareFolderDownload($request['folder'] ?? '', $request['path'] ?? '');
        } else {
            $download = fmPrepareFileDownload($request['folder'] ?? '', $request['path'] ?? '');
        }
    }

    if(!is_file($download['path'])){
        throw new FileManagerException('Download file was not prepared.');
    }

    fmLogDownload($download, $request);

    header('Content-Type: ' . $download['type']);
    header('Content-Disposition: attachment; filename="' . addcslashes($download['name'], '"\\') . '"');
    header('Content-Length: ' . filesize($download['path']));
    header('X-Content-Type-Options: nosniff');
    readfile($download['path']);
} catch (FileManagerException $e) {
    diePage($e->getMessage());
} finally {
    if($download && !empty($download['delete_after_send']) && isset($download['path']) && is_file($download['path'])){
        @unlink($download['path']);
    }
}
