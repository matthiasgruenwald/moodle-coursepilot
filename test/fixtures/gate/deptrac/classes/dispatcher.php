<?php
namespace local_coursepilot;

/** Fixture: Einstieg darf Werkzeuge nutzen. */
class dispatcher {
    public static function run(): array {
        return external\get_skill::execute();
    }
}
