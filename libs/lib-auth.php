<?php defined('BASE_PATH') OR die("Permision Denied!");

/*** Auth Functions ***/

// get login user id
function getCurrentUserId(){
    return getLoggedInUser()->id ?? 0;
}

function isLoggedIn(){
    return isset($_SESSION['login']) ? true : false ;
}

function getLoggedInUser(){
    return $_SESSION['login'] ?? null ;
}

function getUserByEmail($email){
    global $pdo;
    $sql = "SELECT *, username AS name FROM `users` WHERE email = :email";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':email' => $email]);
    $records = $stmt->fetchAll(PDO::FETCH_OBJ);
    return $records[0] ?? null;
}

function getUserByName($name){
    global $pdo;
    $sql = "SELECT *, username AS name FROM `users` WHERE username = :name LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':name' => $name]);
    $record = $stmt->fetch(PDO::FETCH_OBJ);
    return $record ?: null;
}


function logout(){
    unset($_SESSION['login']);    
}

function login($phone, $otp){
    $expectedPhone = defined('FM_TEST_LOGIN_PHONE') ? FM_TEST_LOGIN_PHONE : '';
    $expectedOtp = defined('FM_TEST_LOGIN_OTP') ? FM_TEST_LOGIN_OTP : '';
    $testUserName = defined('FM_TEST_LOGIN_USER_NAME') ? FM_TEST_LOGIN_USER_NAME : '';

    if(trim((string)$phone) !== $expectedPhone || trim((string)$otp) !== $expectedOtp){
        return false;
    }

    $user = $testUserName !== '' ? getUserByName($testUserName) : null;
    if(is_null($user)){
        $user = getUserByEmail($phone);
    }

    if(is_null($user)){
        return false;
    }

    session_regenerate_id(true);
    $user->image = fmGetUserProfileImage($user);
    $_SESSION['login'] = $user;
    return true;
}

function loginWithPassword($username, $password){
    if(!is_string($username) || !is_string($password)){
        return false;
    }

    $username = trim($username);
    if($username === '' || $password === '' || strlen($username) > 255 || strlen($password) > 4096){
        return false;
    }

    $user = getUserByName($username);
    if(!$user || !is_string($user->password ?? null) || !password_verify($password, $user->password)){
        return false;
    }

    session_regenerate_id(true);
    $user->image = fmGetUserProfileImage($user);
    $_SESSION['login'] = $user;
    return true;
}

function register($userData){
    global $pdo;
    # validation of $userData here (isValidEmail,isValidUserName,isValidPassowrd)
    $passHash = password_hash($userData['password'],PASSWORD_BCRYPT);
    $sql = "INSERT INTO `users` (name,email,password) VALUES (:name,:email,:pass);";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':name'=>$userData['name'],':email'=>$userData['email'],':pass'=>$passHash]);
    return $stmt->rowCount() ? true : false;
}
