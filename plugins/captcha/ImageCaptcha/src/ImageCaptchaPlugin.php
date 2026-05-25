<?php

namespace Plugins\Captcha\ImageCaptcha\src;

use App\Plugins\Contracts\CaptchaInterface;
use App\Plugins\Core\AbstractPlugin;
use Illuminate\Support\Str;

class ImageCaptchaPlugin extends AbstractPlugin implements CaptchaInterface
{
    private const NAME = 'image_captcha';

    public function getName(): string { return self::NAME; }
    public function getTitle(): string { return '图形验证码'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getType(): string { return 'captcha'; }
    public function getDescription(): string { return '使用 PHP GD 生成登录和注册图形验证码'; }

    public function generate(): array
    {
        $key = Str::random(40);
        $code = $this->randomCode();
        $this->store($key, $code);

        return [
            'key' => $key,
            'image_url' => route('captcha.image', ['key' => $key]),
        ];
    }

    public function generateForKey(string $key): string
    {
        $code = $this->randomCode();
        $this->store($key, $code);

        return $this->png($code);
    }

    public function verify(string $code, string $key): bool
    {
        $sessionKey = $this->sessionKey($key);
        $payload = session()->pull($sessionKey);
        if (!is_array($payload) || ($payload['expires_at'] ?? 0) < time()) {
            return false;
        }

        return hash_equals((string) $payload['hash'], hash('sha256', strtoupper(trim($code))));
    }

    private function store(string $key, string $code): void
    {
        session()->put($this->sessionKey($key), [
            'hash' => hash('sha256', $code),
            'expires_at' => time() + $this->ttl(),
        ]);
    }

    private function randomCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < $this->length(); $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    private function png(string $code): string
    {
        $image = imagecreatetruecolor(140, 44);
        $background = imagecolorallocate($image, 248, 250, 252);
        $text = imagecolorallocate($image, 24, 24, 27);
        $line = imagecolorallocate($image, 16, 185, 129);
        imagefill($image, 0, 0, $background);

        for ($i = 0; $i < 5; $i++) {
            imageline($image, random_int(0, 140), random_int(0, 44), random_int(0, 140), random_int(0, 44), $line);
        }

        imagestring($image, 5, 20, 14, $code, $text);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function sessionKey(string $key): string
    {
        return 'captcha.image.' . $key;
    }

    private function length(): int
    {
        return max(4, min(8, (int) ($this->config('length', 5) ?: 5)));
    }

    private function ttl(): int
    {
        return max(60, min(600, (int) ($this->config('ttl', 300) ?: 300)));
    }
}
