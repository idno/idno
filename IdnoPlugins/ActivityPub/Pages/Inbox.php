<?php

namespace IdnoPlugins\ActivityPub\Pages;

use IdnoPlugins\ActivityPub\ActivityHandler;
use IdnoPlugins\ActivityPub\HTTPSignature;

/**
 * Per-user ActivityPub inbox endpoint.
 * Route: /actor/{handle}/inbox
 */
class Inbox extends \Idno\Common\Page
{

    function getContent()
    {
        $handle = $this->arguments[0] ?? '';
        $user = \Idno\Entities\User::getByHandle($handle);

        if (!$user) {
            $this->noContent();
            return;
        }

        // A banned member has no actor: other servers are told it is gone
        if ($user->isBanned()) {
            $this->goneContent();
            return;
        }

        $actorId = $user->getActivityPubActorID();
        $inboxUrl = $actorId . '/inbox';

        // Per spec, inbox MUST be an OrderedCollection.
        // We return an empty one since received activities are not publicly exposed.
        $collection = [
            '@context'     => 'https://www.w3.org/ns/activitystreams',
            'id'           => $inboxUrl,
            'type'         => 'OrderedCollection',
            'totalItems'   => 0,
            'orderedItems' => [],
        ];

        header('Content-Type: application/activity+json');
        echo json_encode($collection, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Override the framework's post() to bypass CSRF, parseJSONPayload(),
     * template detection, and other framework machinery that interferes
     * with federation inbox handling.
     *
     * The framework's default post() calls parseJSONPayload() which
     * consumes php://input before postContent() can read it, and runs
     * CSRF token validation that can fail for unsigned federation requests.
     */
    function post()
    {
        // Capture route arguments (handle) from Toro regex matches
        $arguments = func_get_args();
        if (!empty($arguments)) {
            $this->arguments = $arguments;
        }

        try {
            $this->handleInboxPost();
        } catch (\Throwable $e) {
            \Idno\Core\Idno::site()->logging()->error(
                'ActivityPub Inbox: Uncaught error: ' . $e->getMessage() .
                ' in ' . $e->getFile() . ':' . $e->getLine()
            );
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Internal server error']);
            exit;
        }
    }

    /**
     * Handle the inbox POST request directly.
     */
    private function handleInboxPost()
    {
        $handle = $this->arguments[0] ?? '';

        // Read php://input exactly once — the framework's parseJSONPayload()
        // is bypassed so this is the only read.
        $rawBody = file_get_contents('php://input');
        $headers = HTTPSignature::getRequestHeaders();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'POST';
        $path = $_SERVER['REQUEST_URI'] ?? '/';

        \Idno\Core\Idno::site()->logging()->info(
            'ActivityPub Inbox: POST for handle=' . $handle .
            ' body_length=' . strlen($rawBody) .
            ' from=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') .
            ' content_type=' . ($headers['content-type'] ?? 'none')
        );

        $result = ActivityHandler::handle($rawBody, $headers, $method, $path, $handle);

        \Idno\Core\Idno::site()->logging()->info(
            'ActivityPub Inbox: Response status=' . $result['status'] . ' for handle=' . $handle
        );

        http_response_code($result['status']);
        header('Content-Type: application/json');
        echo $result['body'];
        exit;
    }

    function postContent()
    {
        // Not used — post() is overridden to bypass the framework.
    }
}
