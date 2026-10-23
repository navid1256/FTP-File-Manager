<?php defined('BASE_PATH') OR die("Permision Denied!");
// alternative to top code
// if(!defined('BASE_PATH')){
//     echo "Permision Denied!";
//     die();
// }

/*** Folder Function ***/
function deleteFolder($folder_id){
    global $pdo;
    $current_user_id = getCurrentUserId();
    $sql = "delete from folders where id = :folder_id and user_id = :user_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':folder_id' => (int)$folder_id, ':user_id' => (int)$current_user_id]);
    return $stmt->rowCount();
}

function addFolder($folder_name){
    global $pdo;
    $current_user_id = getCurrentUserId();
    $sql = "INSERT INTO `folders` (name,user_id) VALUES (:folder_name,:user_id);";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':folder_name'=>$folder_name,':user_id'=>$current_user_id]);
    return $stmt->rowCount();
}
function doneSwith($task_id){
    global $pdo;
    $current_user_id = getCurrentUserId();
    $sql = "Update `tasks` set is_done = 1 - is_done where user_id = :userID and id = :taskID";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':taskID'=>$task_id,':userID'=>$current_user_id]);
    return $stmt->rowCount();
}

function getFolders(){
    global $pdo;
    $current_user_id = getCurrentUserId();
    $sql = "select * from folders where user_id = :user_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => (int)$current_user_id]);
    $records = $stmt->fetchAll(PDO::FETCH_OBJ);
    return $records;
}

/*** Tasks Function ***/
function deleteTask(int $task_id){
    global $pdo;
    $current_user_id = getCurrentUserId();
    $sql = "delete from tasks where id = :taskID and user_id = :user_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':taskID'=>$task_id, ':user_id' => (int)$current_user_id]);
    return $stmt->rowCount();
}

function addTask($taskTitle,$folderId){
    global $pdo;
    $current_user_id = getCurrentUserId();
    $folderCheck = $pdo->prepare("select id from folders where id = :folder_id and user_id = :user_id limit 1");
    $folderCheck->execute([':folder_id' => (int)$folderId, ':user_id' => (int)$current_user_id]);
    if(!$folderCheck->fetch(PDO::FETCH_OBJ)){
        return 0;
    }
    $sql = "INSERT INTO `tasks` (title,user_id,folder_id) VALUES (:title,:user_id,:folder_id);";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':title'=>$taskTitle,':user_id'=>$current_user_id,':folder_id'=>(int)$folderId]);
    return $stmt->rowCount();
}

function getTaskById(int $task_id){
    global $pdo;
    $current_user_id = getCurrentUserId();
    $sql = "select * from tasks where id = :task_id and user_id = :user_id limit 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':task_id' => $task_id, ':user_id' => (int)$current_user_id]);
    return $stmt->fetch(PDO::FETCH_OBJ);
}

function getTasks(){
    global $pdo;
    $folder = $_GET['folder_id'] ?? null;
    $current_user_id = getCurrentUserId();
    $sql = "select * from tasks where user_id = :user_id";
    $params = [':user_id' => (int)$current_user_id];
    if(isset($folder) and is_numeric($folder)){
        $sql .= " and folder_id = :folder_id";
        $params[':folder_id'] = (int)$folder;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_OBJ);
    return $records;
}
