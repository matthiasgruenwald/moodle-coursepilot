<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared defaults for {question_categories} records, mirroring Moodle core's
 * own convention (lib/questionlib.php, add_category()) instead of computing
 * a per-parent value. A self-computed MAX(sortorder)+1 produced degenerate
 * sortorder=1 values for first-child categories, which correlated with a
 * Moodle-core restore failure ("questioncategoryid cannot be null") — see
 * issue #315.
 */
final class question_category_defaults {
    /** @var int Default sortorder for newly created/moved question categories. */
    public const SORTORDER = 999;
}
