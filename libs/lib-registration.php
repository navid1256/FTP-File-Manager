<?php

class RegistrationException extends RuntimeException {}

function fmValidateRegistration(array $input): array
{
    foreach (['username', 'email', 'password', 'password_confirmation'] as $field) {
        if (!isset($input[$field]) || !is_string($input[$field])) {
            throw new RegistrationException('همهٔ فیلدهای ثبت‌نام را کامل کنید.');
        }
    }
    $username = strtolower(trim($input['username']));
    $email = strtolower(trim($input['email']));
    if (!preg_match('/\A[a-z0-9_]{3,50}\z/D', $username)) {
        throw new RegistrationException('یوزرنیم باید ۳ تا ۵۰ حرف لاتین، عدد یا زیرخط باشد.');
    }
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RegistrationException('ایمیل معتبر وارد کنید.');
    }
    if (strlen($input['password']) < 8 || strlen($input['password']) > 72) {
        throw new RegistrationException('رمز عبور باید بین ۸ تا ۷۲ بایت باشد.');
    }
    if (!hash_equals($input['password'], $input['password_confirmation'])) {
        throw new RegistrationException('رمز عبور و تکرار آن یکسان نیستند.');
    }
    return ['username' => $username, 'email' => $email, 'password' => $input['password']];
}

function fmFindReadyStorage(PDO $db, int $userId): ?array
{
    $stmt = $db->prepare("SELECT folder_path, quota_bytes, used_bytes, reserved_bytes FROM user_storage WHERE user_id = :id AND status = 'ready'");
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

// Pending/failed accounts stay disabled even if new registration is switched off.
function fmAttachProvisionedStorage(PDO $db, object $user): bool
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $exists = $db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'user_storage'")->fetchColumn();
    } else {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :name');
        $stmt->execute([':name' => 'user_storage']);
        $exists = $stmt->fetchColumn();
    }
    if (!$exists) { return true; } // Existing local accounts before the additive migration.
    $stmt = $db->prepare('SELECT status, folder_path FROM user_storage WHERE user_id = :id');
    $stmt->execute([':id' => (int)$user->id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { return true; } // Never provision or migrate old accounts on login.
    if ($row['status'] !== 'ready') { return false; }
    $user->storage_driver = 'ftp';
    $user->storage_path = $row['folder_path'];
    return true;
}

function fmRegisterWithFtp(PDO $db, FtpHomeProvisioner $ftp, array $input): int
{
    $data = fmValidateRegistration($input);
    if ($db->inTransaction()) {
        throw new RegistrationException('ثبت‌نام در این وضعیت قابل انجام نیست.');
    }
    $path = $ftp->homePath($data['username']);
    $hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    $token = bin2hex(random_bytes(32));
    try {
        $db->beginTransaction();
        $stmt = $db->prepare('INSERT INTO users (username, email, password) VALUES (:username, :email, :password)');
        $stmt->execute([':username' => $data['username'], ':email' => $data['email'], ':password' => $hash]);
        $userId = (int)$db->lastInsertId();
        $stmt = $db->prepare("INSERT INTO user_storage (user_id, folder_path, quota_bytes, used_bytes, reserved_bytes, status, provision_token) VALUES (:id, :path, 2147483648, 0, 0, 'pending', :token)");
        $stmt->execute([':id' => $userId, ':path' => $path, ':token' => $token]);
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        error_log('registration_reservation_failed code=' . $e->getCode());
        throw new RegistrationException('ثبت‌نام انجام نشد؛ ممکن است یوزرنیم یا ایمیل قبلاً استفاده شده باشد.');
    }
    fmProvisionStoredUser($db, $ftp, $userId);
    return $userId;
}

// Retries reuse the durable ownership token, never someone else's directory.
function fmProvisionStoredUser(PDO $db, FtpHomeProvisioner $ftp, int $userId): void
{
    if ($db->inTransaction()) {
        throw new RegistrationException('آماده‌سازی پوشه در این وضعیت قابل انجام نیست.');
    }
    $stmt = $db->prepare('SELECT s.*, u.username FROM user_storage s JOIN users u ON u.id = s.user_id WHERE s.user_id = :id');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RegistrationException('حساب در انتظار آماده‌سازی پیدا نشد.');
    }
    if ($row['status'] === 'ready') { return; }
    if ($row['folder_path'] !== $ftp->homePath($row['username'])) {
        throw new RegistrationException('مسیر ذخیره‌شده با تنظیمات فعلی FTP یکسان نیست.');
    }

    $attempt = bin2hex(random_bytes(16));
    $now = gmdate('Y-m-d H:i:s');
    $expires = gmdate('Y-m-d H:i:s', time() + 300);
    $stmt = $db->prepare("UPDATE user_storage SET status = 'provisioning', attempt_token = :attempt, lease_expires_at = :expires, last_error = NULL, updated_at = CURRENT_TIMESTAMP WHERE user_id = :id AND (status IN ('pending', 'failed') OR (status = 'provisioning' AND lease_expires_at < :now))");
    $stmt->execute([':attempt' => $attempt, ':expires' => $expires, ':id' => $userId, ':now' => $now]);
    if ($stmt->rowCount() !== 1) {
        throw new RegistrationException('آماده‌سازی این حساب هنوز در حال انجام است.');
    }

    try {
        $ftp->ensureOwnedHome($row['username'], $row['provision_token']);
        $stmt = $db->prepare("UPDATE user_storage SET status = 'ready', attempt_token = NULL, lease_expires_at = NULL, last_error = NULL, updated_at = CURRENT_TIMESTAMP WHERE user_id = :id AND status = 'provisioning' AND attempt_token = :attempt");
        $stmt->execute([':id' => $userId, ':attempt' => $attempt]);
        if ($stmt->rowCount() !== 1) {
            throw new RegistrationException('نتیجهٔ آماده‌سازی نیازمند بررسی است.');
        }
    } catch (Throwable $e) {
        $code = $e instanceof FtpProvisionException ? 'ftp_provision_failed' : 'activation_failed';
        try {
            $stmt = $db->prepare("UPDATE user_storage SET status = 'failed', attempt_token = NULL, lease_expires_at = NULL, last_error = :error, updated_at = CURRENT_TIMESTAMP WHERE user_id = :id AND status = 'provisioning' AND attempt_token = :attempt");
            $stmt->execute([':error' => $code, ':id' => $userId, ':attempt' => $attempt]);
        } catch (Throwable $stateError) {
            error_log('registration_failure_state_unavailable user_id=' . $userId);
        }
        error_log('registration_provision_failed user_id=' . $userId . ' event=' . $code);
        throw new RegistrationException('ثبت‌نام کامل نشد و حساب فعال نیست؛ برای بررسی به مدیر اطلاع دهید. کد پیگیری: ' . $userId);
    }
}
