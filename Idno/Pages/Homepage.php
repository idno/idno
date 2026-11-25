<?php

    /**
     * Defines the site homepage
     */

namespace Idno\Pages {

    use Idno\Entities\User;
    use Idno\Http\Controller\Home\HomeStream;

    /**
     * Default class to serve the homepage
     */
    class Homepage extends \Idno\Common\Page
    {

        // Handle GET requests to the homepage

        function getContent()
        {

            $stream         = \Idno\Core\Idno::container()->get(HomeStream::class);
            $query          = $this->getInput('q');
            $offset         = (int)$this->getInput('offset');

            // Check for an empty site
            if (!$stream->siteHasUsers()) {
                $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'begin/');
            }

            // Set the homepage owner for single-user sites
            if (!$this->getOwner() && ($owner = $stream->singleUserOwner())) {
                $this->setOwner($owner);
            }

            // The friendly content-specific URL (if any) and the request's own types
            $resolved       = $stream->types($this->arguments[0] ?? null, $query, $this->getInput('types'));
            $friendly_types = $resolved['friendly'];

            $page   = $stream->page($resolved['types'], $query, $offset);
            $feed   = $page['items'];
            $count  = $page['count'];
            $create = $stream->creatableTypes();
            $titles = $stream->titles();

            // If we have a feed, set our last modified flag to the time of the latest returned entry
            if (!empty($feed)) {
                if (is_array($feed)) {
                    $feed = array_filter($feed);
                    $this->setLastModifiedHeader(reset($feed)->updated);
                }
            }

            $t = \Idno\Core\Idno::site()->template();
            $t->__(
                array(

                'title'       => $titles['title'],
                'description' => $titles['description'],
                'content'     => $friendly_types,
                'body'        => $t->__(
                    array(
                    'items'        => $feed,
                    'contentTypes' => $create,
                    'offset'       => $offset,
                    'count'        => $count,
                    'subject'      => $query,
                    'content'      => $friendly_types
                    )
                )->draw('pages/home'),

                )
            )->drawPage();
        }

        /**
         * A webmention to the homepage means someone mentioned our site's root.
         */
        function webmentionContent($source, $target, $source_response, $source_mf2)
        {
            // if this is a single-user site, let's forward on the root mention
            // to their user page

            \Idno\Core\Idno::site()->logging()->info("received homepage mention from $source");

            if (\Idno\Core\Idno::site()->config()->single_user) {
                $user = \Idno\Entities\User::getOne(['admin' => true]);
                if ($user) {
                    return \Idno\Core\Idno::container()->get(HomeStream::class)->profilePageWebmention($user, $source, $target, $source_response, $source_mf2);
                } else {
                    \Idno\Core\Idno::site()->logging()->debug("query for an admin-user failed to find one");
                }
            } else {
                \Idno\Core\Idno::site()->logging()->debug("disregarding mention to multi-user site");
            }

            return false;
        }

    }

}
