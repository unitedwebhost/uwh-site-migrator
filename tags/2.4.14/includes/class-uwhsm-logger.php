<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
* Lightweight protected activity log for Site Migrator.
* Stored as a non-autoloaded option to avoid creating another database table.
*/
final class UWHSM_Logger {
    const OPTION = 'uwhsm_activity_logs_v1';
    const MAX_ROWS = 500;
    const DEDUPE_OPTION = 'uwhsm_activity_log_dedupe_v1';
    const MAX_DEDUPE = 200;

    private static function allowed_categories() {
        return array('backup', 'restore', 'rollback', 'checkpoint', 'drive', 'migration', 'schedule', 'system');
    }

    private static function allowed_statuses() {
        return array('info', 'started', 'success', 'warning', 'failed', 'stopped');
    }

    private static function safe_context(array $context) {
        $allowed = array(
            'backup', 'job', 'restore_id', 'checkpoint', 'transfer_id', 'import_id', 'pull_id', 'source_domain',
            'delivery', 'frequency', 'size', 'bytes', 'count', 'progress', 'phase', 'error', 'message', 'deleted', 'kept', 'next_run'
        );
        $out = array();
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $context)) {
                continue;
            }
            $value = $context[$key];
            if (is_bool($value)) {
                $out[$key] = $value;
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $out[$key] = $value;
                continue;
            }
            if (is_scalar($value)) {
                $out[$key] = substr(sanitize_text_field((string) $value), 0, 500);
            }
        }
        return $out;
    }

    private static function rows() {
        $rows = get_option(self::OPTION, array());
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : array();
    }

    private static function save(array $rows) {
        if (count($rows) > self::MAX_ROWS) {
            $rows = array_slice($rows, 0, self::MAX_ROWS);
        }
        update_option(self::OPTION, array_values($rows), false);
    }

    public static function log($category, $status, $message, array $context = array()) {
        $category = sanitize_text_field((string) $category);
        $status = sanitize_text_field((string) $status);
        if (!in_array($category, self::allowed_categories(), true)) {
            $category = 'system';
        }
        if (!in_array($status, self::allowed_statuses(), true)) {
            $status = 'info';
        }
        $message = substr(sanitize_text_field((string) $message), 0, 500);
        if ($message === '') {
            return false;
        }
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $row = array(
            'id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('uwhsm_', true),
            'timestamp' => time(),
            'category' => $category,
            'status' => $status,
            'message' => $message,
            'user_id' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'user' => is_object($user) && isset($user->user_login) ? substr(sanitize_text_field((string) $user->user_login), 0, 80) : '',
            'context' => self::safe_context($context),
        );
        $rows = self::rows();
        array_unshift($rows, $row);
        self::save($rows);
        return $row['id'];
    }

    public static function log_once($fingerprint, $category, $status, $message, array $context = array(), $ttl = 86400) {
        $fingerprint = substr(preg_replace('/[^a-zA-Z0-9_.:-]/', '', (string) $fingerprint), 0, 160);
        if ($fingerprint === '') {
            return self::log($category, $status, $message, $context);
        }
        $key = hash('sha256', $fingerprint);
        $now = time();
        $ttl = max(60, (int) $ttl);
        $dedupe = get_option(self::DEDUPE_OPTION, array());
        if (!is_array($dedupe)) {
            $dedupe = array();
        }
        $last = (int) ($dedupe[$key] ?? 0);
        if ($last && $now - $last < $ttl) {
            return false;
        }
        $dedupe[$key] = $now;
        arsort($dedupe, SORT_NUMERIC);
        if (count($dedupe) > self::MAX_DEDUPE) {
            $dedupe = array_slice($dedupe, 0, self::MAX_DEDUPE, true);
        }
        update_option(self::DEDUPE_OPTION, $dedupe, false);
        return self::log($category, $status, $message, $context);
    }

    public static function recent(array $args = array()) {
        $args = wp_parse_args($args, array('category' => '', 'status' => '', 'search' => '', 'limit' => 100));
        $category = sanitize_text_field((string) $args['category']);
        $status = sanitize_text_field((string) $args['status']);
        $search = strtolower(trim(sanitize_text_field((string) $args['search'])));
        $limit = max(1, min(500, absint($args['limit'])));
        $out = array();
        foreach (self::rows() as $row) {
            if ($category !== '' && ($row['category'] ?? '') !== $category) {
                continue;
            }
            if ($status !== '' && ($row['status'] ?? '') !== $status) {
                continue;
            }
            if ($search !== '') {
                $haystack = strtolower((string) ($row['message'] ?? '') . ' ' . (string) ($row['user'] ?? '') . ' ' . wp_json_encode($row['context'] ?? array()));
                if (false === strpos($haystack, $search)) {
                    continue;
                }
            }
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    public static function summary() {
        $rows = self::rows();
        $summary = array('total' => count($rows), 'failed' => 0, 'warning' => 0, 'success' => 0, 'last_timestamp' => 0);
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
            $summary['last_timestamp'] = max($summary['last_timestamp'], (int) ($row['timestamp'] ?? 0));
        }
        return $summary;
    }

    public static function clear() {
        self::save(array());
        update_option(self::DEDUPE_OPTION, array(), false);
        return true;
    }
}
