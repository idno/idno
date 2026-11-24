<?php

namespace IdnoPlugins\StaticPages\Pages {

    /**
     * Default class to serve the homepage
     */
    class View extends \Idno\Common\Page implements \Idno\Common\MarkdownPermalink
    {

        // Handle GET requests to the entity

        function getContent()
        {
            // The same address answers as HTML or as Markdown, by the Accept header
            $this->sendVaryAccept();
            $this->selectMarkdownIfWanted();

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

            // Ensure we're talking about pages ...
            if (!($object instanceof \IdnoPlugins\StaticPages\StaticPage)) {
                $this->goneContent();
            }

            // Check that we can see it
            if (!$object->canRead()) {
                $this->deniedContent();
            }

            // Forward if necessary
            if (!empty($object->forward_url) && !\Idno\Core\Idno::site()->session()->isAdmin()) {
                $this->forward($object->forward_url);
            }

            $this->setOwner($object->getOwner());
            $this->setPermalink(); // This is a permalink
            $this->setLastModifiedHeader($object->updated); // Say when this was last modified

            // The page as Markdown, for a request that asks for it
            if ($this->wantsMarkdown()) {
                $this->drawMarkdown($object);
            }

            $t = \Idno\Core\Idno::site()->template();
            $t->__(array(

                'title'       => $object->getTitle(),
                'body'        => $t->__(array('object' => $object))->draw('staticpages/page'),
                'description' => $object->getShortDescription()

            ))->drawPage();
        }

        // Get webmention content and handle it

        function webmentionContent($source, $target, $source_content, $source_mf2)
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
                $return = $object->addWebmentions($source, $target, $source_content, $source_mf2);
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
            if (empty($object)) $this->forward(); // TODO: 404
            if ($object->saveDataFromInput()) {
                $this->forward($object->getURL());
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
            if (empty($object)) $this->forward(); // TODO: 404
            if ($object->delete()) {
                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_('%s was deleted.', [$object->getTitle()]));
            }
            $this->forward($_SERVER['HTTP_REFERER']);
        }

    }

}

