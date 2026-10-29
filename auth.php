<?php
include "bootstrap/init.php";

if(isLoggedIn()){
    redirect(site_url());
}

$authError = '';
$phoneValue = '';
$authErrors = [];
$authSuccess = is_string($_SESSION['auth_success'] ?? null) ? $_SESSION['auth_success'] : null;
unset($_SESSION['auth_success']);
$activeAuthForm = ($_GET['action'] ?? '') === 'register' ? 'register' : 'login';
$oldInput = ['username' => '', 'email' => ''];
$csrfToken = getCsrfToken();
$isOtpLogin = ($_GET['mode'] ?? '') === 'otp';
if($isOtpLogin && fmFtpRegistrationEnabled()){
    $isOtpLogin = false;
    $authErrors[] = 'ورود پیامکی در این مرحله فعال نیست.';
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = $_GET['action'] ?? 'login';
    $oldInput['username'] = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $oldInput['email'] = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
    $postedToken = $_POST['csrf_token'] ?? '';

    if(!is_string($postedToken) || !verifyCsrfToken($postedToken)){
        $authErrors[] = 'نشست فرم معتبر نیست؛ دوباره تلاش کنید.';
    } elseif($action === 'login' && !$isOtpLogin){
        try {
            if(loginWithPassword($_POST['username'] ?? '', $_POST['password'] ?? '')){
                redirect(site_url());
            }
            $authErrors[] = 'نام کاربری یا رمز عبور اشتباه است.';
        } catch(PDOException $e) {
            error_log('Password login database failure: ' . $e->getCode());
            $authErrors[] = 'در حال حاضر امکان ورود وجود ندارد؛ دوباره تلاش کنید.';
        }
    } elseif($action === 'otp-login' && $isOtpLogin){
        $phoneValue = is_string($_POST['phone'] ?? null) ? trim($_POST['phone']) : '';
        $otpValue = is_string($_POST['otp'] ?? null) ? trim($_POST['otp']) : '';
        if(login($phoneValue, $otpValue)){
            redirect(site_url());
        }

        $authErrors[] = 'شماره موبایل یا کد یک‌بار مصرف اشتباه است.';
    } elseif($action === 'register'){
        try {
            register($_POST);
            $_SESSION['auth_success'] = 'حساب و پوشهٔ اختصاصی شما ساخته شد؛ با یوزرنیم و رمز عبور وارد شوید.';
            redirect(site_url('auth.php'));
        } catch(RegistrationException | FtpProvisionException $e) {
            $authErrors[] = $e->getMessage();
        } catch(Throwable $e) {
            error_log('registration_failed code=' . $e->getCode());
            $authErrors[] = 'ثبت‌نام انجام نشد؛ تنظیمات سامانه نیاز به بررسی دارد.';
        }
    } else {
        $authErrors[] = 'درخواست ورود نامعتبر است.';
    }
}

$authError = implode(' ', $authErrors);
include $isOtpLogin ? "tpl/tpl-otp.php" : "tpl/tpl-auth.php";
