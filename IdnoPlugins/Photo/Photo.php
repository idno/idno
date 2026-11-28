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
         * The files of one form field, one entry per file, whether the field was sent
         * as one file (name="photo") or as several (name="photo[]"); nothing when the
         * request carried no such field. Entries keep PHP's error code: a file that PHP
         * refused has an empty tmp_name and the reason in 'error'.
         *
         * @param  string $field
         * @return array[]
         */
        public static function uploadedFiles($field)
        {
            if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
                return [];
            }
            if (!array_key_exists('name', $_FILES[$field])) {
                // Already one entry per file (the Micropub endpoint fills $_FILES this way)
                return array_values(array_filter($_FILES[$field], 'is_array'));
            }
            $files = \Idno\Core\Input::getFiles($field);

            return isset($files['name']) ? [$files] : array_values((array) $files);
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
            $this->tags  = \Idno\Core\Tags::clean(\Idno\Core\Idno::site()->currentPage()->getInput('tags'));
            $access = \Idno\Core\Idno::site()->currentPage()->getInput('access');
            $this->setAccess($access);

            if ($time = \Idno\Core\Idno::site()->currentPage()->getInput('created')) {
                if ($time = strtotime($time)) {
                    $this->created = $time;
                }
            }

            // Look at every file before storing any, so that a post is never left with
            // some of its photos, and never saved without the photo its author chose
            $files = static::uploadedFiles('photo');
            $language = \Idno\Core\Idno::site()->language();
            $session = \Idno\Core\Idno::site()->session();
            $chosen = [];

            foreach ($files as $_file) {

                $errcode = isset($_file['error']) ? intval($_file['error']) : UPLOAD_ERR_OK;
                $name = isset($_file['name']) ? (string) $_file['name'] : '';

                if ($errcode === UPLOAD_ERR_NO_FILE || ($errcode === UPLOAD_ERR_OK && empty($_file['tmp_name']) && $name === '')) {
                    continue; // An empty slot of the form
                }

                // PHP refused the file (too large, cut short, nowhere to put it)
                if ($errcode !== UPLOAD_ERR_OK || empty($_file['tmp_name'])) {
                    $session->addErrorMessage(\Idno\Core\Upload::errorMessage($errcode ?: UPLOAD_ERR_CANT_WRITE, $name));
                    return false;
                }

                if (!\Idno\Core\Upload::isShowableImage($_file['tmp_name'], $name)) {
                    $session->addErrorMessage($language->esc_('"%s" is not an image this site can show, so nothing was saved. A photo can be %s.', [$name, \Idno\Core\Upload::typeList()]));
                    return false;
                }

                if (!\Idno\Entities\File::isFileFreeFromScriptTags($_file['tmp_name'], $name)) {
                    $session->addErrorMessage($language->_('This image seems to contain malicious code, so it can\'t be uploaded.'));
                    return false;
                }

                $chosen[] = $_file;
            }

            // A photo post is a photo: without one (newly chosen, or already attached) there is nothing to save
            if (empty($chosen) && empty($this->getAttachments())) {
                $session->addErrorMessage($language->_('A photo post needs a photo, and none arrived, so nothing was saved. Choose a photo and try again.'));
                return false;
            }

            foreach ($chosen as $_file) {

                // Extract exif data so we can rotate
                if (is_callable('exif_read_data') && $_file['type'] == 'image/jpeg') {
                    try {
                        if ($exif = @exif_read_data($_file['tmp_name'])) {
                            $this->exif = base64_encode(serialize($exif)); // Yes, this is rough, but exif contains binary data that cannot be saved in mongo
                        }
                    } catch (\Throwable $e) {
                        $exif = false;
                    }
                } else if (!is_callable('exif_read_data') && $session->isAdmin()) {
                    \Idno\Core\Idno::site()->logging()->info("Because your server doesn't provide EXIF support, Idno can't preserve any rotation information in this image.");
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
                                if (empty($this->$varname)) {
                                    $this->$varname = [];
                                }

                                $this->$varname[$filename] = [
                                    'id'    => substr($thumbnail, 0, strpos($thumbnail, '/')),
                                    'url'   => \Idno\Core\Idno::site()->config()->url . 'file/' . $thumbnail,
                                ];
                            }
                        }
                    }

                } else {
                    $session->addErrorMessage($language->esc_('This site could not store "%s", so nothing was saved. Please try again.', [$_file['name']]));
                    return false;
                }
            }

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

            // A photo post can be without its file (the file was removed, for example)
            if ($attachments = $this->getAttachments()) {
                $attachment = $attachments[0];

                $mainsrc = $attachment['url'];
                $mainsrc = preg_replace('/^(https?:\/\/\/)/', \Idno\Core\Idno::site()->config()->getDisplayURL(), $mainsrc);
                $mainsrc = \Idno\Core\Idno::site()->config()->sanitizeAttachmentURL($mainsrc);

                $json['image'] = $mainsrc;
            }

            return $json;
        }

    }

}
