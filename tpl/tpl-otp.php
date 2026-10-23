<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= esc(SITE_TITLE) ?> Authentication</title>
  <link rel="stylesheet" href="<?= site_url('assets/css/auth.css?v=auth-3') ?>">
</head>
<body>
  <div class="wrapper">
    <img class="logo" src="<?= esc(site_url('assets/img/logo%201024.png')) ?>" alt="7File Manager">
    <h2>ورود</h2>

    <?php if(!empty($authError)): ?>
      <div class="auth-message error"><?= esc($authError) ?></div>
    <?php endif; ?>

    <form method="post" id="loginForm" action="<?= esc(site_url('auth.php?mode=otp&action=otp-login')) ?>">
      <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
      <div class="input-box">
        <input
          type="tel"
          name="phone"
          id="phoneInput"
          placeholder="شماره موبایل"
          inputmode="numeric"
          autocomplete="tel"
          maxlength="11"
          value="<?= esc($phoneValue ?? '') ?>"
          required
        />
      </div>

      <div class="input-box otp-box">
        <input
          type="text"
          name="otp"
          id="otpInput"
          pattern="^[0-9]{6}$"
          placeholder="کد یک‌بار مصرف"
          oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6)"
          required
        />
        <button type="button" class="btn-inside" id="codeBtn">دریافت کد</button>
      </div>

      <button type="submit" name="verify" class="btn">ورود</button>
    </form>
    <p style="text-align: center;"><a href="<?= esc(site_url('auth.php')) ?>">ورود با نام کاربری و رمز عبور</a></p>
  </div>

  <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
  <script>
    $(function(){
      var TEST_PHONE = <?= json_encode(FM_TEST_LOGIN_PHONE) ?>;
      var TEST_OTP = <?= json_encode(FM_TEST_LOGIN_OTP) ?>;
      var $phone = $('#phoneInput');
      var $otp = $('#otpInput');
      var $form = $('#loginForm');
      var $message = $('<div class="auth-message info" style="display:none;"></div>');

      $form.prepend($message);

      $('#codeBtn').on('click', function(){
        if($.trim($phone.val()) !== TEST_PHONE){
          $message
            .removeClass('info success')
            .addClass('error')
            .text('شماره موبایل تستی صحیح نیست.')
            .show();
          return;
        }

        $otp.val(TEST_OTP).trigger('focus').trigger('select');
        $message
          .removeClass('error')
          .addClass('success')
          .text('کد یک‌بار مصرف آزمایشی آماده شد.')
          .show();
      });
    });
  </script>
</body>
</html>
