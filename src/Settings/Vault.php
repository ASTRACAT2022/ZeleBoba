<?php
declare(strict_types=1);
namespace App\Settings;
final class Vault
{
    public function __construct(private string $path) {}
    private function key(bool $create): string
    {
        if (!is_file($this->path) && !$create) throw new \RuntimeException('Encryption key missing; restore the original key');
        $old=umask(0077);
        try {
            $file=fopen($this->path,'c+b');
            if (!$file || !flock($file,LOCK_EX)) throw new \RuntimeException('Cannot lock encryption key');
            try {
                $key=stream_get_contents($file);
                if ($key==='' && $create) { $key=random_bytes(32); if (fwrite($file,$key)!==32) throw new \RuntimeException('Key write failed'); fflush($file); }
                if (strlen($key)!==32) throw new \RuntimeException('Invalid encryption key');
                chmod($this->path,0600); return $key;
            } finally { flock($file,LOCK_UN);fclose($file); }
        } finally { umask($old); }
    }
    public function seal(string $name,string $value): string
    {
        $nonce=random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return base64_encode($nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($value,$name,$nonce,$this->key(true)));
    }
    public function open(string $name,string $value): string
    {
        $blob=base64_decode($value,true);
        if ($blob===false || strlen($blob)<40) throw new \RuntimeException('Invalid encrypted setting');
        $plain=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($blob,24),$name,substr($blob,0,24),$this->key(false));
        if ($plain===false) throw new \RuntimeException('Cannot decrypt setting');
        return $plain;
    }
}
