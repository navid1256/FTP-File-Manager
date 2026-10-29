<?php

class FtpProvisionException extends RuntimeException {}

interface FtpHomeProvisioner
{
    public function homePath(string $username): string;
    public function ensureOwnedHome(string $username, string $token): void;
}

final class FtpHomeClient implements FtpHomeProvisioner
{
    private array $config;

    public function __construct(array $config)
    {
        if (!extension_loaded('curl')) {
            throw new FtpProvisionException('افزونهٔ cURL در PHP فعال نیست.');
        }
        if (!in_array($config['protocol'] ?? '', ['ftp', 'ftps'], true)) {
            throw new FtpProvisionException('نوع اتصال FTP معتبر نیست.');
        }
        if (!is_string($config['host'] ?? null)
            || !preg_match('/\A[a-zA-Z0-9.-]+\z/D', $config['host'])
            || !is_int($config['port'] ?? null) || $config['port'] < 1 || $config['port'] > 65535
            || !is_string($config['username'] ?? null) || $config['username'] === ''
            || !is_string($config['password'] ?? null) || $config['password'] === '') {
            throw new FtpProvisionException('تنظیمات اتصال FTP کامل نیست.');
        }
        if (!is_string($config['base_path'] ?? null)
            || !preg_match('/\A[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)*\z/D', $config['base_path'])) {
            throw new FtpProvisionException('مسیر پایهٔ FTP معتبر نیست.');
        }
        if (!is_int($config['timeout'] ?? null) || $config['timeout'] < 1 || $config['timeout'] > 60) {
            throw new FtpProvisionException('مهلت اتصال FTP معتبر نیست.');
        }
        $this->config = $config;
    }

    public function homePath(string $username): string
    {
        if (!preg_match('/\A[a-z0-9_]{3,50}\z/D', $username)) {
            throw new FtpProvisionException('نام پوشهٔ کاربر معتبر نیست.');
        }
        return $this->config['base_path'] . '/' . $username;
    }

    // Read-only: this proves login and directory listing, not write permission.
    public function inspect(): array
    {
        $result = $this->request($this->config['base_path'] . '/', [CURLOPT_DIRLISTONLY => true]);
        return ['entry_path' => $result['entry_path'], 'base_path' => $this->config['base_path']];
    }

    public function ensureOwnedHome(string $username, string $token): void
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
            throw new FtpProvisionException('شناسهٔ ساخت پوشه معتبر نیست.');
        }
        $path = $this->homePath($username);
        $listing = $this->request($this->config['base_path'] . '/', [CURLOPT_DIRLISTONLY => true]);
        $names = array_map(static fn($name) => basename(str_replace('\\', '/', trim($name))),
            preg_split('/\r?\n/', trim($listing['body'])) ?: []);
        if (in_array($username, $names, true)) {
            // A retry may only reuse a directory carrying this operation's token.
            $existing = $this->request($path . '/.filemanager-owner', [], 128);
            if (!hash_equals($token, $existing['body'])) {
                throw new FtpProvisionException('پوشهٔ هم‌نام از قبل وجود دارد و قابل واگذاری نیست.');
            }
            return;
        }

        // MKD must succeed; never continue after an existing-directory error.
        $this->request('', [CURLOPT_NOBODY => true, CURLOPT_QUOTE => ['MKD ' . $path]]);
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, $token);
            rewind($stream);
            $this->request($path . '/.filemanager-owner', [
                CURLOPT_UPLOAD => true, CURLOPT_INFILE => $stream, CURLOPT_INFILESIZE => strlen($token),
            ]);
            $written = $this->request($path . '/.filemanager-owner', [], 128);
            if (!hash_equals($token, $written['body'])) {
                throw new FtpProvisionException('تأیید مالکیت پوشهٔ جدید انجام نشد.');
            }
        } finally {
            fclose($stream);
        }
        // No recursive delete or overwrite: failed provisioning is retained for review.
    }

    private function request(string $path, array $options = [], int $maxBytes = 1048576): array
    {
        $url = 'ftp://' . $this->config['host'] . ':' . $this->config['port'] . '/'
            . implode('/', array_map('rawurlencode', explode('/', $path)));
        $handle = curl_init($url);
        $body = '';
        try {
            $defaults = [
                CURLOPT_USERNAME => $this->config['username'],
                CURLOPT_PASSWORD => $this->config['password'],
                CURLOPT_PROXY => '',
                CURLOPT_CONNECTTIMEOUT => min(5, $this->config['timeout']),
                CURLOPT_TIMEOUT => $this->config['timeout'],
                CURLOPT_PROTOCOLS => CURLPROTO_FTP | CURLPROTO_FTPS,
                CURLOPT_USE_SSL => $this->config['protocol'] === 'ftps' ? CURLUSESSL_ALL : CURLUSESSL_NONE,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FTP_SKIP_PASV_IP => true,
                CURLOPT_FTP_FILEMETHOD => CURLFTPMETHOD_SINGLECWD,
                CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$body, $maxBytes) {
                    if (strlen($body) + strlen($chunk) > $maxBytes) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ];
            if (($this->config['ca_file'] ?? '') !== '') {
                $defaults[CURLOPT_CAINFO] = $this->config['ca_file'];
            }
            curl_setopt_array($handle, array_replace($defaults, $options));
            if (curl_exec($handle) === false) {
                // Do not expose libcurl diagnostics: they may contain infrastructure details.
                error_log('ftp_request_failed code=' . curl_errno($handle)
                    . ' response=' . curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
                throw new FtpProvisionException('اتصال یا عملیات FTP انجام نشد؛ تنظیمات و مجوز مسیر را بررسی کنید.');
            }
            return ['body' => $body, 'entry_path' => curl_getinfo($handle, CURLINFO_FTP_ENTRY_PATH)];
        } finally {
            curl_close($handle);
        }
    }
}
