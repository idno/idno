<?php

    /**
     * User profile
     */

namespace Idno\Pages\User {

    use Idno\Core\Idno;
    use Idno\Core\Webmention;
    use Idno\Entities\User;

    /**
     * Default class to serve the homepage
     */
    class View extends \Idno\Common\Page
    {

        // Handle GET requests to the entity
        function getContent()
        {

            if (!empty($this->arguments[0])) {
                $user = \Idno\Entities\User::getByHandle($this->arguments[0]);
            }
            if (empty($user)) {
                $this->noContent();
            }

            // Users own their own profiles
            $this->setOwner($user);
            $this->setPermalink(true, $user); // This is a permalink

            if (!empty($this->arguments[1])) { // If we're on the friendly content-specific URL
                if ($friendly_types = explode('/', $this->arguments[1])) {
                    $friendly_types = array_filter($friendly_types);

                    $types = array();

                    // Run through the URL parameters and set content types appropriately
                    foreach ($friendly_types as $friendly_type) {
                        if ($friendly_type == 'all') {
                            $types = \Idno\Common\ContentType::getRegisteredClasses();
                            break;
                        }
                        if ($content_type_class = \Idno\Common\ContentType::categoryTitleToClass($friendly_type)) {
                            $types[] = $content_type_class;
                        }
                    }
                }
            } else {
                $types  = ['IdnoPlugins\Status\Status', 'IdnoPlugins\Text\Entry'];
            }
            $offset = (int)$this->getInput('offset');
            $count  = \Idno\Common\Entity::countFromX($types, array('owner' => $user->getUUID(), 'publish_status' => 'published'));
            $feed   = \Idno\Common\Entity::getFromX($types, array('owner' => $user->getUUID(), 'publish_status' => 'published'), array(), \Idno\Core\Idno::site()->config()->items_per_page, $offset);

            $last_modified = $user->updated;
            if (!empty($feed) && is_array($feed)) {
                if ($feed[0]->updated > $last_modified) {
                    $last_modified = $feed[0]->updated;
                }
            }
            $this->setLastModifiedHeader($last_modified);

            $t = \Idno\Core\Idno::site()->template();
            $t->__(
                array(

                'title'       => $user->getTitle(),
                'body'        => $t->__(array('user' => $user, 'items' => $feed, 'count' => $count, 'offset' => $offset))->draw('entity/User/profile'),
                'description' => \Idno\Core\Idno::site()->language()->_('The %s profile for %s', [\Idno\Core\Idno::site()->config()->title, $user->getTitle()])

                )
            )->drawPage();
        }

        // Handle POST requests to the entity

        function postContent()
        {
            if (!empty($this->arguments[0])) {
                $user = \Idno\Entities\User::getByHandle($this->arguments[0]);
            }
            if (empty($user)) {
                $this->forward(); // TODO: 404
            }
            if ($user->saveDataFromInput()) {
                $this->forward($user->getURL());
            }
            $this->forward($_SERVER['HTTP_REFERER']);
        }

        // Handle DELETE requests to the entity

        function deleteContent()
        {
            if (!empty($this->arguments[0])) {
                $object = \Idno\Common\Entity::getByID($this->arguments[0]);
            }
            if (empty($object)) {
                $this->forward(); // TODO: 404
            }
            if ($object->delete()) {
                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_('%s was deleted.', [$object->getTitle()]));
            }
            $this->forward($_SERVER['HTTP_REFERER']);
        }

        /**
         * A webmention to our profile page means someone mentioned us.
         */
        function webmentionContent($source, $target, $source_response, $source_mf2)
        {
            Idno::site()->logging()->info("received user mention from $source to $target");
            if (empty($this->arguments)) {
                Idno::site()->logging()->debug("could not process user mention, no pagehandler arguments");

                return false;
            }

            $user = User::getByHandle($this->arguments[0]);
            if (empty($user)) {
                Idno::site()->logging()->debug('could not process user mention, no user for handle ' . $this->arguments[0]);

                return false;
            }

            Idno::site()->logging()->debug("found target user {$user->getHandle()}");

            // if this is anything other than a normal mention (e.g. a delete), accept the wm, but do nothing
            if ($source_response['response'] !== 200) {
                return true;
            }

            $title = Webmention::getTitleFromContent($source_response['content'], $source);

            $mention = [
                'permalink' => $source,
                'title'     => $title,
            ];

            // look for the first and only h-entry or h-event on the page
            $entry = Webmention::findRepresentativeHEntry($source_mf2, $source, ['h-entry', 'h-event']);
            $card  = Webmention::findAuthorHCard($source_mf2, $source, $entry);

            // try to get some more specific details of the mention from mf2 content
            if ($entry) {
                if (!empty($entry['properties']['url'])) {
                    $mention['permalink'] = $entry['properties']['url'][0];
                }
                if (!empty($entry['properties']['content'])) {
                    $content            = $entry['properties']['content'][0];
                    $mention['content'] = Idno::site()->template()->sanitize_html(is_array($content) ? $content['html'] : $content);
                }
            }

            $sender_url = false;
            if ($card) {
                if (!empty($card['properties']['url'])) {
                    $sender_url           = $card['properties']['url'][0];
                    $mention['owner_url'] = $card['properties']['url'][0];
                }
                if (!empty($card['properties']['name'])) {
                    $mention['owner_name'] = $card['properties']['name'][0];
                }
            }

            return true;
        }

    }

}
