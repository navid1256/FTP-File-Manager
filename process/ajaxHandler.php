<?php
include_once "../bootstrap/init.php";

if(!isAjaxRequest()){
    diePage("Invalid Request!");
}

if(!isset($_POST['action']) || empty($_POST['action'])){
    diePage("Invalid Action!");
}

if(!isLoggedIn()){
    diePage("Unauthorized!");
}

if(!verifyCsrfToken($_POST['csrf_token'] ?? '')){
    diePage("Invalid CSRF token!");
}

header('Content-Type: application/json; charset=utf-8');

function jsonResponse($ok, $message = '', $data = []){
    echo json_encode([
        'ok' => (bool)$ok,
        'message' => (string)$message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    die();
}

try {
    switch($_POST['action']){
        case "deleteFile":
        case "deleteItem":
            if(!isset($_POST['folder']) || !isset($_POST['path'])){
                jsonResponse(false, "Item path is required.");
            }

            fmDeleteItem($_POST['folder'], $_POST['path']);
            jsonResponse(true, "Item deleted.");
        break;

        case "deleteItems":
            if(!isset($_POST['folder']) || !isset($_POST['items']) || !is_array($_POST['items'])){
                jsonResponse(false, "Selected items are required.");
            }

            $deletedCount = fmDeleteItems($_POST['folder'], $_POST['items']);
            jsonResponse(true, "$deletedCount item(s) deleted.");
        break;

        case "uploadFile":
            if(!isset($_FILES['file'])){
                jsonResponse(false, "Please choose a file.");
            }

            fmSendFileToUser($_POST['folder'] ?? '', $_FILES['file']);
            jsonResponse(true, "File sent.");
        break;

        case "getDownloadLog":
            jsonResponse(true, "", [
                'logs' => fmGetCurrentUserDownloadLogs()
            ]);
        break;

        case "getSendFileLog":
            jsonResponse(true, "", [
                'logs' => fmGetCurrentUserSendFileLogs()
            ]);
        break;

        default:
            jsonResponse(false, "Invalid Action!");
    }
} catch (FileManagerException $e) {
    jsonResponse(false, $e->getMessage());
}
