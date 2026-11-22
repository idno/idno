<?php
// Command line only: anywhere else, answer 404 with an empty body before doing anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Tests/rig/mail-container.sh runs this after the run of Tests/Mail/MailpitDeliveryTest, with the JUnit log
 * of the run: the test must have RUN, not been skipped (it skips without Mailpit's addresses). It prints how
 * many of its cases ran and were skipped, and exits 1 when fewer than three ran (plain, STARTTLS, implicit
 * TLS) or any was skipped.
 *
 *     php Tests/rig/mail-check.php <junit.xml>
 */
$log = $argv[1] ?? '';
$xml = is_file($log) ? @simplexml_load_file($log) : false;
if ($xml === false) {
    fwrite(STDERR, "Cannot read the JUnit log \"$log\".\n");
    exit(1);
}

$cases = $xml->xpath('//testcase[@class="Tests\\Mail\\MailpitDeliveryTest"]') ?: [];
$skipped = 0;
foreach ($cases as $case) {
    if (isset($case->skipped)) {
        ++$skipped;
    }
}
$ran = count($cases) - $skipped;
printf("MailpitDeliveryTest: %d ran, %d skipped\n", $ran, $skipped);
if ($ran < 3 || $skipped > 0) {
    fwrite(STDERR, "The mail delivery test must run, not skip: three connections, none skipped.\n");
    exit(1);
}
