<?php
/**
 * Sales opportunities (deals) moving through pipeline stages.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.16.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Deals
 */
final class VKL_Crm_Deals extends VKL_Crm_Repository
{
    public const STATUSES = ['open', 'won', 'lost'];

    private const SORTABLE = ['id', 'created_at', 'updated_at', 'value', 'expected_close', 'position', 'closed_at'];

    protected function table_name(): string
    {
        return 'crm_deals';
    }

    protected function columns(): array
    {
        return [
            'contact_id'     => '%d',
            'pipeline_id'    => '%d',
            'stage_id'       => '%d',
            'title'          => '%s',
            'value'          => '%f',
            'owner_id'       => '%d',
            'expected_close' => '%s',
            'status'         => '%s',
            'lost_reason'    => '%s',
            'order_id'       => '%d',
            'source'         => '%s',
            'position'       => '%d',
            'closed_at'      => '%s',
        ];
    }

    /**
     * Filters: q (title), contact_id, pipeline_id, stage_id, status,
     * owner_id, order_id, close_from, close_to (Y-m-d).
     *
     * @param array<string, mixed>                 $filters
     * @param array{0: string, 1: array<int, int>} $scope From scope_sql('t.owner_id').
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function search(array $filters, array $scope, int $page = 1, int $per_page = 50, string $orderby = 'created_at', string $order = 'DESC'): array
    {
        $where = ['t.deleted_at IS NULL', $scope[0]];
        $args  = $scope[1];

        if ('' !== trim((string) ($filters['q'] ?? ''))) {
            $where[] = 't.title LIKE %s';
            $args[]  = $this->like(trim((string) $filters['q']));
        }

        foreach (['contact_id', 'pipeline_id', 'stage_id', 'order_id'] as $col) {
            if (!empty($filters[$col])) {
                $where[] = "t.{$col} = %d";
                $args[]  = (int) $filters[$col];
            }
        }

        if (isset($filters['owner_id']) && '' !== (string) $filters['owner_id']) {
            $where[] = 't.owner_id = %d';
            $args[]  = max(0, (int) $filters['owner_id']);
        }

        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where[] = 't.status = %s';
            $args[]  = $filters['status'];
        }

        foreach (['close_from' => '>=', 'close_to' => '<='] as $key => $op) {
            if (!empty($filters[$key]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters[$key])) {
                $where[] = "t.expected_close {$op} %s";
                $args[]  = $filters[$key];
            }
        }

        return $this->paginate($where, $args, $page, $per_page, $orderby, $order, self::SORTABLE, 'created_at');
    }

    /**
     * Board data: open deals of one pipeline grouped by stage, plus count
     * and value per stage. Closed deals appear in their won/lost column.
     *
     * @param array{0: string, 1: array<int, int>} $scope
     * @return array<int, array{deals: array<int, array<string, mixed>>, count: int, value: float}>
     */
    public function board(int $pipeline_id, array $scope, int $per_stage = 50): array
    {
        $rows = $this->db->get_results(
            'SELECT t.*, c.first_name, c.last_name, c.mobile
             FROM {crm_deals} t LEFT JOIN {crm_contacts} c ON c.id = t.contact_id
             WHERE t.pipeline_id = %d AND t.deleted_at IS NULL AND ' . $scope[0] . '
             ORDER BY t.stage_id, t.position, t.id DESC',
            ...array_merge([$pipeline_id], $scope[1])
        );

        $out = [];

        foreach ($rows as $row) {
            $row   = $this->hydrate($row);
            $stage = (int) $row['stage_id'];
            $out[$stage] ??= ['deals' => [], 'count' => 0, 'value' => 0.0];

            ++$out[$stage]['count'];
            $out[$stage]['value'] += (float) $row['value'];

            if (count($out[$stage]['deals']) < $per_stage) {
                $out[$stage]['deals'][] = $row;
            }
        }

        return $out;
    }

    /**
     * Open-deal totals for a pipeline: count, value, probability-weighted value.
     *
     * @param array{0: string, 1: array<int, int>} $scope
     * @return array{count: int, value: float, weighted: float}
     */
    public function open_totals(array $scope, int $pipeline_id = 0): array
    {
        $args = $scope[1];
        $pw   = '';

        if ($pipeline_id > 0) {
            $pw     = ' AND t.pipeline_id = %d';
            $args[] = $pipeline_id;
        }

        $row = $this->db->get_row(
            "SELECT COUNT(*) AS n, COALESCE(SUM(t.value), 0) AS v, COALESCE(SUM(t.value * s.probability / 100), 0) AS w
             FROM {crm_deals} t LEFT JOIN {crm_stages} s ON s.id = t.stage_id
             WHERE t.status = 'open' AND t.deleted_at IS NULL AND {$scope[0]}{$pw}",
            ...$args
        );

        return ['count' => (int) ($row['n'] ?? 0), 'value' => (float) ($row['v'] ?? 0), 'weighted' => (float) ($row['w'] ?? 0)];
    }
}
