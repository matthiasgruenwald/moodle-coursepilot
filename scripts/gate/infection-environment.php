<?php
/** Capture the exact installed question-type sources without loading Moodle or its database. */
$root = $argv[1];
$support = ['lib/classes/xml_parser.php', 'lib/db/install.xml', 'lib/setup.php', 'lib/filelib.php',
    'lib/dml/moodle_database.php', 'lib/dml/mysqli_native_moodle_database.php', 'lib/dml/pgsql_native_moodle_database.php',
    'question/engine/bank.php', 'question/format.php', 'question/format/xml/format.php',
    'question/type/questiontypebase.php'];
$sources = [];
foreach ($support as $relative) {
    $file = $root . '/' . $relative;
    if (!is_file($file)) {
        throw new RuntimeException('Moodle question type support source is missing: ' . $relative);
    }
    $digest = hash_file('sha256', $file);
    if ($digest === false) {
        throw new RuntimeException('Moodle question type support source could not be hashed: ' . $relative);
    }
    $sources[$relative] = $digest;
}
$implementations = glob($root . '/question/type/*/questiontype.php');
if ($implementations === false || $implementations === []) {
    throw new RuntimeException('Moodle question type source discovery failed');
}
$qtypes = [];
foreach ($implementations as $implementation) {
    $directory = dirname($implementation);
    $qtypes[] = basename($directory);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if (!$file->isFile() || $file->getExtension() !== 'php' || in_array('tests', explode('/', $relative), true)) {
            continue;
        }
        $digest = hash_file('sha256', $file->getPathname());
        if ($digest === false) {
            throw new RuntimeException('Question type source could not be hashed: ' . $relative);
        }
        $sources[$relative] = $digest;
    }
}
ksort($sources, SORT_STRING);
$hash = hash('sha256', json_encode($sources, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo json_encode(['qtypeSourceHash' => $hash, 'qtypes' => $qtypes, 'sources' => $sources],
    JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
