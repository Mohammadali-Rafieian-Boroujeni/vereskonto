<?php
/**
 * VAPID keys and request signing for Web Push.
 *
 * Deliberately sends push messages WITHOUT a payload. Encrypting a payload
 * needs ECDH plus AES-GCM, which is a lot of delicate crypto to carry in a
 * plugin; a payloadless push is a plain signed POST, and the service worker
 * then fetches the actual text from our REST route. Same result for the
 * customer, far less that can silently go wrong.
 *
 * @package Vereskonto\Modules\Notify
 * @since   1.99.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Notify_Vapid
 */
final class VKL_Notify_Vapid
{
    private const OPT_PUBLIC  = 'vkl_vapid_public';
    private const OPT_PRIVATE = 'vkl_vapid_private';

    /**
     * Base64 in the URL-safe form the Push standard uses.
     */
    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function unb64(string $value): string
    {
        $pad = strlen($value) % 4;

        if ($pad > 0) {
            $value .= str_repeat('=', 4 - $pad);
        }

        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    /**
     * The public key browsers need, generating the pair on first use.
     */
    public static function public_key(): string
    {
        $public = (string) get_option(self::OPT_PUBLIC, '');

        if ('' !== $public) {
            return $public;
        }

        return self::generate()['public'];
    }

    public static function has_keys(): bool
    {
        return '' !== (string) get_option(self::OPT_PUBLIC, '')
            && '' !== (string) get_option(self::OPT_PRIVATE, '');
    }

    /**
     * Create and store a keypair.
     *
     * @return array{public: string, private: string}
     */
    public static function generate(): array
    {
        $res = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if (false === $res) {
            return ['public' => '', 'private' => ''];
        }

        $details = openssl_pkey_get_details($res);

        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['d'])) {
            return ['public' => '', 'private' => ''];
        }

        // Uncompressed point: 0x04 || X || Y, each padded to 32 bytes.
        $public = self::b64(
            "\x04"
            . str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT)
            . str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT)
        );

        $private = self::b64(str_pad((string) $details['ec']['d'], 32, "\0", STR_PAD_LEFT));

        update_option(self::OPT_PUBLIC, $public, false);
        update_option(self::OPT_PRIVATE, $private, false);

        return ['public' => $public, 'private' => $private];
    }

    public static function forget(): void
    {
        delete_option(self::OPT_PUBLIC);
        delete_option(self::OPT_PRIVATE);
    }

    /**
     * Authorization header value for one push endpoint.
     *
     * The audience is the endpoint's origin, so a token signed for one push
     * service is not valid at another.
     */
    public static function auth_header(string $endpoint): string
    {
        $private = (string) get_option(self::OPT_PRIVATE, '');
        $public  = (string) get_option(self::OPT_PUBLIC, '');

        if ('' === $private || '' === $public) {
            return '';
        }

        $parts = wp_parse_url($endpoint);

        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $audience = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];

        $header = self::b64((string) wp_json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64((string) wp_json_encode([
            'aud' => $audience,
            'exp' => time() + 12 * HOUR_IN_SECONDS,
            'sub' => 'mailto:' . get_option('admin_email', 'admin@example.com'),
        ]));

        $signature = self::sign($header . '.' . $claims, $private);

        if ('' === $signature) {
            return '';
        }

        return 'vapid t=' . $header . '.' . $claims . '.' . $signature . ', k=' . $public;
    }

    /**
     * ES256 signature in the raw R||S form JWT expects.
     *
     * OpenSSL hands back DER, so the two integers are unwrapped and padded
     * to 32 bytes each — a JWT verifier rejects the DER form outright.
     */
    private static function sign(string $input, string $private_b64): string
    {
        $key = self::private_key_pem(self::unb64($private_b64));

        if ('' === $key) {
            return '';
        }

        $der = '';

        if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            return '';
        }

        $raw = self::der_to_raw($der);

        return '' !== $raw ? self::b64($raw) : '';
    }

    /**
     * Wrap a 32-byte private scalar in a PEM key OpenSSL will load.
     */
    private static function private_key_pem(string $d): string
    {
        if (32 !== strlen($d)) {
            return '';
        }

        // SEC1 EC private key for prime256v1, with the curve OID inline.
        $der = "\x30\x77\x02\x01\x01\x04\x20" . $d
            . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            . "\xa1\x44\x03\x42\x00" . self::public_point($d);

        $pem = "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END EC PRIVATE KEY-----\n";

        $key = openssl_pkey_get_private($pem);

        return false !== $key ? $pem : '';
    }

    /**
     * The stored public point, which must match the private scalar.
     */
    private static function public_point(string $d): string
    {
        $point = self::unb64((string) get_option(self::OPT_PUBLIC, ''));

        return 65 === strlen($point) ? $point : str_repeat("\0", 65);
    }

    /**
     * DER SEQUENCE(INTEGER r, INTEGER s) -> r||s, 32 bytes each.
     */
    private static function der_to_raw(string $der): string
    {
        $offset = 0;

        if (!isset($der[$offset]) || "\x30" !== $der[$offset]) {
            return '';
        }

        ++$offset;
        $len = ord($der[$offset]);
        ++$offset;

        if ($len > 0x80) {
            $offset += $len - 0x80;
        }

        $read = static function (string $der, int &$offset): string {
            if (!isset($der[$offset]) || "\x02" !== $der[$offset]) {
                return '';
            }

            ++$offset;
            $length = ord($der[$offset]);
            ++$offset;

            $value = substr($der, $offset, $length);
            $offset += $length;

            // Drop the sign byte DER adds, then pad back out to 32.
            $value = ltrim($value, "\0");

            return str_pad($value, 32, "\0", STR_PAD_LEFT);
        };

        $r = $read($der, $offset);
        $s = $read($der, $offset);

        return ('' !== $r && '' !== $s) ? $r . $s : '';
    }
}
