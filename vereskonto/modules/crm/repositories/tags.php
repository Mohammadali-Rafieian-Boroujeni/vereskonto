<?php
/**
 * CRM tags and categories (type 'tag' or 'category'), and which
 * contacts carry them.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.16.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Tags
 */
final class VKL_Crm_Tags extends VKL_Crm_Repository
{
    public const TYPES = ['tag', 'category'];

    protected function table_name(): string
    {
        return 'crm_tags';
    }

    protected function columns(): array
    {
        return ['type' => '%s', 'name' => '%s', 'slug' => '%s', 'color' => '%s'];
    }

    protected function has_timestamps(): bool
    {
        return false;
    }

    /**
     * @return array<int, array<string, mixed>> With a contact count each.
     */
    public function all(string $type = ''): array
    {
        $sql  = 'SELECT t.*, (SELECT COUNT(*) FROM {crm_contact_tag} ct WHERE ct.tag_id = t.id) AS contacts FROM {crm_tags} t';
        $rows = in_array($type, self::TYPES, true)
            ? $this->db->get_results($sql . ' WHERE t.type = %s ORDER BY t.name', $type)
            : $this->db->get_results($sql . ' ORDER BY t.type, t.name');

        return array_map(function (array $r): array {
            $r             = $this->hydrate($r);
            $r['contacts'] = (int) $r['contacts'];

            return $r;
        }, $rows);
    }

    /**
     * Create a tag, or return the existing one with the same name/type.
     */
    public function ensure(string $name, string $type = 'tag', string $color = ''): int
    {
        $name = trim(wp_strip_all_tags($name));
        $type = in_array($type, self::TYPES, true) ? $type : 'tag';

        if ('' === $name) {
            return 0;
        }

        $slug     = self::slugify($name);
        $existing = $this->db->get_var('SELECT id FROM {crm_tags} WHERE type = %s AND slug = %s', $type, $slug);

        if (null !== $existing) {
            return (int) $existing;
        }

        return $this->db->insert('crm_tags', [
            'type'       => $type,
            'name'       => mb_substr($name, 0, 100),
            'slug'       => $slug,
            'color'      => preg_match('/^#[0-9a-f]{6}$/i', $color) ? strtolower($color) : '',
            'created_at' => $this->now(),
        ], ['%s', '%s', '%s', '%s', '%s']);
    }

    /**
     * Delete a tag and detach it from every contact.
     */
    public function delete_tag(int $tag_id): void
    {
        $this->db->delete('crm_contact_tag', ['tag_id' => $tag_id], ['%d']);
        $this->db->delete('crm_tags', ['id' => $tag_id], ['%d']);
    }

    public function attach(int $contact_id, int $tag_id): void
    {
        if ($contact_id <= 0 || $tag_id <= 0) {
            return;
        }

        $this->db->query(
            'INSERT IGNORE INTO {crm_contact_tag} (contact_id, tag_id, created_at) VALUES (%d, %d, %s)',
            $contact_id,
            $tag_id,
            $this->now()
        );
    }

    public function detach(int $contact_id, int $tag_id): void
    {
        $this->db->delete('crm_contact_tag', ['contact_id' => $contact_id, 'tag_id' => $tag_id], ['%d', '%d']);
    }

    /**
     * Replace a contact's tags with exactly $tag_ids.
     *
     * @param int[] $tag_ids
     */
    public function set_for_contact(int $contact_id, array $tag_ids): void
    {
        $this->db->delete('crm_contact_tag', ['contact_id' => $contact_id], ['%d']);

        foreach (array_unique(array_filter(array_map('intval', $tag_ids))) as $tag_id) {
            $this->attach($contact_id, $tag_id);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function for_contact(int $contact_id): array
    {
        return array_map([$this, 'hydrate'], $this->db->get_results(
            'SELECT t.* FROM {crm_tags} t INNER JOIN {crm_contact_tag} ct ON ct.tag_id = t.id WHERE ct.contact_id = %d ORDER BY t.type, t.name',
            $contact_id
        ));
    }

    /**
     * Unicode-safe slug: Persian names keep their letters (sanitize_title
     * would turn them into a percent-encoded string).
     */
    public static function slugify(string $name): string
    {
        // WordPress polyfills mb_substr but not mb_strtolower.
        $slug = function_exists('mb_strtolower') ? mb_strtolower(trim($name)) : strtolower(trim($name));
        $slug = (string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug);
        $slug = trim($slug, '-');

        return '' === $slug ? substr(md5($name), 0, 12) : mb_substr($slug, 0, 100);
    }
}
