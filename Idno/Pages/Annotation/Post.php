<?php

    /**
     * Post annotations
     */

namespace Idno\Pages\Annotation {

    use Idno\Common\Entity;

    /**
     * Default class to post annotations
     */
    class Post extends \Idno\Common\Page
    {

        // Handle GET requests to a comment
        function getContent()
        {
            $this->forward(\Idno\Http\RedirectTarget::sanitize((string) ($_SERVER['HTTP_REFERER'] ?? ''), \Idno\Core\Idno::site()->config()->getDisplayURL(), \Idno\Core\Idno::site()->config()->getURL())); // Send the user back to whence they came
        }

        // Handle POST requests: this is where the interesting stuff happens
        function postContent()
        {

            $this->createGatekeeper(); // User is logged in and can post content

            if ($this->xhr) {
                \Idno\Core\Idno::site()->template()->setTemplateType('json'); // Set template
            }

            // Get variables
            $body        = $this->getInput('body');
            $object_uuid = $this->getInput('object');
            $type        = $this->getInput('type');
            $user        = \Idno\Core\Idno::site()->session()->currentUser();
            if ($type != 'like') {
                $type = 'reply';
            }

            if ($object = Entity::getByUUID($object_uuid)) {

                $has_liked = false;
                if ($type == 'like') {
                    if ($like_annotations = $object->getAnnotations('like')) {
                        foreach (array_keys($like_annotations) as $like_key) {
                            // The member's own like is one they are the author of by the rule that lets an author delete an annotation (made on this site, by them):
                            // a like a webmention sent in their name is not theirs, and the toggle then adds their own. Exactly that annotation is removed, as an
                            // annotation-only save made once (an unlike is no change of the post, whoever owns it).
                            $authored = $object->authoredAnnotationKey((string) $like_key);
                            if ($authored !== null) {
                                if ($object->removeAnnotationByKey($authored)) {
                                    $object->saveAnnotations(true);
                                }
                                $has_liked = true;
                                break;
                            }
                        }
                    }
                }

                if (!$has_liked) {
                    // (addAnnotation() saves the annotation, marked as annotation-only: a second save here would be an unmarked update of the post)
                    $object->addAnnotation($type, $user->getTitle(), $user->getURL(), $user->getIcon(), $body, null, null, '', [], true, true);
                }

                if ($this->xhr) {

                    $likes = $object->countAnnotations('like');
                    if ($likes == 1) {
                        $heart_text = 'star';
                    } else {
                        $heart_text = 'stars';
                    }

                    \Idno\Core\Idno::site()->template()->__(
                        [
                        'number' => $likes,
                        'text' => "$likes $heart_text"
                        ]
                    )->drawPage();
                }

                $this->forward($object->getDisplayURL() . '#comments');
            }

            // Missing object, error
            $this->goneContent();
        }

    }

}

