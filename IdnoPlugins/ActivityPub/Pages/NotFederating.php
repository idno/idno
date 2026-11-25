<?php

namespace IdnoPlugins\ActivityPub\Pages;

/**
 * What every federation address answers on a private site: 404, to any method, for a member
 * that exists and one that does not alike, and whoever asks.
 *
 * 404 and not 403 or 401: the site has no actors, so there is nothing to be let in to, and
 * other servers ask again with a signature after a 401 or 403. Not 410 either, which tells
 * them to forget the member for good; the site may be made public again.
 *
 * Without this page the addresses would fall through to the site's other routes, which
 * answer a visitor to a private site 403 for some of them and 404 for others.
 */
class NotFederating extends \Idno\Common\Page
{

    private function refuse()
    {
        $this->setResponse(404);
        http_response_code(404);
        header('Content-Type: application/json');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            echo json_encode(['error' => 'Not found']);
        }
        exit;
    }

    function get($params = array())
    {
        $this->refuse();
    }

    function get_xhr()
    {
        $this->refuse();
    }

    function head()
    {
        $this->refuse();
    }

    function head_xhr()
    {
        $this->refuse();
    }

    // Page::post() would ask for a form token first, and answer 403 without one
    function post()
    {
        $this->refuse();
    }

    function post_xhr()
    {
        $this->refuse();
    }

    function put()
    {
        $this->refuse();
    }

    function put_xhr()
    {
        $this->refuse();
    }

    function delete()
    {
        $this->refuse();
    }

    function delete_xhr()
    {
        $this->refuse();
    }
}
