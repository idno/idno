<?php

/**
 * Subprocess helper for Tests\Core\ForwardScriptRequestTest: starts Idno and serves a page whose
 * handler refuses with a redirect and then goes on, as a POST made by a script
 * (XMLHttpRequest) or by a form.
 *
 *     php forward_script_request.php <script|form> <exit|noexit>
 *
 * The handler prints BEFORE, calls forward() (with $exit true or false), and prints AFTER. The
 * last line of output is "STATUS <code>", written when the process ends.
 */

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

$kind = isset($argv[1]) ? $argv[1] : 'script';
$exit = !isset($argv[2]) || $argv[2] !== 'noexit';

// Nothing is written before the handler has set its headers
ob_start();

include dirname(__DIR__, 2) . '/_bootstrap.php';

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'POST';

register_shutdown_function(function () {
    echo "\nSTATUS " . http_response_code() . "\n";
});

class ForwardScriptRequestFixturePage extends \Idno\Common\Page
{

    public $exit = true;

    function postContent()
    {
        echo "BEFORE\n";
        $this->setResponse(403);
        $this->forward('', $this->exit);
        echo "AFTER\n";
    }
}

// A request that needs no form token, so that the handler is reached
\Idno\Core\Idno::site()->session()->setIsAPIRequest(false);
$_REQUEST['__bTs'] = $_POST['__bTs'] = time();
$_REQUEST['__bTa'] = $_POST['__bTa'] = '/';
$_REQUEST['__bTk'] = $_POST['__bTk'] = \Idno\Core\Bonita\Forms::token('/', $_REQUEST['__bTs']);

$page = new ForwardScriptRequestFixturePage();
$page->exit = $exit;
if ($kind == 'script') {
    $page->post_xhr();
} else {
    $page->post();
}
echo "RETURNED\n";
