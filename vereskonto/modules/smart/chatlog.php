<?php
/**
 * Chatbot conversation history.
 *
 * Stores every exchange (visitor message + bot reply) per anonymous
 * session so the shop owner can review real customer questions and the
 * bot keeps context. Retention: rows older than 90 days are purged
 * opportunistically (no extra cron needed).
 *
 * @package Vereskonto\Modules\Smart
 * @since   1.10.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Smart_Chatlog
 */
final class VKL_Smart_Chatlog extends VKL_Repository
{
    /**
     * Retention in days.
     */
    private const RETENTION_DAYS = 90;

    /**
     * Table base name.
     */
    protected function table_name(): string
    {
        return 'chat_messages';
    }

    /**
     * Register schema.
     *
     * @param VKL_Migrator $migrator Migrator.
     */
    public static function register_migrations(VKL_Migrator $migrator): void
    {
        // 1.11.0 re-declares the table with the structured 'topic' column
        // (dbDelta adds it in place; existing rows keep an empty topic).
        foreach (['1.10.0', '1.11.0'] as $version) {
            $migrator->register($version, [self::class, 'create_table']);
        }

        // 2.9.14: a guest's mobile number, confirmed at the phone gate, was
        // only ever kept in a browser cookie — the shop had no way to call
        // a visitor back once the chat tab closed. Stamped on every row of
        // the session (denormalised, same as topic) so recent_sessions()
        // can surface it with one query, no join needed.
        $migrator->register('2.9.14', static function (): void {
            global $wpdb;

            $table = $wpdb->prefix . 'vkl_chat_messages';
            $cols  = $wpdb->get_col("SHOW COLUMNS FROM {$table}"); // phpcs:ignore

            if (is_array($cols) && !in_array('phone', $cols, true)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN phone VARCHAR(15) NOT NULL DEFAULT '' AFTER topic"); // phpcs:ignore
            }
        });

        // 2.9.16: live human takeover. A tiny per-session state table —
        // one row, flipped on when a customer marks a bot reply unhelpful
        // (or the shop wants to jump in), flipped off again the moment
        // staff actually answers. The admin's live inbox lists sessions
        // WHERE needs_human = 1; the widget's own poll loop only starts
        // once this is set, so the vast majority of chats — the bot
        // answers, nobody escalates — never poll the server at all.
        $migrator->register('2.9.16', static function (): void {
            global $wpdb;

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $charset = $wpdb->get_charset_collate();

            dbDelta(
                "CREATE TABLE {$wpdb->prefix}vkl_chat_sessions (
                    session VARCHAR(40) NOT NULL,
                    needs_human TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY  (session),
                    KEY needs_human (needs_human)
                ) {$charset};"
            );
        });

