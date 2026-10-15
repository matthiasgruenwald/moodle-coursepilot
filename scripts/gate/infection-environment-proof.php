<?php
// Tooling filesystem fixture only: no Moodle bootstrap, qtype loading or database access.
$root = sys_get_temp_dir() . '/coursepilot-source-probe-' . bin2hex(random_bytes(8));
$write = static function ($path, $content = "<?php\n") use ($root): void {
    if (!is_dir(dirname($root . '/' . $path))) mkdir(dirname($root . '/' . $path), 0700, true);
    file_put_contents($root . '/' . $path, $content);
};
try {
    foreach (['lib/classes/xml_parser.php', 'lib/db/install.xml', 'lib/setup.php', 'lib/filelib.php',
        'lib/dml/moodle_database.php', 'lib/dml/mysqli_native_moodle_database.php', 'lib/dml/pgsql_native_moodle_database.php',
        'question/engine/bank.php', 'question/format.php', 'question/format/xml/format.php',
        'question/type/questiontypebase.php', 'question/type/first/version.php',
        'question/type/first/questiontype.php'] as $file) $write($file);
    $probe = static fn() => json_decode(shell_exec('php /var/www/reports/infection-environment.php ' . escapeshellarg($root)), true, 512, JSON_THROW_ON_ERROR);
    $before = $probe();
    $write('question/type/second/questiontype.php');
    $after = $probe();
    if (!isset($after['sources']['question/type/second/questiontype.php']) || $before['qtypeSourceHash'] === $after['qtypeSourceHash']) {
        throw new RuntimeException('A newly discoverable qtype source without version.php must invalidate the source condition');
    }
    echo "Tooling source-discovery fixture: additional implementation invalidates digest\n";
    foreach (['lib/classes/xml_parser.php', 'lib/db/install.xml', 'lib/setup.php', 'lib/filelib.php',
        'lib/dml/moodle_database.php', 'lib/dml/mysqli_native_moodle_database.php', 'lib/dml/pgsql_native_moodle_database.php'] as $support) {
        $write($support, "changed filesystem fixture\n");
        $changed = $probe();
        if ($after['qtypeSourceHash'] === $changed['qtypeSourceHash']) {
            throw new RuntimeException('Changed native parser/schema/number/file dependency must invalidate the source condition');
        }
        $after = $changed;
    }
    echo "Tooling source-discovery fixture: parser, schema, numeric setup, DML and file dependencies invalidate digest\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($root);
}
