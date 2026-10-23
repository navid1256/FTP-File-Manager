<?php
include "bootstrap/init.php";

if(isLoggedIn()){
    redirect(site_url());
}

$authError = '';
$phoneValue = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = $_GET['action'] ?? 'login';
    if($action === 'login'){
        $phoneValue = trim((string)($_POST['phone'] ?? ''));
        $otpValue = trim((string)($_POST['otp'] ?? ''));

        if(login($phoneValue, $otpValue)){
            redirect(site_url());
        }

        $authError = 'شماره موبایل یا کد یک‌بار مصرف اشتباه است.';
    }
}

include "tpl/tpl-auth.php";
