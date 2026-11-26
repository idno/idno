<?php

    /**
     * Generic, backup viewer for entities
     */

namespace Idno\Pages\Entity {

    /**
     * Default class to serve the homepage
     */
    class View extends \Idno\Common\Page
    {

        // Handle GET requests to the entity

        function getContent()
        {
            if (!empty($this->arguments[0])) {
                $object = \Idno\Common\Entity::getByID($this->arguments[0]);
                if (empty($object)) {
                    $object = \Idno\Common\Entity::getBySlug($this->arguments[0]);
                }
            }
            if (empty($object)) {
                $this->goneContent();
            }

            // From here, we know the object is set

            // Check that we can see it
            if (!$object->canRead()) {
                $this->deniedContent();
            }

            // Just forward to the user's page
            if ($object instanceof \Idno\Entities\User) {
                $this->forward($object->getDisplayURL());
            }

            $this->setOwner($object->getOwner());
            $this->setPermalink(true, $object); // This is a permalink

            $this->lastModifiedGatekeeper($object->updated); // 304 if we've not updated the object

            // We need to set pragma and expires headers
            //header("Pragma: private");
            //                header("Cache-Control: no-store, no-cache, must-revalidate"); // HTTP/1.1
            //                header("Cache-Control: post-check=0, pre-check=0", false);
            //                header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
            //                header("Pragma: no-cache"); // HTTP/1.0
            //                header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT"); // Last modified right now

            //header('Expires: ' . date(\DateTime::RFC1123, time() + (86400 * 30))); // Cache for 30 days!
            $this->setLastModifiedHeader($object->updated); // Say when this was last modified
            //                if ($cache = \Idno\Core\Idno::site()->cache()) {
            //                    $cache->store("{$this->arguments[0]}_modified_ts", $object->updated);
            //                }

            $t = \Idno\Core\Idno::site()->template();

            $description = $object->getShortDescription();
            if (empty($description)) {
                $description = $t->sampleText($object->getDescription());
            }

            $t->__(
                array(

                'title'       => $object->getTitle(),
                'body'        => $t->__(['object' => $object])->draw('entity/wrapper'),
                'description' => $description

                )
            )->drawPage();
        }

        // Get webmention content and handle it

        function webmentionContent($source, $target, $source_response, $source_mf2)
        {
            if (!empty($this->arguments[0])) {
                $object = \Idno\Common\Entity::getByID($this->arguments[0]);
                if (empty($object)) {
                    $object = \Idno\Common\Entity::getBySlug($this->arguments[0]);
                }
            }
            if (empty($object)) {
                \Idno\Core\Idno::site()->logging()->error("No object was found with ID {$this->arguments[0]}.");

                return false;
            }

            $return = true;

            if ($object instanceof \Idno\Common\Entity && $source != $target && $source != $object->getObjectURL()) {
                $return = $object->addWebmentions($source, $target, $source_response, $source_mf2);
            }

            return $return;
        }

        // Handle POST requests to the entity

        function postContent()
        {
            if (!empty($this->arguments[0])) {
                $object = \Idno\Common\Entity::getByID($this->arguments[0]);
                if (empty($object)) {
                    $object = \Idno\Common\Entity::getBySlug($this->arguments[0]);
                }
            }

            if (empty($object)) {
                $this->forward(); // TODO: 404
            }
            // Who may change the post is asked here, before anything of the request is kept:
            // saveDataFromInput() may store or replace files before save() asks
            $this->gatekeeper();
            if (!$object->canEdit()) {
                $this->deniedContent();
            }
            if ($object->saveDataFromInput()) {
                $this->forward($object->getDisplayURL());
            }
            $this->forward($_SERVER['HTTP_REFERER']);
        }

        // Handle DELETE requests to the entity

        function deleteContent()
        {
            if (!empty($this->arguments[0])) {
                $object = \Idno\Common\Entity::getByID($this->arguments[0]);
                if (empty($object)) {
                    $object = \Idno\Common\Entity::getBySlug($this->arguments[0]);
                }
            }
            // Who may delete is asked here: Entity::delete() does not ask
            $this->gatekeeper();
            // What the requester may not read is answered as something missing is
            if (empty($object) || !$object->canRead()) {
                $this->forward(); // TODO: 404
            }
            if (!$object->canEdit()) {
                $this->deniedContent();
            }
            if ($object->delete()) {
                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_('%s was deleted.', [$object->getTitle()]));
            }
            $this->forward(\Idno\Pages\Session\Login::sanitizeRedirectUrl($_SERVER['HTTP_REFERER'] ?? ''));
        }

    }

}

