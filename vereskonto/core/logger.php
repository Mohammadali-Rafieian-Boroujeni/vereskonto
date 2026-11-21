<?php
/**
 * Logger.
 *
 * Level-based logging. Debug output only when WP_DEBUG is enabled;
 * warnings and errors are always written to the PHP error log.
 * Domain-specific persistent logs (e.g. SMS delivery) live in
 * dedicated tables owned by their repositories — not here.
 *
 * @package Vereskonto\Core
 * @since   1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Logger
 */
final class VKL_Logger
{
    public const DEBUG   = 'debug';
    public const INFO    = 'info';
    public const WARNING = 'warning';
    public const ERROR   = 'error';

    /**
     * Log a debug message (only written when WP_DEBUG is true).
     *
     * @param string               $message Message.
     * @param array<string, mixed> $context Context.
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    /**
     * Log an informational message.
     *
     * @param string               $message Message.
     * @param array<string, mixed> $context Context.
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    /**
     * Log a warning.
     *
     * @param string               $message Message.
     * @param array<string, mixed> $context Context.
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    /**
     * Log an error.
     *
     * @param string               $message Message.
     * @param array<string, mixed> $context Context.
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * Write a log line.
     *
     * @param string               $level   One of the level constants.
     * @param string               $message Message.
     * @param array<string, mixed> $context Context (JSON-encoded).
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $is_debug = defined('WP_DEBUG') && WP_DEBUG;

        if (in_array($level, [self::DEBUG, self::INFO], true) && !$is_debug) {
            return;
        }

        $line = sprintf(
            '[VKL][%s] %s%s',
            strtoupper($level),
            $message,
            $context ? ' ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE) : ''
        );

        error_log($line);

        /**
         * Fires for every platform log entry. Allows external log sinks
         * (files, monitoring services) without changing core code.
         *
         * @param string $level   Log level.
         * @param string $message Message.
         * @param array  $context Context.
         */
        do_action('vkl_log', $level, $message, $context);
    }
}
