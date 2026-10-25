<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Small policy helper kept separate so rollback decisions stay predictable and testable. */
final class UWHSM_Rollback {
    public static function should_auto_rollback(array $state) {
        if (!empty($state['is_rollback'])) {
            return false;
        }
        if (empty($state['checkpoint_name']) || !is_string($state['checkpoint_name'])) {
            return false;
        }
        return in_array((string) ($state['phase'] ?? ''), array('files', 'database', 'finalize'), true);
    }
}
