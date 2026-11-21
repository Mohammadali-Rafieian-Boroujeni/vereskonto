<?php
/**
 * Small, dependency-free charts for the CRM dashboard and reports:
 * horizontal bars (HTML) and a single-series line (inline SVG).
 *
 * Design rules (single series, so one hue, no legend box): thin marks
 * with rounded data-ends, recessive grid, a hover tooltip on every mark
 * (native <title>, plus a visible value), selective labels, and a table
 * view of the same numbers for screen readers and exact reading.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.19.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Charts
 */
final class VKL_Crm_Charts
{
    /**
     * Horizontal bars, longest = full width.
     *
     * @param array<int, array{label: string, value: float, display: string, swatch?: string, url?: string}> $rows
     */
    public static function bars(array $rows, string $title, string $empty = ''): void
    {
        $max = 0.0;

        foreach ($rows as $r) {
            $max = max($max, (float) $r['value']);
        }

        if ([] === $rows || $max <= 0) {
            echo '<p class="vkl-crm-empty">' . esc_html('' !== $empty ? $empty : __('No data for this period yet.', 'vereskala-login')) . '</p>';

            return;
        }

        echo '<ul class="vkl-crm-bars" role="list" aria-label="' . esc_attr($title) . '">';

        foreach ($rows as $r) {
            $pct   = max(0.5, round(100 * (float) $r['value'] / $max, 2));
            $label = esc_html($r['label']);

            if (!empty($r['url'])) {
                $label = '<a href="' . esc_url($r['url']) . '">' . $label . '</a>';
            }

            printf(
                '<li title="%1$s"><span class="vkl-crm-bar-label">%2$s%3$s</span><span class="vkl-crm-bar-track"><span class="vkl-crm-bar-fill" style="inline-size:%4$s%%"></span></span><span class="vkl-crm-bar-value">%5$s</span></li>',
                esc_attr($r['label'] . ': ' . $r['display']),
                !empty($r['swatch']) ? '<span class="vkl-crm-swatch" style="background:' . esc_attr((string) $r['swatch']) . '" aria-hidden="true"></span>' : '',
                $label, // escaped above
                esc_attr((string) $pct),
                esc_html($r['display'])
            );
        }

        echo '</ul>';
    }

