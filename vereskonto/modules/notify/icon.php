<?php
/**
 * Build an app icon that survives whatever shape the launcher imposes.
 *
 * Browsers and Android launchers draw the home-screen icon inside a circle
 * or squircle of their own choosing — no manifest setting turns that off.
 * The only reliable answer is to hand them artwork that already sits in
 * the middle of a larger square, so the frame lands on empty space and the
 * logo is never clipped.
 *
 * @package Vereskonto\Modules\Notify
 * @since   2.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Notify_Icon
 */
final class VKL_Notify_Icon
{
    /** Final canvas edge, the size browsers ask for. */
    private const CANVAS = 512;

    /**
     * Share of the canvas the artwork may occupy.
     *
     * Geometry, not taste: a circle drawn inside the square has radius
     * C/2, and the corner of a centred square of side s sits at
     * s·√2/2 from the middle. Staying inside the circle therefore needs
     * s ≤ C/√2, about 70.7%. 66% leaves a margin on top of that, so the
     * artwork survives the slightly tighter squircle some launchers use.
     */
    private const SCALE = 0.66;

    public static function can_build(): bool
    {
        return function_exists('imagecreatetruecolor')
            && function_exists('imagecopyresampled')
            && function_exists('imagepng');
    }

    /**
     * Centre the source image on a transparent 512×512 canvas.
     *
     * @return string URL of the generated file, or '' on failure.
     */
    public static function build(string $source_url): string
    {
        if (!self::can_build() || '' === $source_url) {
            return '';
        }

        $path = self::local_path($source_url);

        if ('' === $path || !is_readable($path)) {
            return '';
        }

        $src = self::load($path);

        if (null === $src) {
            return '';
        }

        $sw = imagesx($src);
        $sh = imagesy($src);

        if ($sw < 1 || $sh < 1) {
            imagedestroy($src);

            return '';
        }

        $canvas = imagecreatetruecolor(self::CANVAS, self::CANVAS);

        // Transparent background, so the launcher's own plate shows through
        // instead of a white square floating on the wallpaper.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        // Fit the artwork inside the safe box without distorting it.
        $box   = (int) round(self::CANVAS * self::SCALE);
        $ratio = min($box / $sw, $box / $sh);
        $dw    = max(1, (int) round($sw * $ratio));
        $dh    = max(1, (int) round($sh * $ratio));
        $dx    = (int) round((self::CANVAS - $dw) / 2);
        $dy    = (int) round((self::CANVAS - $dh) / 2);

        imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
        imagedestroy($src);

        $uploads = wp_upload_dir();

        if (!empty($uploads['error'])) {
            imagedestroy($canvas);

            return '';
        }

        // A fresh name each time, so a browser holding the old icon in
        // cache does not keep showing the clipped version.
        $name = 'vkl-app-icon-' . substr(md5($source_url . (string) time()), 0, 8) . '.png';
        $file = trailingslashit($uploads['path']) . $name;

        $saved = imagepng($canvas, $file, 6);
        imagedestroy($canvas);

        if (!$saved) {
            return '';
        }

        // Register it so the size check can read real dimensions later.
        $attachment = wp_insert_attachment(
            [
                'post_mime_type' => 'image/png',
                'post_title'     => 'Vereskala app icon',
                'post_status'    => 'inherit',
            ],
            $file
        );

        if (!is_wp_error($attachment) && $attachment > 0) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            wp_update_attachment_metadata($attachment, wp_generate_attachment_metadata($attachment, $file));
        }

        return trailingslashit($uploads['url']) . $name;
    }

    /**
     * Turn an uploads URL into a path on disk.
     */
    private static function local_path(string $url): string
    {
        $id = attachment_url_to_postid($url);

        if ($id > 0) {
            $path = get_attached_file($id);

            if (is_string($path) && '' !== $path) {
                return $path;
            }
        }

        $uploads = wp_upload_dir();

        if (!empty($uploads['baseurl']) && 0 === strpos($url, (string) $uploads['baseurl'])) {
            return (string) $uploads['basedir'] . substr($url, strlen((string) $uploads['baseurl']));
        }

        return '';
    }

    /**
     * Load PNG, JPEG or WebP into a GD resource.
     *
     * @return resource|GdImage|null
     */
    private static function load(string $path)
    {
        $info = @getimagesize($path);

        if (!is_array($info)) {
            return null;
        }

        $image = null;

        switch ($info[2]) {
            case IMAGETYPE_PNG:
                $image = @imagecreatefrompng($path);
                break;

            case IMAGETYPE_JPEG:
                $image = @imagecreatefromjpeg($path);
                break;

            case IMAGETYPE_WEBP:
                $image = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null;
                break;
        }

        return $image ?: null;
    }
}
