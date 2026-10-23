<?php
include_once "bootstrap/init.php";

if (isset($_GET['logout'])) {
    logout();
    redirect(site_url('auth.php'));
}

if (!isLoggedIn()) {
    // redirect to auth form
    redirect(site_url('auth.php'));
}
# user is LoggedIn
$user = getLoggedInUser();

$rootFolders = [];
$currentFolder = '';
$currentUserDisplayName = '';
$currentPath = '';
$entries = [];
$ftpError = '';
$currentUserFolder = '';
$canWriteCurrentFolder = false;

try {
    $dashboardData = fmGetDashboardData($_GET['folder'] ?? '', $_GET['path'] ?? '');
    $rootFolders = $dashboardData['rootFolders'];
    $currentFolder = $dashboardData['currentFolder'];
    $currentUserDisplayName = $dashboardData['currentUserDisplayName'];
    $currentPath = $dashboardData['currentPath'];
    $entries = $dashboardData['entries'];
    $currentUserFolder = $dashboardData['currentUserFolder'];
    $canWriteCurrentFolder = $dashboardData['canWriteCurrentFolder'];
} catch (FileManagerException $e) {
    $ftpError = $e->getMessage();
}

include_once "tpl/tpl-index.php";
