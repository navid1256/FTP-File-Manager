<?php

function fmFtpConfig()
{
    $config = require __DIR__ . '/ftp.example.php';
    $local = __DIR__ . '/ftp.local.php';
    if (is_file($local)) {
        $overrides = require $local;
        if (!is_array($overrides)) {
            throw new RuntimeException('Invalid local FTP configuration.');
        }
        $config = array_replace($config, $overrides);
    }
    return $config;
}

function fmFtpRegistrationEnabled()
{
    return fmFtpConfig()['registration_enabled'] === true;
}
