<?php
namespace local_coursepilot\external;

/** Fixture: ein Werkzeug ruft ein anderes Werkzeug. */
class get_sections {
    public static function execute(): array {
        return get_skill::execute();
    }
}