        // 2.9.17: two things.
        //
        // First, chat_sessions gets a short numeric id. The 32-character
        // session token is fine for a cookie, useless in an SMS or an
        // admin screen — staff SMS now quotes this as the "conversation
        // number" so a reply can say "regarding #482" instead of nothing
        // identifying which chat it means. This is ephemeral routing
        // state, not conversation content (that lives in chat_messages),
        // so rebuilding the table on upgrade from the old shape — losing
        // any currently-set needs_human flags — is a one-time, harmless
        // reset rather than something worth a delicate ALTER.
        //
        // Second, chat_learned: every staff reply is remembered against
        // the question that prompted it, so the next customer asking
        // something similar gets a support-verified answer instead of
        // the bot's best guess. Matching happens in PHP against the same
        // normalize()/tokenize() pipeline the bot's own intents already
        // use (see VKL_Smart_Chatbot::learned_answer()) rather than the
        // database's own full-text relevance tuning, which varies across
        // hosts — so no special index is needed here.
        $migrator->register('2.9.17', static function (): void {
            global $wpdb;

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $charset        = $wpdb->get_charset_collate();
            $sessions_table = $wpdb->prefix . 'vkl_chat_sessions';
            $cols           = $wpdb->get_col("SHOW COLUMNS FROM {$sessions_table}"); // phpcs:ignore

            if (is_array($cols) && !in_array('id', $cols, true)) {
                $wpdb->query("DROP TABLE IF EXISTS {$sessions_table}"); // phpcs:ignore
            }

            dbDelta(
                "CREATE TABLE {$sessions_table} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    session VARCHAR(40) NOT NULL,
                    needs_human TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    UNIQUE KEY session (session),
                    KEY needs_human (needs_human)
                ) {$charset};"
            );

            dbDelta(
                "CREATE TABLE {$wpdb->prefix}vkl_chat_learned (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    question TEXT NOT NULL,
                    answer TEXT NOT NULL,
                    hits INT UNSIGNED NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY  (id)
                ) {$charset};"
            );
        });
    }

    /**
     * Create/upgrade the table via dbDelta.
     */
    public static function create_table(): void
    {
        (static function (): void {
            global $wpdb;

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $charset = $wpdb->get_charset_collate();

            dbDelta(
                "CREATE TABLE {$wpdb->prefix}vkl_chat_messages (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    session VARCHAR(40) NOT NULL,
                    user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    role VARCHAR(10) NOT NULL DEFAULT 'user',
                    topic VARCHAR(60) NOT NULL DEFAULT '',
                    message TEXT NOT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    KEY session (session),
                    KEY topic (topic),
                    KEY created_at (created_at)
                ) {$charset};"
            );
        })();
    }

    /**
     * Store one message.
     *
     * @param string $session Session token.
     * @param int    $user_id User id (0 = guest).
     * @param string $role    'user', 'bot' or 'staff'.
     * @param string $message Message text.
     * @param string $topic   Detected category slug, '' when none.
     * @param string $phone   The visitor's mobile number, when known —
     *                        logged-in customers' billing phone, or a
     *                        guest's number from the phone gate. '' when
     *                        neither is available.
     *
     * @return int The new row's id (0 on failure) — callers polling for
     *             "anything after this point" key off it.
     */
    public function add(string $session, int $user_id, string $role, string $message, string $topic = '', string $phone = ''): int
    {
        $id = $this->insert_row([
            'session'    => substr($session, 0, 40),
            'user_id'    => $user_id,
            'role'       => in_array($role, ['bot', 'staff'], true) ? $role : 'user',
            'topic'      => substr(sanitize_key($topic), 0, 60),
            'phone'      => substr(preg_replace('/[^0-9]/', '', $phone) ?? '', 0, 15),
            'message'    => mb_substr($message, 0, 2000),
            'created_at' => $this->now(),
        ]);

        // Opportunistic retention purge (~1% of inserts).
        if (1 === wp_rand(1, 100)) {
            $this->db->query(
                'DELETE FROM {chat_messages} WHERE created_at < %s',
                [gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS)]
            );
            $this->db->query(
                'DELETE FROM {chat_sessions} WHERE updated_at < %s',
                [gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS)]
            );
        }

        return $id;
    }

    /**
     * Ensure a chat_sessions row exists for this session and return its
     * short numeric id — the "conversation number" quoted in staff SMS
     * and shown in the admin inbox, since the 32-character session token
     * itself means nothing read over the phone or typed into a reply.
     * Safe to call on every message: existing rows just get their
     * updated_at bumped, never a new one.
     */
    public function touch_session(string $session): int
    {
        $session = substr($session, 0, 40);

        $this->db->query(
            "INSERT INTO {chat_sessions} (session, needs_human, updated_at) VALUES (%s, 0, %s)
             ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
            [$session, $this->now()]
        );

        return (int) $this->db->get_var('SELECT id FROM {chat_sessions} WHERE session = %s', $session);
    }

    /**
     * Flag a session as waiting on a human reply — a customer marked a bot
     * answer unhelpful, or the shop wants to step in. Listed in the admin
     * live inbox until a staff reply (add_staff_reply()) clears it again.
     */
    public function mark_needs_human(string $session): void
    {
        $session = substr($session, 0, 40);

        $this->db->query(
            "INSERT INTO {chat_sessions} (session, needs_human, updated_at) VALUES (%s, 1, %s)
             ON DUPLICATE KEY UPDATE needs_human = 1, updated_at = VALUES(updated_at)",
            [$session, $this->now()]
        );
    }

    /**
     * Store a staff reply, clear the session's "needs human" flag, and
     * remember the (question, answer) pair for the bot to reuse — three
     * things kept in one place so they can never drift apart: a reply
     * sent without the flag clearing would leave the conversation stuck
     * in the inbox forever; the flag clearing without a reply would
     * silently drop the customer; and remembering it separately risked
     * forgetting on one of the two call sites.
     *
     * @return int The new message's id.
     */
    public function add_staff_reply(string $session, int $admin_id, string $message): int
    {
        $session  = substr($session, 0, 40);
        $question = $this->last_customer_message($session);
        $id       = $this->add($session, $admin_id, 'staff', $message);

        $this->db->query(
            "INSERT INTO {chat_sessions} (session, needs_human, updated_at) VALUES (%s, 0, %s)
             ON DUPLICATE KEY UPDATE needs_human = 0, updated_at = VALUES(updated_at)",
            [$session, $this->now()]
        );

        if ('' !== $question) {
            $this->learn($question, $message);
        }

        return $id;
    }

    /**
     * Who is on the other end of a session: the logged-in customer (0 for
     * a guest) and the best mobile number known for them — the number
     * stamped on the session's rows (guest phone gate or billing phone at
     * the time), else the account's current billing phone.
     *
     * @return array{user_id: int, phone: string, number: int}
     */
    public function session_contact(string $session): array
    {
        $session = substr($session, 0, 40);

        $row = $this->db->get_row(
            "SELECT MAX(user_id) AS user_id,
                    MAX(CASE WHEN phone <> '' THEN phone END) AS phone
             FROM {chat_messages} WHERE session = %s",
            $session
        );

        $user_id = (int) ($row['user_id'] ?? 0);
        $phone   = (string) ($row['phone'] ?? '');

        if ('' === $phone && $user_id > 0) {
            $phone = (string) get_user_meta($user_id, 'billing_phone', true);
        }

        $number = (int) $this->db->get_var('SELECT id FROM {chat_sessions} WHERE session = %s', $session);

        return ['user_id' => $user_id, 'phone' => $phone, 'number' => $number];
    }

    /**
     * The customer's own most recent message in a session — the question
     * a staff reply is presumably answering.
     */
    private function last_customer_message(string $session): string
    {
        return (string) $this->db->get_var(
            "SELECT message FROM {chat_messages}
             WHERE session = %s AND role = 'user' ORDER BY id DESC LIMIT 1",
            $session
        );
    }

    /**
     * Remember a (question, answer) pair from a staff reply. A question
     * asked before (compared case/whitespace-insensitively) just gets its
     * answer refreshed rather than piling up duplicate rows for the same
     * thing every time staff answers it again.
     */
    public function learn(string $question, string $answer): void
    {
        $question = trim(mb_substr($question, 0, 500));
        $answer   = trim(mb_substr($answer, 0, 2000));

        if ('' === $question || '' === $answer) {
            return;
        }

        $existing = $this->db->get_var(
            'SELECT id FROM {chat_learned} WHERE LOWER(question) = %s LIMIT 1',
            mb_strtolower($question)
        );

        if (null !== $existing) {
            $this->db->update('chat_learned', [
                'answer'     => $answer,
                'updated_at' => $this->now(),
            ], ['id' => (int) $existing]);

            return;
        }

        $this->db->insert('chat_learned', [
            'question'   => $question,
            'answer'     => $answer,
            'hits'       => 0,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
    }

    /**
     * Every learned (question, answer) pair — matching against them
     * happens in PHP (VKL_Smart_Chatbot::learned_answer()), so this is
     * just the raw fetch.
     *
     * @return array<int, array<string, mixed>>
     */
    public function learned_questions(int $limit = 500): array
    {
        return $this->db->get_results(
            'SELECT id, question, answer FROM {chat_learned} ORDER BY id DESC LIMIT %d',
            $limit
        );
    }

    /**
     * Bump a learned answer's hit counter — called once the bot actually
     * used it, so the admin can see which learned answers are earning
     * their keep.
     */
    public function bump_learned_hit(int $id): void
    {
        $this->db->query('UPDATE {chat_learned} SET hits = hits + 1 WHERE id = %d', $id);
    }

    /**
     * Every learned answer, most used first (for the admin screen).
     *
     * @return array<int, array<string, mixed>>
     */
    public function all_learned(int $limit = 200): array
    {
        return $this->db->get_results(
            'SELECT id, question, answer, hits, updated_at FROM {chat_learned}
             ORDER BY hits DESC, updated_at DESC LIMIT %d',
            $limit
        );
    }

    /**
     * Forget one learned answer (admin correction).
     */
    public function forget_learned(int $id): void
    {
        $this->db->delete('chat_learned', ['id' => $id]);
    }

    /**
     * Sessions currently waiting on a human reply, most recently
     * escalated first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function needing_human(int $limit = 30): array
    {
        return $this->db->get_results(
            'SELECT id AS conversation_number, session, updated_at FROM {chat_sessions}
             WHERE needs_human = 1 ORDER BY updated_at DESC LIMIT %d',
            $limit
        );
    }

    /**
     * Conversations waiting on a human — the admin menu badge.
     */
    public function needing_human_count(): int
    {
        return (int) $this->db->get_var('SELECT COUNT(*) FROM {chat_sessions} WHERE needs_human = 1');
    }

    /**
     * Whether this session is currently waiting on a human reply — the
     * widget polls only while this is true.
     */
    public function needs_human(string $session): bool
    {
        return '1' === (string) $this->db->get_var(
            'SELECT needs_human FROM {chat_sessions} WHERE session = %s',
            $session
        );
    }

    /**
     * Has a staff member ever replied in this session? Once true, the
     * chatbot stops auto-answering the customer here — a human already
     * took the conversation over, and an automated reply barging back in
     * on the next message would undo that.
     */
    public function has_staff_reply(string $session): bool
    {
        return null !== $this->db->get_var(
            "SELECT id FROM {chat_messages} WHERE session = %s AND role = 'staff' LIMIT 1",
            $session
        );
    }

    /**
     * Highest message id of a session (0 when it has none yet) — the
     * starting cursor for a poll loop.
     */
    public function latest_message_id(string $session): int
    {
        return (int) $this->db->get_var(
            'SELECT MAX(id) FROM {chat_messages} WHERE session = %s',
            $session
        );
    }

    /**
     * Highest message id across every conversation — the admin live
     * inbox's auto-refresh cursor: "has anything at all arrived since I
     * loaded this page?"
     */
    public function latest_message_id_global(): int
    {
        return (int) $this->db->get_var('SELECT MAX(id) FROM {chat_messages}');
    }

    /**
     * Messages of a session strictly after a given id, oldest first — one
     * poll tick's worth of new messages, whichever side is polling.
     *
     * @return array<int, array<string, mixed>>
     */
    public function messages_since(string $session, int $after_id, int $limit = 50): array
    {
        return $this->db->get_results(
            'SELECT id, role, message, created_at FROM {chat_messages}
             WHERE session = %s AND id > %d ORDER BY id ASC LIMIT %d',
            [$session, $after_id, $limit]
        );
    }

    /**
     * Latest sessions with message count and last activity.
     *
     * @param int $limit Max sessions.
     *
     * @return object[]
     */
    public function recent_sessions(int $limit = 15): array
    {
        return $this->db->get_results(
            "SELECT m.session, MAX(m.user_id) AS user_id, COUNT(*) AS messages, MAX(m.created_at) AS last_at,
                    MAX(CASE WHEN m.phone <> '' THEN m.phone END) AS phone,
                    MAX(s.id) AS conversation_number
             FROM {chat_messages} m
             LEFT JOIN {chat_sessions} s ON s.session = m.session
             GROUP BY m.session
             ORDER BY last_at DESC
             LIMIT %d",
            [$limit]
        );
    }

    /**
     * Most recent non-empty topic of a session ('' when none) — the bot's
     * short-term memory of what this visitor is shopping for.
     */
    public function last_topic(string $session): string
    {
        return (string) $this->db->get_var(
            "SELECT topic FROM {chat_messages}
             WHERE session = %s AND topic <> '' AND role = 'user'
             ORDER BY id DESC LIMIT 1",
            $session
        );
    }

    /**
     * Distinct topics of a session (for the admin viewer).
     *
     * @return string[]
     */
    public function session_topics(string $session): array
    {
        $rows = $this->db->get_results(
            "SELECT DISTINCT topic FROM {chat_messages} WHERE session = %s AND topic <> ''",
            $session
        );

        return array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['topic'] ?? ''), $rows)));
    }

    /**
     * Messages of one session, oldest first.
     *
     * @param string $session Session token.
     * @param int    $limit   Max messages.
     *
     * @return object[]
     */
    public function messages(string $session, int $limit = 50): array
    {
        return $this->db->get_results(
            'SELECT role, message, created_at FROM {chat_messages}
             WHERE session = %s ORDER BY id ASC LIMIT %d',
            [$session, $limit]
        );
    }

    /**
     * The last $limit turns of a conversation, oldest first — context for
     * the AI connector (the newest message is not logged yet when it runs).
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent_turns(string $session, int $limit = 6): array
    {
        return $this->db->get_results(
            'SELECT role, message FROM (
                 SELECT id, role, message FROM {chat_messages} WHERE session = %s ORDER BY id DESC LIMIT %d
             ) t ORDER BY id ASC',
            substr($session, 0, 40),
            max(1, $limit)
        );
    }

    /**
     * Delete one conversation.
     */
    public function delete_session(string $session): int
    {
        $deleted = (int) $this->db->query('DELETE FROM {chat_messages} WHERE session = %s', $session);
        $this->db->query('DELETE FROM {chat_sessions} WHERE session = %s', $session);

        return $deleted;
    }

    /**
     * Delete every recorded conversation.
     */
    public function delete_all(): int
    {
        $deleted = (int) $this->db->query('DELETE FROM {chat_messages} WHERE 1 = %d', 1);
        $this->db->query('DELETE FROM {chat_sessions} WHERE 1 = %d', 1);

        return $deleted;
    }
}