    /**
     * One series over time. Points: label (x), value, display (tooltip).
     * Time runs left to right in every language, as on a calendar.
     *
     * @param array<int, array{label: string, value: float, display: string}> $points
     */
    public static function line(array $points, string $title, callable $axis_format): void
    {
        $n = count($points);

        if ($n < 2) {
            echo '<p class="vkl-crm-empty">' . esc_html__('No data for this period yet.', 'vereskala-login') . '</p>';

            return;
        }

        $w = 640;
        $h = 200;
        $pad = ['l' => 56, 'r' => 12, 't' => 12, 'b' => 28];
        $max = 0.0;

        foreach ($points as $p) {
            $max = max($max, (float) $p['value']);
        }

        $max   = $max > 0 ? self::nice($max) : 1.0;
        $plotw = $w - $pad['l'] - $pad['r'];
        $ploth = $h - $pad['t'] - $pad['b'];
        $x     = static fn(int $i): float => $pad['l'] + ($n > 1 ? $i * $plotw / ($n - 1) : 0);
        $y     = static fn(float $v): float => $pad['t'] + $ploth - ($v / $max) * $ploth;

        $path = '';
        $area = '';

        foreach ($points as $i => $p) {
            $cmd   = 0 === $i ? 'M' : 'L';
            $path .= sprintf('%s%.1f %.1f ', $cmd, $x($i), $y((float) $p['value']));
        }

        $area = $path . sprintf('L%.1f %.1f L%.1f %.1f Z', $x($n - 1), $pad['t'] + $ploth, $x(0), $pad['t'] + $ploth);
        $last = $points[$n - 1];
        $step = max(1, (int) ceil($n / 6));
        ?>
        <figure class="vkl-crm-line">
            <svg viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $h; ?>" role="img" aria-label="<?php echo esc_attr($title); ?>" preserveAspectRatio="xMidYMid meet" dir="ltr">
                <?php for ($g = 0; $g <= 3; $g++) :
                    $gv = $max * $g / 3;
                    $gy = $y($gv);
                    ?>
                    <line class="grid" x1="<?php echo esc_attr((string) $pad['l']); ?>" x2="<?php echo esc_attr((string) ($w - $pad['r'])); ?>" y1="<?php echo esc_attr(sprintf('%.1f', $gy)); ?>" y2="<?php echo esc_attr(sprintf('%.1f', $gy)); ?>"/>
                    <text class="axis" x="<?php echo esc_attr((string) ($pad['l'] - 6)); ?>" y="<?php echo esc_attr(sprintf('%.1f', $gy + 4)); ?>" text-anchor="end"><?php echo esc_html((string) $axis_format($gv)); ?></text>
                <?php endfor; ?>
                <path class="area" d="<?php echo esc_attr($area); ?>"/>
                <path class="stroke" d="<?php echo esc_attr(trim($path)); ?>"/>
                <?php foreach ($points as $i => $p) : ?>
                    <?php // Every $step-th label, plus the last one — but never a regular label so close to the last that they overlap. ?>
                    <?php if ($i === $n - 1 || (0 === $i % $step && ($n - 1 - $i) >= $step)) : ?>
                        <text class="axis" x="<?php echo esc_attr(sprintf('%.1f', $x($i))); ?>" y="<?php echo esc_attr((string) ($h - 8)); ?>" text-anchor="middle"><?php echo esc_html($p['label']); ?></text>
                    <?php endif; ?>
                    <g class="pt">
                        <rect class="hit" x="<?php echo esc_attr(sprintf('%.1f', $x($i) - $plotw / ($n - 1) / 2)); ?>" y="<?php echo esc_attr((string) $pad['t']); ?>" width="<?php echo esc_attr(sprintf('%.1f', $plotw / ($n - 1))); ?>" height="<?php echo esc_attr((string) $ploth); ?>"><title><?php echo esc_html($p['label'] . ' — ' . $p['display']); ?></title></rect>
                        <circle cx="<?php echo esc_attr(sprintf('%.1f', $x($i))); ?>" cy="<?php echo esc_attr(sprintf('%.1f', $y((float) $p['value']))); ?>" r="4"/>
                    </g>
                <?php endforeach; ?>
                <circle class="last" cx="<?php echo esc_attr(sprintf('%.1f', $x($n - 1))); ?>" cy="<?php echo esc_attr(sprintf('%.1f', $y((float) $last['value']))); ?>" r="4.5"/>
            </svg>
            <figcaption><?php echo esc_html(sprintf(/* translators: %s: latest value */ __('Latest week: %s', 'vereskala-login'), $last['display'])); ?></figcaption>
            <details class="vkl-crm-table-view">
                <summary><?php esc_html_e('Show the numbers', 'vereskala-login'); ?></summary>
                <table class="widefat striped"><tbody>
                    <?php foreach ($points as $p) : ?>
                        <tr><td><?php echo esc_html($p['label']); ?></td><td><?php echo esc_html($p['display']); ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
            </details>
        </figure>
        <?php
    }

    /**
     * A round axis top: 1, 2, 2.5 or 5 × 10^n just above $v.
     */
    private static function nice(float $v): float
    {
        $exp  = floor(log10($v));
        $base = 10 ** $exp;

        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($v <= $m * $base) {
                return $m * $base;
            }
        }

        return 10 * $base;
    }

    /**
     * Compact number for axes (12k, 3.4M), in the site's digits.
     */
    public static function compact(float $v): string
    {
        if ($v >= 1e9) {
            return number_format_i18n($v / 1e9, 1) . 'B';
        }

        if ($v >= 1e6) {
            return number_format_i18n($v / 1e6, 1) . 'M';
        }

        if ($v >= 1e3) {
            return number_format_i18n($v / 1e3, 0) . 'k';
        }

        return number_format_i18n($v, 0);
    }

    public static function plain_money(float $v): string
    {
        return function_exists('wc_price') ? trim(wp_strip_all_tags(html_entity_decode(wc_price($v), ENT_QUOTES))) : number_format_i18n($v);
    }
}
