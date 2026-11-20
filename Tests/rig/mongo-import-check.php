<?php
// Command line only: anywhere else, answer 404 with an empty body before doing anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Tests/rig/mongo-import-container.sh runs this after each run of Tests/Import, with the JUnit log of
 * the run: the end-to-end test of the MongoDB import must have RUN, not been skipped (it skips without a
 * MongoDB server, and on a failure to reach the target's database). It prints how many of its tests ran
 * and were skipped, and exits 1 when none ran or any was skipped.
 *
 *     php Tests/rig/mongo-import-check.php <junit.xml> <target name>
 */
$log = $argv[1] ?? '';
$target = $argv[2] ?? 'the target';
$xml = is_file($log) ? @simplexml_load_file($log) : false;
if ($xml === false) {
    fwrite(STDERR, "Cannot read the JUnit log \"$log\".\n");
    exit(1);
}

$cases = $xml->xpath('//testcase[@class="Tests\\Import\\MongoImportEndToEndTest"]') ?: [];
$skipped = 0;
foreach ($cases as $case) {
    if (isset($case->skipped)) {
        ++$skipped;
    }
}
$ran = count($cases) - $skipped;
printf("MongoImportEndToEndTest on %s: %d ran, %d skipped\n", $target, $ran, $skipped);
if ($ran === 0 || $skipped > 0) {
    fwrite(STDERR, "The end-to-end test of the import must run, not skip.\n");
    exit(1);
}
