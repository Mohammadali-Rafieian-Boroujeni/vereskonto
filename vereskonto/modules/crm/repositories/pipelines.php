<?php
/**
 * Sales pipelines and their stages.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.16.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Pipelines
 */
final class VKL_Crm_Pipelines extends VKL_Crm_Repository
{
    public const STAGE_KINDS = ['open', 'won', 'lost'];

    protected function table_name(): string
    {
        return 'crm_pipelines';
    }

    protected function columns(): array
    {
        return ['name' => '%s', 'position' => '%d', 'is_default' => '%d'];
    }

    protected function has_timestamps(): bool
    {
        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return array_map([$this, 'hydrate'], $this->db->get_results('SELECT * FROM {crm_pipelines} ORDER BY position, id'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function default_pipeline(): ?array
    {
        $row = $this->db->get_row('SELECT * FROM {crm_pipelines} ORDER BY is_default DESC, position, id LIMIT 1');

        return null === $row ? null : $this->hydrate($row);
    }

    public function create_pipeline(string $name): int
    {
        $name = trim(wp_strip_all_tags($name));

        if ('' === $name) {
            return 0;
        }

        $position = (int) $this->db->get_var('SELECT COALESCE(MAX(position), -1) + 1 FROM {crm_pipelines}');

        return $this->db->insert('crm_pipelines', [
            'name'       => mb_substr($name, 0, 100),
            'position'   => $position,
            'is_default' => 0,
            'created_at' => $this->now(),
        ], ['%s', '%d', '%d', '%s']);
    }

    public function rename(int $pipeline_id, string $name): bool
    {
        $name = mb_substr(trim(wp_strip_all_tags($name)), 0, 100);

        return '' !== $name && $this->update($pipeline_id, ['name' => $name]);
    }

    /**
     * Make one pipeline the default (where new deals go).
     */
    public function set_default(int $pipeline_id): void
    {
        if (null === $this->find($pipeline_id)) {
            return;
        }

        $this->db->query('UPDATE {crm_pipelines} SET is_default = 0');
        $this->update($pipeline_id, ['is_default' => 1]);
    }

    /**
     * Delete a pipeline (and its stages) only when it holds no deals and
     * is not the last one.
     */
    public function delete_pipeline(int $pipeline_id): bool
    {
        if (count($this->all()) < 2 || (int) $this->db->get_var('SELECT COUNT(*) FROM {crm_deals} WHERE pipeline_id = %d AND deleted_at IS NULL', $pipeline_id) > 0) {
            return false;
        }

        $was_default = (int) ($this->find($pipeline_id)['is_default'] ?? 0);

        $this->db->delete('crm_stages', ['pipeline_id' => $pipeline_id], ['%d']);
        $this->db->delete('crm_pipelines', ['id' => $pipeline_id], ['%d']);

        if ($was_default) {
            $first = $this->all()[0] ?? null;

            if (null !== $first) {
                $this->set_default((int) $first['id']);
            }
        }

        return true;
    }

    /* ---- stages -------------------------------------------------------- */

    /**
     * Move a stage one place up (-1) or down (+1) within its pipeline.
     */
    public function shift_stage(int $stage_id, int $direction): void
    {
        $stage = $this->stage($stage_id);

        if (null === $stage) {
            return;
        }

        $ids = array_map(static fn(array $s): int => (int) $s['id'], $this->stages((int) $stage['pipeline_id']));
        $at  = array_search($stage_id, $ids, true);
        $to  = $at + ($direction < 0 ? -1 : 1);

        if (false === $at || $to < 0 || $to >= count($ids)) {
            return;
        }

        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        $this->reorder_stages((int) $stage['pipeline_id'], $ids);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function stages(int $pipeline_id): array
    {
        return array_map([$this, 'hydrate_stage'], $this->db->get_results(
            'SELECT * FROM {crm_stages} WHERE pipeline_id = %d ORDER BY position, id',
            $pipeline_id
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function stage(int $stage_id): ?array
    {
        $row = $this->db->get_row('SELECT * FROM {crm_stages} WHERE id = %d', $stage_id);

        return null === $row ? null : $this->hydrate_stage($row);
    }

    /**
     * First open stage of a pipeline (where new deals start).
     *
     * @return array<string, mixed>|null
     */
    public function first_stage(int $pipeline_id): ?array
    {
        foreach ($this->stages($pipeline_id) as $stage) {
            if ('open' === $stage['kind']) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data name, probability, kind, color
     */
    public function save_stage(int $pipeline_id, array $data, int $stage_id = 0): int
    {
        $row = [
            'name'        => mb_substr(trim(wp_strip_all_tags((string) ($data['name'] ?? ''))), 0, 100),
            'probability' => max(0, min(100, (int) ($data['probability'] ?? 0))),
            'kind'        => in_array($data['kind'] ?? '', self::STAGE_KINDS, true) ? $data['kind'] : 'open',
            'color'       => preg_match('/^#[0-9a-f]{6}$/i', (string) ($data['color'] ?? '')) ? strtolower((string) $data['color']) : '',
        ];

        if ('' === $row['name']) {
            return 0;
        }

        if ($stage_id > 0) {
            $this->db->update('crm_stages', $row, ['id' => $stage_id, 'pipeline_id' => $pipeline_id], ['%s', '%d', '%s', '%s'], ['%d', '%d']);

            return $stage_id;
        }

        $row['pipeline_id'] = $pipeline_id;
        $row['position']    = (int) $this->db->get_var('SELECT COALESCE(MAX(position), -1) + 1 FROM {crm_stages} WHERE pipeline_id = %d', $pipeline_id);

        return $this->db->insert('crm_stages', $row, ['%s', '%d', '%s', '%s', '%d', '%d']);
    }

    /**
     * @param int[] $stage_ids In the new order.
     */
    public function reorder_stages(int $pipeline_id, array $stage_ids): void
    {
        foreach (array_values($stage_ids) as $position => $stage_id) {
            $this->db->update('crm_stages', ['position' => $position], ['id' => (int) $stage_id, 'pipeline_id' => $pipeline_id], ['%d'], ['%d', '%d']);
        }
    }

    /**
     * Delete a stage only when no deal (open or closed) sits in it.
     */
    public function delete_stage(int $stage_id): bool
    {
        if ((int) $this->db->get_var('SELECT COUNT(*) FROM {crm_deals} WHERE stage_id = %d AND deleted_at IS NULL', $stage_id) > 0) {
            return false;
        }

        return $this->db->delete('crm_stages', ['id' => $stage_id], ['%d']) > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate_stage(array $row): array
    {
        foreach (['id', 'pipeline_id', 'position', 'probability'] as $col) {
            $row[$col] = (int) $row[$col];
        }

        return $row;
    }
}
