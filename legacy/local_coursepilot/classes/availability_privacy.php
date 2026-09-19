<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot;

defined('MOODLE_INTERNAL') || die();

/**
 * Sanitizes Moodle availability JSON for safe AI output.
 *
 * Pure static class – no Moodle DB dependencies, easy to unit-test.
 */
class availability_privacy {

    /**
     * Replaces personal data in Moodle availability JSON before sending to AI.
     *
     * Rules:
     * - Empty string → empty string.
     * - Unparseable JSON → empty string (cannot be assessed safely).
     * - Recursively processes nested "c" condition arrays.
     * - For type==="profile" conditions: replaces "v" with "***".
     *   "sf"/"cf" and "op" are kept so the AI knows a personal restriction exists.
     * - All other types and fields are unchanged.
     */
    public static function sanitize(string $availability): string {
        if ($availability === '') {
            return '';
        }
        $data = json_decode($availability, true);
        if ($data === null) {
            return '';
        }
        $data = self::sanitize_node($data);
        return json_encode($data);
    }

    private static function sanitize_node(array $node): array {
        if (!isset($node['c']) || !is_array($node['c'])) {
            return $node;
        }
        $node['c'] = array_map([self::class, 'sanitize_condition'], $node['c']);
        return $node;
    }

    private static function sanitize_condition(array $condition): array {
        // Recurse into nested operator nodes.
        if (isset($condition['c']) && is_array($condition['c'])) {
            return self::sanitize_node($condition);
        }
        if (($condition['type'] ?? '') === 'profile') {
            $condition['v'] = '***';
        }
        return $condition;
    }
}
