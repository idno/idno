<?php

namespace IdnoPlugins\Photo {

    use Idno\Entities\File;

    class Photo extends \Idno\Common\Entity
        implements \Idno\Common\JSONLDSerialisable
    {

        function getTitle()
        {
            if (empty($this->title)) {
                return \Idno\Core\Idno::site()->language()->_('Untitled');
            } else {
                return $this->title;
            }
        }

        function getDescription()
        {
            return $this->body;
        }

        /**
         * Photo objects have type 'image'
         * @return 'image'
         */
        function getActivityStreamsObjectType()
        {
            return 'image';
        }

        function getMetadataForFeed()
        {
            return array('type' => 'photo');
        }

        /**
         * Retrieve icon
         * @return mixed|string
         */
        function getIcon()
        {
            $urls = [];
            if (!empty($this->thumbs_large)) {
                foreach ($this->thumbs_large as $filename => $data) {
                    $urls[] = preg_replace('/^(https?:\/\/\/)/', \Idno\Core\Idno::site()->config()->getDisplayURL(), $data['url']);
                }
            }
            if (!empty($urls)) {
                return $urls[0];
            }
            return parent::getIcon();
        }

        /**
         * Extend json serialisable to include some extra data
         */
        public function jsonSerialize(): mixed
        {
            $object = parent::jsonSerialize();

            // Add some thumbs
            $object['thumbnails'] = array();
            $sizes = \Idno\Core\Idno::site()->events()->dispatch('photo/thumbnail/getsizes', new \Idno\Core\Event([
                'sizes' => [
                    'large' => 800,
                    'medium' => 400,
                    'small' => 200,
                ]
            ]));
            $eventdata = $sizes->data();
            foreach ($eventdata['sizes'] as $label => $size) {
                $varname                      = "thumbs_{$label}";
                if (!empty($this->$varname) && is_array($this->$varname)) {
                    foreach ($this->$varname as $filename => $data) {
                        $object['thumbnails'][$label][$filename] = [
                            'url' => preg_replace('/^(https?:\/\/\/)/', \Idno\Core\Idno::site()->config()->url, $data['url'])
                        ];
                    }
                }
            }

            return $object;
        }


        /**
         * Include thumbnails, so they get the same access control as the photo itself
         *
         * @return array
         */
        public function getAttachedFileIDs()
        {
            $ids = parent::getAttachedFileIDs();
            foreach ((array) $this->getAttributes() as $key => $value) {
                if (strpos((string) $key, 'thumbs_') === 0 && is_array($value)) {
                    foreach ($value as $thumb) {
                        if (is_array($thumb) && !empty($thumb['id'])) {
                            $ids[] = (string) $thumb['id'];
                        }
                    }
                } else if (preg_match('/^thumbnail(_[a-z]+)?_id$/', (string) $key) && !empty($value) && is_scalar($value)) {
                    $ids[] = (string) $value;
                }
            }

            return array_values(array_unique($ids));
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

            // Get photo
            $files = \Idno\Core\Input::getFiles('photo');

            if (!isset($files['name'])) {
                $files = array_filter($files, function($var) {
                    return !empty($var['tmp_name']); // Filter non-filled in elements
                });
            } else {
                $files = [$files]; // Handle situations where we aren't given an array of photos
            }

            foreach ($files as $_file) {

                if (!empty($_file['tmp_name'])) {

                    if (\Idno\Entities\File::isImage($_file['tmp_name']) || \Idno\Entities\File::isSVG($_file['tmp_name'], $_file['name'])) {

                        if (!\Idno\Entities\File::isFileFreeFromScriptTags($_file['tmp_name'], $_file['name'])) {
                            \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_('Image seems to contain malicious code and cannot be uploaded.'));
                            return false;
                        }

                        // Extract exif data so we can rotate
                        if (is_callable('exif_read_data') && $_file['type'] == 'image/jpeg') {
                            try {
                                if (function_exists('exif_read_data')) {
                                    if ($exif = exif_read_data($_file['tmp_name'])) {
                                        $this->exif = base64_encode(serialize($exif)); // Yes, this is rough, but exif contains binary data that cannot be saved in mongo
                                    }
                                }
                            } catch (\Exception $e) {
                                $exif = false;
                            }
                        } else {
                            $exif = false;

                            if (!is_callable('exif_read_data')) {
                                // Admins get a no-EXIF error
                                if (\Idno\Core\Idno::site()->session()->isAdmin()) {
                                    \Idno\Core\Idno::site()->logging()->info("Because your server doesn't provide EXIF support, Idno can't preserve any rotation information in this image.");
                                }
                            }
                        }

                        if ($photo = \Idno\Entities\File::createFromFile($_file['tmp_name'], $_file['name'], $_file['type'], true, true)) {
                            $this->attachFile($photo, true);

                            // Now get some smaller thumbnails, with the option to override sizes
                            $sizes = \Idno\Core\Idno::site()->events()->dispatch('photo/thumbnail/getsizes', new \Idno\Core\Event(array('sizes' => array('large' => 800, 'medium' => 400, 'small' => 200))));
                            $eventdata = $sizes->data();
                            foreach ($eventdata['sizes'] as $label => $size) {

                                $filename = $_file['name'];

                                if ($_file['type'] != 'image/gif') {
                                    if ($thumbnail = \Idno\Entities\File::createThumbnailFromFile($_file['tmp_name'], "{$filename}_{$label}", $size, false)) {
                                        // New style thumbnails
                                        $varname        =   "thumbs_{$label}";
                                        if (empty($this->$varname))
                                        $this->$varname = [];

                                        $this->$varname[$filename] = [
                                        'id'    => substr($thumbnail, 0, strpos($thumbnail, '/')),
                                        'url'   => \Idno\Core\Idno::site()->config()->url . 'file/' . $thumbnail,
                                        ];
                                    }
                                }
                            }

                        } else {
                            \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_('Image wasn\'t attached.'));
                            return false;
                        }
                    } else {
                        \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_('This doesn\'t seem to be an image...'));
                        return false;
                    }

                } else {

                    $errcode = null;
                    if (!empty($_file['error']))
                        $errcode = $_file['error'];

                    $errmsg = \Idno\Files\FileSystem::getUploadErrorCodeMessage($errcode);
                    if (!empty($errcode) && !empty($errmsg)) {

                        // No file is ok, if this is not new
                        if (intval($errcode) == UPLOAD_ERR_NO_FILE && !$new) {
                            $errmsg = null;
                        }
                    } else {
                        $errmsg = \Idno\Core\Idno::site()->language()->_('We couldn\'t access your image for an unknown reason. Please try again.');
                    }
                    if (!empty($errmsg)) {
                        \Idno\Core\Idno::site()->session()->addErrorMessage($errmsg);
                        return false;
                    }
                }
            }
            //}

            $publish_status = \Idno\Core\Idno::site()->currentPage()->getInput('publish_status', 'published');

            if ($publish_status === 'draft') {
                return $this->saveAsDraft();
            }

            $this->setPublishStatus('published');
            if ($this->publish($new)) {

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
                "@context" => "http://schema.org",
                "@type" => 'Photograph',
                'dateCreated' => date('c', $this->getCreatedTime()),
                'datePublished' => date('c', $this->getCreatedTime()),
                'author' => [
                    "@type" => "Person",
                    "name" => $this->getOwner()->getName()
                ],
                'name' => $this->getTitle(),
                'description' => $this->body,
                'url' => $this->getUrl(),
                'mainEntityOfPage' => $this->getUrl(),
            ];

            $attachments = $this->getAttachments();
            $attachment = $attachments[0];

            $mainsrc = $attachment['url'];
            $mainsrc = preg_replace('/^(https?:\/\/\/)/', \Idno\Core\Idno::site()->config()->getDisplayURL(), $mainsrc);
            $mainsrc = \Idno\Core\Idno::site()->config()->sanitizeAttachmentURL($mainsrc);

            $json['image'] = $mainsrc;

            return $json;
        }

    }

}
