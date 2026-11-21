<?php
/**
 * CRM reports: periods, KPIs and aggregates for the dashboard, the
 * reports page and the CSV exports.
 *
 * Everything takes the caller's scope (VKL_Crm_Access::scope_sql), so an
 * agent's figures cover only their own records. Periods are shop-time
 * calendar ranges (Jalali months, weeks starting on Saturday) converted
 * to UTC for the queries. Results are cached for ten minutes per user,
 * scope and period.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.19.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Reports
 */
final class VKL_Crm_Reports
{
    public const PERIODS = ['this_month', 'last_month', 'last_30', 'last_90', 'this_year', 'custom'];

    private const CACHE_TTL = 600;

    private VKL_Database $db;

    public function __construct(VKL_Database $db)
    {
        $this->db = $db;
    }

    /* ------------------------------------------------------------------ *
     *  Periods
     * ------------------------------------------------------------------ */

    /**
     * [from_utc, to_utc, label] for a preset (or a custom Jalali range).
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function period(string $key, string $from = '', string $to = ''): array
    {
        $tz  = wp_timezone();
        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $tz);
        [$jy, $jm] = VKL_Jalali::g2j((int) $now->format('Y'), (int) $now->format('n'), (int) $now->format('j'));

        $local = static function (int $y, int $m, int $d) use ($tz): DateTimeImmutable {
            [$gy, $gm, $gd] = VKL_Jalali::j2g($y, $m, $d);

            return new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $gy, $gm, $gd), $tz);
        };

        switch ($key) {
            case 'last_month':
                [$py, $pm] = 1 === $jm ? [$jy - 1, 12] : [$jy, $jm - 1];
                $start = $local($py, $pm, 1);
                $end   = $local($jy, $jm, 1)->modify('-1 second');
                $label = sprintf('%s %d', VKL_Jalali::MONTHS[$pm - 1], $py);
                break;
            case 'last_30':
            case 'last_90':
                $days  = 'last_30' === $key ? 30 : 90;
                $start = $now->setTime(0, 0)->modify('-' . ($days - 1) . ' days');
                $end   = $now->setTime(23, 59, 59);
                $label = sprintf(/* translators: %d: days */ __('Last %d days', 'vereskala-login'), $days);
                break;
            case 'this_year':
                $start = $local($jy, 1, 1);
                $end   = $now->setTime(23, 59, 59);
                $label = sprintf(/* translators: %d: Jalali year */ __('Year %d', 'vereskala-login'), $jy);
                break;
            case 'custom':
                $f = VKL_Crm_Task_Service::local_to_utc($from, '00:00');
                $t = VKL_Crm_Task_Service::local_to_utc($to, '23:59');

                if (is_string($f) && is_string($t) && $f <= $t) {
                    return [$f, gmdate('Y-m-d H:i:59', (int) strtotime($t . ' UTC')), VKL_Jalali::format($f, 'short') . ' – ' . VKL_Jalali::format($t, 'short')];
                }
                // Invalid range: fall back to this month.
                // no break
            default:
                $start = $local($jy, $jm, 1);
                $end   = $now->setTime(23, 59, 59);
                $label = sprintf('%s %d', VKL_Jalali::MONTHS[$jm - 1], $jy);
        }

        return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s'), $label];
    }

    /* ------------------------------------------------------------------ *
     *  Aggregates (all scoped)
     * ------------------------------------------------------------------ */

    /**
     * Headline figures for a period.
     *
     * @param array{0: string, 1: array<int, int>} $scope Contact-owner scope (t.owner_id).
     * @return array<string, float|int>
     */
    public function kpis(array $scope, string $from, string $to): array
    {
        return $this->cached('kpis', [$scope, $from, $to], function () use ($scope, $from, $to): array {
            [$sw, $sa] = $this->scope($scope, 't.owner_id');

            $new = (int) $this->db->get_var(
                "SELECT COUNT(*) FROM {crm_contacts} t WHERE t.deleted_at IS NULL AND t.created_at BETWEEN %s AND %s AND {$sw}",
                ...array_merge([$from, $to], $sa)
            );

            $closed = $this->db->get_row(
                "SELECT SUM(t.status = 'won') AS won, SUM(t.status = 'lost') AS lost,
                        COALESCE(SUM(CASE WHEN t.status = 'won' THEN t.value END), 0) AS won_value
                 FROM {crm_deals} t
                 WHERE t.deleted_at IS NULL AND t.status IN ('won','lost') AND t.closed_at BETWEEN %s AND %s AND {$sw}",
                ...array_merge([$from, $to], $sa)
            );

            $open = $this->db->get_row(
                "SELECT COUNT(*) AS n, COALESCE(SUM(t.value), 0) AS v, COALESCE(SUM(t.value * s.probability / 100), 0) AS w
                 FROM {crm_deals} t LEFT JOIN {crm_stages} s ON s.id = t.stage_id
                 WHERE t.deleted_at IS NULL AND t.status = 'open' AND {$sw}",
                ...$sa
            );

            [$aw, $aa] = $this->scope($scope, 'c.owner_id');
            $acts = (int) $this->db->get_var(
                "SELECT COUNT(*) FROM {crm_activities} a INNER JOIN {crm_contacts} c ON c.id = a.contact_id
                 WHERE a.deleted_at IS NULL AND a.type IN ('note','call','meeting','email','sms') AND a.created_at BETWEEN %s AND %s AND {$aw}",
                ...array_merge([$from, $to], $aa)
            );

            $won  = (int) ($closed['won'] ?? 0);
            $lost = (int) ($closed['lost'] ?? 0);

            return [
                'new_contacts' => $new,
                'won'          => $won,
                'lost'         => $lost,
                'won_value'    => (float) ($closed['won_value'] ?? 0),
                'win_rate'     => $won + $lost > 0 ? round(100 * $won / ($won + $lost), 1) : 0.0,
                'open_count'   => (int) ($open['n'] ?? 0),
                'open_value'   => (float) ($open['v'] ?? 0),
                'weighted'     => (float) ($open['w'] ?? 0),
                'activities'   => $acts,
            ];
        });
    }

    /**
     * Open deals per stage of a pipeline (the funnel).
     *
     * @param array{0: string, 1: array<int, int>} $scope
     * @return array<int, array{id: int, name: string, color: string, kind: string, count: int, value: float}>
     */
    public function funnel(int $pipeline_id, array $scope): array
    {
        return $this->cached('funnel', [$pipeline_id, $scope], function () use ($pipeline_id, $scope): array {
            [$sw, $sa] = $this->scope($scope, 't.owner_id');

            $rows = $this->db->get_results(
                "SELECT s.id, s.name, s.color, s.kind, s.position,
                        COUNT(t.id) AS n, COALESCE(SUM(t.value), 0) AS v
                 FROM {crm_stages} s
                 LEFT JOIN {crm_deals} t ON t.stage_id = s.id AND t.deleted_at IS NULL AND t.status = 'open' AND {$sw}
                 WHERE s.pipeline_id = %d AND s.kind = 'open'
                 GROUP BY s.id ORDER BY s.position, s.id",
                ...array_merge($sa, [$pipeline_id])
            );

            return array_map(static fn(array $r): array => [
                'id'    => (int) $r['id'],
                'name'  => (string) $r['name'],
                'color' => (string) $r['color'],
                'kind'  => (string) $r['kind'],
                'count' => (int) $r['n'],
                'value' => (float) $r['v'],
            ], $rows);
        });
    }

    /**
     * Weekly series (Saturday-start shop weeks): new contacts and won
     * deal value, oldest week first, $weeks long (empty weeks included).
     *
     * @param array{0: string, 1: array<int, int>} $scope
     * @return array<int, array{week: string, label: string, contacts: int, won_value: float, won: int}>
     */
    public function weekly(array $scope, int $weeks = 12): array
    {
        return $this->cached('weekly', [$scope, $weeks], function () use ($scope, $weeks): array {
            $tz    = wp_timezone();
            $utc   = new DateTimeZone('UTC');
            $today = new DateTimeImmutable('today', $tz);
            $back  = ((int) $today->format('w') + 1) % 7; // days since Saturday
            $start = $today->modify("-{$back} days")->modify('-' . ($weeks - 1) . ' weeks');
            $out   = [];

            for ($i = 0; $i < $weeks; $i++) {
                $ws    = $start->modify("+{$i} weeks");
                $key   = $ws->format('Y-m-d');
                $out[$key] = [
                    'week'      => $key,
                    'label'     => VKL_Jalali::format($ws->setTimezone($utc)->format('Y-m-d H:i:s'), 'short'),
                    'contacts'  => 0,
                    'won_value' => 0.0,
                    'won'       => 0,
                ];
            }

            $from = $start->setTimezone($utc)->format('Y-m-d H:i:s');

            // Week of a UTC timestamp: its shop-time day, counted from the
            // first Saturday. '' when before the range.
            $bucket = static function (string $utc_dt) use ($tz, $utc, $start): string {
                $day  = (new DateTimeImmutable($utc_dt, $utc))->setTimezone($tz)->setTime(0, 0);
                $days = (int) round(($day->getTimestamp() - $start->getTimestamp()) / DAY_IN_SECONDS);

                return $days < 0 ? '' : $start->modify('+' . intdiv($days, 7) . ' weeks')->format('Y-m-d');
            };

            [$sw, $sa] = $this->scope($scope, 't.owner_id');

            foreach ($this->db->get_results("SELECT t.created_at FROM {crm_contacts} t WHERE t.deleted_at IS NULL AND t.created_at >= %s AND {$sw}", ...array_merge([$from], $sa)) as $r) {
                $k = $bucket((string) $r['created_at']);
                if (isset($out[$k])) {
                    ++$out[$k]['contacts'];
                }
            }

            foreach ($this->db->get_results("SELECT t.closed_at, t.value FROM {crm_deals} t WHERE t.deleted_at IS NULL AND t.status = 'won' AND t.closed_at >= %s AND {$sw}", ...array_merge([$from], $sa)) as $r) {
                $k = $bucket((string) $r['closed_at']);
                if (isset($out[$k])) {
                    ++$out[$k]['won'];
                    $out[$k]['won_value'] += (float) $r['value'];
                }
            }

            return array_values($out);
        });
    }

    /**
     * Per lead source: contacts added in the period, how many of them are
     * customers now, and won deal value of contacts from that source.
     *
     * @param array{0: string, 1: array<int, int>} $scope
     * @return array<int, array{source: string, contacts: int, customers: int, won_value: float}>
     */
    public function sources(array $scope, string $from, string $to): array
    {
        return $this->cached('sources', [$scope, $from, $to], function () use ($scope, $from, $to): array {
            [$sw, $sa] = $this->scope($scope, 't.owner_id');

            $rows = $this->db->get_results(
                "SELECT t.source, COUNT(*) AS contacts, SUM(t.lifecycle = 'customer') AS customers
                 FROM {crm_contacts} t
                 WHERE t.deleted_at IS NULL AND t.created_at BETWEEN %s AND %s AND {$sw}
                 GROUP BY t.source ORDER BY contacts DESC",
                ...array_merge([$from, $to], $sa)
            );

            [$dw, $da] = $this->scope($scope, 'd.owner_id');
            $won = [];

            foreach ($this->db->get_results(
                "SELECT c.source, COALESCE(SUM(d.value), 0) AS v FROM {crm_deals} d INNER JOIN {crm_contacts} c ON c.id = d.contact_id
                 WHERE d.deleted_at IS NULL AND d.status = 'won' AND d.closed_at BETWEEN %s AND %s AND {$dw}
                 GROUP BY c.source",
                ...array_merge([$from, $to], $da)
            ) as $r) {
                $won[(string) $r['source']] = (float) $r['v'];
            }

            $out = [];

            foreach ($rows as $r) {
                $out[] = ['source' => (string) $r['source'], 'contacts' => (int) $r['contacts'], 'customers' => (int) $r['customers'], 'won_value' => $won[(string) $r['source']] ?? 0.0];
                unset($won[(string) $r['source']]);
            }

            foreach ($won as $source => $v) {
                $out[] = ['source' => $source, 'contacts' => 0, 'customers' => 0, 'won_value' => $v];
            }

            return $out;
        });
    }

    /**
     * Deals lost in the period, grouped by reason.
     *
     * @param array{0: string, 1: array<int, int>} $scope
     * @return array<int, array{reason: string, count: int, value: float}>
     */
    public function lost_reasons(array $scope, string $from, string $to): array
    {
        return $this->cached('lost', [$scope, $from, $to], function () use ($scope, $from, $to): array {
            [$sw, $sa] = $this->scope($scope, 't.owner_id');

            return array_map(static fn(array $r): array => ['reason' => (string) $r['lost_reason'], 'count' => (int) $r['n'], 'value' => (float) $r['v']], $this->db->get_results(
                "SELECT t.lost_reason, COUNT(*) AS n, COALESCE(SUM(t.value), 0) AS v FROM {crm_deals} t
                 WHERE t.deleted_at IS NULL AND t.status = 'lost' AND t.closed_at BETWEEN %s AND %s AND {$sw}
                 GROUP BY t.lost_reason ORDER BY n DESC",
                ...array_merge([$from, $to], $sa)
            ));
        });
    }

    /**
     * Per staff member: contacts added to them, logged activities by
     * type, deals won/lost and their value, tasks done and overdue.
     * Managers only (no scope: the whole team).
     *
     * @return array<int, array<string, mixed>>
     */
    public function agents(string $from, string $to): array
    {
        return $this->cached('agents', [$from, $to], function () use ($from, $to): array {
            $out = [];

            foreach (vkl('crm.access')->assignable_users() as $uid => $name) {
                $out[$uid] = [
                    'user_id' => $uid, 'name' => $name, 'contacts' => 0, 'calls' => 0, 'meetings' => 0, 'notes' => 0,
                    'won' => 0, 'lost' => 0, 'won_value' => 0.0, 'tasks_done' => 0, 'tasks_overdue' => 0,
                ];
            }

            $add = static function (array $rows, callable $fn) use (&$out): void {
                foreach ($rows as $r) {
                    $uid = (int) $r['uid'];
                    if (isset($out[$uid])) {
                        $fn($out[$uid], $r);
                    }
                }
            };

            $add($this->db->get_results('SELECT owner_id AS uid, COUNT(*) AS n FROM {crm_contacts} WHERE deleted_at IS NULL AND created_at BETWEEN %s AND %s GROUP BY owner_id', $from, $to),
                static function (array &$a, array $r): void { $a['contacts'] = (int) $r['n']; });

            $add($this->db->get_results("SELECT author_id AS uid, type, COUNT(*) AS n FROM {crm_activities} WHERE deleted_at IS NULL AND type IN ('call','meeting','note') AND created_at BETWEEN %s AND %s GROUP BY author_id, type", $from, $to),
                static function (array &$a, array $r): void { $a[['call' => 'calls', 'meeting' => 'meetings', 'note' => 'notes'][$r['type']]] = (int) $r['n']; });

            $add($this->db->get_results("SELECT owner_id AS uid, SUM(status = 'won') AS won, SUM(status = 'lost') AS lost, COALESCE(SUM(CASE WHEN status = 'won' THEN value END), 0) AS v FROM {crm_deals} WHERE deleted_at IS NULL AND status IN ('won','lost') AND closed_at BETWEEN %s AND %s GROUP BY owner_id", $from, $to),
                static function (array &$a, array $r): void { $a['won'] = (int) $r['won']; $a['lost'] = (int) $r['lost']; $a['won_value'] = (float) $r['v']; });

            $add($this->db->get_results("SELECT assignee_id AS uid, COUNT(*) AS n FROM {crm_tasks} WHERE deleted_at IS NULL AND status = 'done' AND completed_at BETWEEN %s AND %s GROUP BY assignee_id", $from, $to),
                static function (array &$a, array $r): void { $a['tasks_done'] = (int) $r['n']; });

            $add($this->db->get_results("SELECT assignee_id AS uid, COUNT(*) AS n FROM {crm_tasks} WHERE deleted_at IS NULL AND status = 'open' AND due_at < %s GROUP BY assignee_id", gmdate('Y-m-d H:i:s')),
                static function (array &$a, array $r): void { $a['tasks_overdue'] = (int) $r['n']; });

            foreach ($out as &$a) {
                $a['win_rate'] = $a['won'] + $a['lost'] > 0 ? round(100 * $a['won'] / ($a['won'] + $a['lost']), 1) : 0.0;
            }

            usort($out, static fn(array $x, array $y): int => $y['won_value'] <=> $x['won_value'] ?: strcmp($x['name'], $y['name']));

            return $out;
        });
    }

    /**
     * Latest manual and system activities in scope (dashboard feed).
     *
     * @param array{0: string, 1: array<int, int>} $scope
     * @return array<int, array<string, mixed>>
     */
    public function recent(array $scope, int $limit = 10): array
    {
        [$sw, $sa] = $this->scope($scope, 'c.owner_id');

        return $this->db->get_results(
            "SELECT a.id, a.type, a.subject, a.body, a.author_id, a.created_at, a.contact_id, a.deal_id,
                    c.first_name, c.last_name, c.company, c.mobile, c.email
             FROM {crm_activities} a INNER JOIN {crm_contacts} c ON c.id = a.contact_id
             WHERE a.deleted_at IS NULL AND {$sw}
             ORDER BY a.id DESC LIMIT %d",
            ...array_merge($sa, [max(1, min(50, $limit))])
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * Re-target a scope fragment built for "t.owner_id" to another alias.
     *
     * @param array{0: string, 1: array<int, int>} $scope
     * @return array{0: string, 1: array<int, int>}
     */
    private function scope(array $scope, string $column): array
    {
        return [str_replace('t.owner_id', $column, $scope[0]), $scope[1]];
    }

    /**
     * @param array<int, mixed> $key_parts
     * @return mixed
     */
    private function cached(string $name, array $key_parts, callable $compute)
    {
        $key = 'vkl_crm_rep_' . $name . '_' . md5((string) wp_json_encode($key_parts) . '|' . get_option('vkl_crm_rep_gen', '0'));
        $hit = get_transient($key);

        if (false !== $hit) {
            return $hit;
        }

        $value = $compute();
        set_transient($key, $value, self::CACHE_TTL);

        return $value;
    }

    /**
     * Drop every cached report (e.g. after a bulk import or in tests).
     */
    public static function flush(): void
    {
        update_option('vkl_crm_rep_gen', (string) microtime(true), false);
    }
}
