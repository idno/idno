<?php

namespace IdnoPlugins\Media {

    class Media extends \Idno\Common\Entity implements \Idno\Common\JSONLDSerialisable
    {

        function getTitle()
        {
            if (empty($this->title)) {
                return  \Idno\Core\Idno::site()->language()->_('Untitled');
            } else {
                return $this->title;
            }
        }

        function getDescription()
        {
            return $this->body;
        }

        /**
         * Media objects have type 'media'
         * @return 'media'
         */
        function getActivityStreamsObjectType()
        {
            return 'media';
        }

        /**
         * Saves changes to this object based on user input
         * @return bool
         */
        function saveDataFromInput()
        {

            if (empty($this->_id)) {
                $new = true;
            } else {
                $new = false;
            }

            if ($new) {
                if (!\Idno\Core\Idno::site()->events()->triggerEvent("file/upload", [], true)) {
                    return false;
                }
            }

            $this->title = \Idno\Core\Idno::site()->currentPage()->getInput('title');
            $this->body  = \Idno\Core\Idno::site()->template()->sanitize_html(\Idno\Core\Idno::site()->currentPage()->getInput('body'));
            $this->tags  = \Idno\Core\Idno::site()->currentPage()->getInput('tags');
            $access = \Idno\Core\Idno::site()->currentPage()->getInput('access');
            $this->setAccess($access);

            if ($time = \Idno\Core\Idno::site()->currentPage()->getInput('created')) {
                if ($time = strtotime($time)) {
                    $this->created = $time;
                }
            }

            // This flag will tell us if it's safe to save the object later on
            if ($new) {
                $ok = false;
            } else {
                $ok = true;
            }

            // Get media
            //if ($new) {
            if (!empty($_FILES['media']['tmp_name'])) {
                // This is awful, but unfortunately, browsers can't be trusted to send the right mimetype.
                $ext = pathinfo($_FILES['media']['name'], PATHINFO_EXTENSION);
                if (!empty($ext)) {
                    $ext = strtolower($ext);
                    if (in_array($ext,
                        [
                            'mp4',
                            'mov',
                            'webm',
                            'ogg',
                            'mpeg',
                            'mp3',
                            'm4a',
                            'wav',
                            'vorbis'
                        ]
                    )
                    ) {
                        $media_file = $_FILES['media'];
                        if ($media_file['type'] == 'application/octet-stream') {
                            switch ($ext) {
                                case 'mp4':
                                    $media_file['type'] = 'video/mp4';
                                    break;
                                case 'mov':
                                    $media_file['type'] = 'video/mov';
                                    break;
                                case 'webm':
                                    $media_file['type'] = 'video/webm';
                                    break;
                                case 'wav':
                                    $media_file['type'] = 'audio/wav';
                                    break;
                                case 'ogg':
                                    $media_file['type'] = 'audio/ogg';
                                    break;
                                case 'mp3':
                                    $media_file['type'] = 'audio/mpeg';
                                    break;
                                case 'm4a':
                                    $media_file['type'] = 'audio/x-m4a';
                                    break;
                                case 'mpeg':
                                    $media_file['type'] = 'video/mpeg';
                                    break;
                                case 'ogv':
                                    $media_file['type'] = 'audio/ogv';
                                    break;
                            }
                        }
                        $this->media_type = $media_file['type'];

                        // Replace any existing photos
                        $this->deleteAttachments();

                        if ($media = \Idno\Entities\File::createFromFile($media_file['tmp_name'], $media_file['name'], $media_file['type'], true)) {
                            $this->attachFile($media);
                            $ok = true;
                        } else {
                            \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_('Media wasn\'t attached.'));
                        }
                    } else {
                        \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->esc_('This doesn\'t seem to be a media file .. %s', [$_FILES['media']['type']]));
                    }
                } else {
                    \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_('We couldn\'t access your media. Please try again.'));

                    return false;
                }
            } else if ($new) {

                $errcode = null;
                if (!empty($_FILES['media']['error']))
                    $errcode = $_FILES['media']['error'];

                $errmsg = \Idno\Files\FileSystem::getUploadErrorCodeMessage($errcode);
                if (!empty($errcode) && !empty($errmsg)) {

                    // No file is ok, if this is not new
                    if (intval($errcode) == UPLOAD_ERR_NO_FILE && !$new) {
                        $errmsg = null;
                    }
                } else {
                    $errmsg = \Idno\Core\Idno::site()->language()->_('We couldn\'t access your media for an unknown reason. Please try again.');
                }
                if (!empty($errmsg)) {
                    \Idno\Core\Idno::site()->session()->addErrorMessage($errmsg);
                    return false;
                }
            }

            // If a media file wasn't attached, don't save the file.
            if (!$ok) {
                return false;
            }

            if ($this->publish($new)) {

                // Now we've saved it and got an ID, if it's a video, let's call out to enquue a transcode
                if (strpos($this->media_type, 'video') !== false) {
                    \Idno\Core\Idno::site()->queue()->enqueue('default', 'video/transcode', [
                        'uuid' => $this->getUUID()
                    ]);
                }

                if ($this->getAccess() == 'PUBLIC') {
                    \Idno\Core\Webmention::pingMentions($this->getURL(), \Idno\Core\Idno::site()->template()->parseURLs($this->getTitle() . ' ' . $this->getDescription()));
                }

                return true;
            } else {
                return false;
            }

        }

        public function jsonLDSerialise(array $params = array())
        {

            $json = [
                "@context" => "http://schema.org/",

                "name" => $this->getTitle(),
                "@id" => $this->getUUID(),
                "datePublished" => date('c', $this->getCreatedTime()),
                "description" => $this->body,

            //                "thumbnailURL" => "http://placehold.it/350x150",
            //                "thumbnail" => "http://placehold.it/350x150",

                "uploadDate" => date('c', $this->getCreatedTime()),
                'author' => [
                    "@type" => "Person",
                    "name" => $this->getOwner()->getName()
                ],
                'encodingFormat' => $this->media_type,

            ];

            if ($attachments = $this->getAttachments()) {
                $attachment = $attachments[0];

                $mainsrc = $attachment['url'];
                $mainsrc = preg_replace('/^(https?:\/\/\/)/', \Idno\Core\Idno::site()->config()->getDisplayURL(), $mainsrc);
                $mainsrc = \Idno\Core\Idno::site()->config()->sanitizeAttachmentURL($mainsrc);

                $json['contentUrl'] = $mainsrc;
            }

            if (substr($vars['object']->media_type, 0, 5) == 'video') {
                $json['@type'] = 'VideoObject';
            } else {
                $json['@type'] = 'AudioObject';
            }

            return $json;
        }

    }

}
