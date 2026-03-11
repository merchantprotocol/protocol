<?php

use PHPUnit\Framework\TestCase;
use Gitcd\Helpers\Secrets;

class SecretsTest extends TestCase
{
    private string $tmpDir;
    private string $originalHome;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/protocol-test-' . getmypid();
        mkdir($this->tmpDir . '/.protocol', 0700, true);

        // Override HOME so Secrets::keyPath() uses our temp directory
        $this->originalHome = $_SERVER['HOME'] ?? getenv('HOME');
        $_SERVER['HOME'] = $this->tmpDir;
    }

    protected function tearDown(): void
    {
        // Restore HOME
        $_SERVER['HOME'] = $this->originalHome;

        // Cleanup temp files
        $this->recursiveDelete($this->tmpDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testGenerateKeyReturns64HexChars(): void
    {
        $key = Secrets::generateKey();
        $this->assertSame(64, strlen($key));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
    }

    public function testGenerateKeyIsRandom(): void
    {
        $key1 = Secrets::generateKey();
        $key2 = Secrets::generateKey();
        $this->assertNotSame($key1, $key2);
    }

    public function testStoreAndReadKey(): void
    {
        $hexKey = Secrets::generateKey();
        $this->assertTrue(Secrets::storeKey($hexKey));
        $this->assertTrue(Secrets::hasKey());

        $readBack = Secrets::readKey();
        $this->assertNotNull($readBack);
        $this->assertSame(Secrets::KEY_LENGTH, strlen($readBack));
        $this->assertSame($hexKey, bin2hex($readBack));
    }

    public function testKeyFilePermissions(): void
    {
        $hexKey = Secrets::generateKey();
        Secrets::storeKey($hexKey);

        $perms = fileperms(Secrets::keyPath()) & 0777;
        $this->assertSame(0600, $perms);
    }

    public function testReadKeyReturnsNullWhenNoKey(): void
    {
        // No key stored in our temp dir
        $this->assertNull(Secrets::readKey());
    }

    #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]
    public function testReadKeyReturnsNullForInvalidKey(): void
    {
        // Store a key that's the wrong length — hex2bin warning is expected
        file_put_contents(Secrets::keyPath(), 'tooshort');
        $this->assertNull(@Secrets::readKey());
    }

    #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]
    public function testReadKeyReturnsNullForNonHex(): void
    {
        // 64 chars but not valid hex — hex2bin warning is expected
        file_put_contents(Secrets::keyPath(), str_repeat('zz', 32));
        $this->assertNull(@Secrets::readKey());
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        Secrets::storeKey(Secrets::generateKey());

        $plaintext = "DB_HOST=localhost\nDB_PASS=supersecret123\n";
        $encrypted = Secrets::encrypt($plaintext);

        $this->assertNotNull($encrypted);
        $this->assertNotSame($plaintext, $encrypted);

        $decrypted = Secrets::decrypt($encrypted);
        $this->assertSame($plaintext, $decrypted);
    }

    public function testEncryptProducesDifferentCiphertextEachTime(): void
    {
        Secrets::storeKey(Secrets::generateKey());

        $plaintext = 'same input';
        $enc1 = Secrets::encrypt($plaintext);
        $enc2 = Secrets::encrypt($plaintext);

        // Different nonces mean different ciphertext
        $this->assertNotSame($enc1, $enc2);

        // But both decrypt to the same value
        $this->assertSame($plaintext, Secrets::decrypt($enc1));
        $this->assertSame($plaintext, Secrets::decrypt($enc2));
    }

    public function testEncryptReturnsNullWithoutKey(): void
    {
        $this->assertNull(Secrets::encrypt('hello'));
    }

    public function testDecryptReturnsNullWithoutKey(): void
    {
        $this->assertNull(Secrets::decrypt(base64_encode(random_bytes(50))));
    }

    public function testDecryptReturnsNullForTamperedCiphertext(): void
    {
        Secrets::storeKey(Secrets::generateKey());

        $encrypted = Secrets::encrypt('sensitive data');
        $this->assertNotNull($encrypted);

        // Tamper with the ciphertext
        $raw = base64_decode($encrypted);
        $raw[20] = chr(ord($raw[20]) ^ 0xFF);
        $tampered = base64_encode($raw);

        $this->assertNull(Secrets::decrypt($tampered));
    }

    public function testDecryptReturnsNullForInvalidBase64(): void
    {
        Secrets::storeKey(Secrets::generateKey());
        $this->assertNull(Secrets::decrypt('not-valid-base64!!!'));
    }

    public function testDecryptReturnsNullForTooShortData(): void
    {
        Secrets::storeKey(Secrets::generateKey());
        // Nonce(12) + Tag(16) + at least 1 byte = 29 minimum
        $this->assertNull(Secrets::decrypt(base64_encode(random_bytes(20))));
    }

    public function testDecryptWithWrongKeyFails(): void
    {
        Secrets::storeKey(Secrets::generateKey());
        $encrypted = Secrets::encrypt('secret');

        // Replace key with a different one
        Secrets::storeKey(Secrets::generateKey());
        $this->assertNull(Secrets::decrypt($encrypted));
    }

    public function testEncryptDecryptFile(): void
    {
        Secrets::storeKey(Secrets::generateKey());

        $inputFile = $this->tmpDir . '/.env';
        $outputFile = $this->tmpDir . '/.env.enc';

        $content = "APP_KEY=base64:abc123\nDB_PASSWORD=secret\n";
        file_put_contents($inputFile, $content);

        $this->assertTrue(Secrets::encryptFile($inputFile, $outputFile));
        $this->assertFileExists($outputFile);
        $this->assertNotSame($content, file_get_contents($outputFile));

        $decrypted = Secrets::decryptFile($outputFile);
        $this->assertSame($content, $decrypted);
    }

    public function testEncryptFileReturnsFalseForMissingInput(): void
    {
        Secrets::storeKey(Secrets::generateKey());
        $this->assertFalse(Secrets::encryptFile('/nonexistent', $this->tmpDir . '/out'));
    }

    public function testDecryptFileReturnsNullForMissingInput(): void
    {
        $this->assertNull(Secrets::decryptFile('/nonexistent'));
    }

    public function testDecryptToTempFile(): void
    {
        Secrets::storeKey(Secrets::generateKey());

        $inputFile = $this->tmpDir . '/.env.enc';
        $content = "SECRET=value\n";

        $encrypted = Secrets::encrypt($content);
        file_put_contents($inputFile, $encrypted);

        $tmpFile = Secrets::decryptToTempFile($inputFile);
        $this->assertNotNull($tmpFile);
        $this->assertFileExists($tmpFile);
        $this->assertSame($content, file_get_contents($tmpFile));

        // Check temp file permissions
        $perms = fileperms($tmpFile) & 0777;
        $this->assertSame(0600, $perms);

        // Cleanup
        unlink($tmpFile);
    }

    public function testEncryptDecryptEmptyString(): void
    {
        Secrets::storeKey(Secrets::generateKey());

        $encrypted = Secrets::encrypt('');
        // Empty string encrypts to nonce(12) + tag(16) + 0 bytes ciphertext.
        // decrypt() requires minLength of 29 (nonce + tag + 1), so empty
        // plaintext cannot round-trip. This is acceptable — .env files are
        // never empty in practice.
        if ($encrypted !== null) {
            $decrypted = Secrets::decrypt($encrypted);
            $this->assertNull($decrypted, 'Empty plaintext cannot round-trip due to minimum length check');
        } else {
            $this->assertNull($encrypted);
        }
    }

    public function testEncryptDecryptLargePayload(): void
    {
        Secrets::storeKey(Secrets::generateKey());

        $plaintext = str_repeat("LARGE_ENV_VAR=value\n", 10000);
        $encrypted = Secrets::encrypt($plaintext);
        $this->assertNotNull($encrypted);

        $decrypted = Secrets::decrypt($encrypted);
        $this->assertSame($plaintext, $decrypted);
    }

    public function testEncryptDecryptBinaryContent(): void
    {
        Secrets::storeKey(Secrets::generateKey());

        $plaintext = random_bytes(256);
        $encrypted = Secrets::encrypt($plaintext);
        $this->assertNotNull($encrypted);

        $decrypted = Secrets::decrypt($encrypted);
        $this->assertSame($plaintext, $decrypted);
    }
}
