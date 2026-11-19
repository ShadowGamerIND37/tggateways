<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';

function encryption_key(): string {
    $hex=envv('APP_ENCRYPTION_KEY','');
    if (!is_string($hex) || !preg_match('/^[a-f0-9]{64}$/i',$hex)) throw new RuntimeException('APP_ENCRYPTION_KEY must be a 64-character hex-encoded 32-byte key.');
    return hex2bin($hex);
}

function encrypt_secret(string $plaintext): string {
    $iv=random_bytes(12); $tag='';
    $cipher=openssl_encrypt($plaintext,'aes-256-gcm',encryption_key(),OPENSSL_RAW_DATA,$iv,$tag,'tg-secret-v1');
    if ($cipher===false) throw new RuntimeException('Encryption failed');
    return 'v1:'.base64_encode($iv.$tag.$cipher);
}

function decrypt_secret(string $encoded): string {
    if (!str_starts_with($encoded,'v1:')) throw new RuntimeException('Unsupported encrypted secret format');
    $raw=base64_decode(substr($encoded,3),true);
    if ($raw===false || strlen($raw)<29) throw new RuntimeException('Invalid encrypted secret');
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',encryption_key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'tg-secret-v1');
    if ($plain===false) throw new RuntimeException('Secret authentication failed');
    return $plain;
}
