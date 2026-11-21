<?php
/**
 * Follow-up tasks assigned to CRM staff.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.16.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Tasks
 */
final class VKL_Crm_Tasks extends VKL_Crm_Repository
{
    public const STATUSES   = ['open', 'done', 'cancelled'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /** Views of the task list. */
    public const VIEWS = ['today', 'overdue', 'upcoming', 'no_date', 'done', 'all'];

    private const SORTABLE = ['id', 'due_at', 'created_at', 'priority', 'completed_at'];

    protected function table_name(): string
    {
        return 'crm_tasks';
    }

    protected function columns(): array
    {
        return [
            'title'        => '%s',
            'description'  => '%s',
            'contact_id'   => '%d',
            'deal_id'      => '%d',
            'assignee_id'  => '%d',
            'created_by'   => '%d',
            'due_at'       => '%s',
            'remind_at'    => '%s',
            'reminded_at'  => '%s',
            'priority'     => '%s',
            'status'       => '%s',
            'completed_at' => '%s',
        ];
    }

    public function complete(int $id): bool
    {
        return $this->update($id, ['status' => 'done', 'completed_at' => $this->now()]);
    }

    public function reopen(int $id): bool
    {
        return $this->update($id, ['status' => 'open', 'completed_at' => null]);
    }

    /**
     * Filters: view (see VIEWS), assignee_id, contact_id, deal_id,
     * priority, q. "Today" is the shop's calendar day, given in UTC
     * bounds by the caller ($day_start_utc, $day_end_utc).
     *
     * @param array<string, mixed>                 $filters
     * @param array{0: string, 1: array<int, int>} $scope From scope_sql('t.assignee_id').
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function search(array $filters, array $scope, string $day_start_utc, string $day_end_utc, int $page = 1, int $per_page = 50): array
    {
        $where = ['t.deleted_at IS NULL', $scope[0]];
        $args  = $scope[1];
        $view  = in_array($filters['view'] ?? '', self::VIEWS, true) ? $filters['view'] : 'all';
        $order = 'ASC';
        $by    = 'due_at';

        switch ($view) {
            case 'today':
                $where[] = "t.status = 'open' AND t.due_at BETWEEN %s AND %s";
                array_push($args, $day_start_utc, $day_end_utc);
                break;
            case 'overdue':
                $where[] = "t.status = 'open' AND t.due_at < %s";
                $args[]  = $day_start_utc;
                break;
            case 'upcoming':
                $where[] = "t.status = 'open' AND t.due_at > %s";
                $args[]  = $day_end_utc;
                break;
            case 'no_date':
                $where[] = "t.status = 'open' AND t.due_at IS NULL";
                $by      = 'created_at';
                break;
            case 'done':
                $where[] = "t.status = 'done'";
                $by      = 'completed_at';
                $order   = 'DESC';
                break;
            default:
                $by    = 'created_at';
                $order = 'DESC';
        }

        foreach (['contact_id', 'deal_id'] as $col) {
            if (!empty($filters[$col])) {
                $where[] = "t.{$col} = %d";
                $args[]  = (int) $filters[$col];
            }
        }

        if (isset($filters['assignee_id']) && '' !== (string) $filters['assignee_id']) {
            $where[] = 't.assignee_id = %d';
            $args[]  = max(0, (int) $filters['assignee_id']);
        }

        if (!empty($filters['priority']) && in_array($filters['priority'], self::PRIORITIES, true)) {
            $where[] = 't.priority = %s';
            $args[]  = $filters['priority'];
        }

        if ('' !== trim((string) ($filters['q'] ?? ''))) {
            $where[] = 't.title LIKE %s';
            $args[]  = $this->like(trim((string) $filters['q']));
        }

        return $this->paginate($where, $args, $page, $per_page, $by, $order, self::SORTABLE, 'due_at');
    }

    /**
     * Open-task counts for one user (or everyone in scope): today, overdue.
     *
     * @param array{0: string, 1: array<int, int>} $scope
     * @return array{today: int, overdue: int, open: int}
     */
    public function counts(array $scope, string $day_start_utc, string $day_end_utc): array
    {
        $row = $this->db->get_row(
            "SELECT
                SUM(t.due_at BETWEEN %s AND %s) AS today,
                SUM(t.due_at < %s) AS overdue,
                COUNT(*) AS open
             FROM {crm_tasks} t
             WHERE t.status = 'open' AND t.deleted_at IS NULL AND {$scope[0]}",
            ...array_merge([$day_start_utc, $day_end_utc, $day_start_utc], $scope[1])
        );

        return ['today' => (int) ($row['today'] ?? 0), 'overdue' => (int) ($row['overdue'] ?? 0), 'open' => (int) ($row['open'] ?? 0)];
    }

    /**
     * Open tasks whose reminder time has come and that were not reminded.
     *
     * @return array<int, array<string, mixed>>
     */
    public function due_reminders(int $limit = 100): array
    {
        return array_map([$this, 'hydrate'], $this->db->get_results(
            "SELECT * FROM {crm_tasks}
             WHERE status = 'open' AND deleted_at IS NULL AND remind_at IS NOT NULL
               AND remind_at <= %s AND reminded_at IS NULL
             ORDER BY remind_at LIMIT %d",
            $this->now(),
            max(1, min(500, $limit))
        ));
    }
}
