<?php

namespace IdnoPlugins\IndiePub\Pages\MicroPub {

    use Idno\Common\ContentType;
    use Idno\Entities\User;
    use IdnoPlugins\IndiePub\Pages\IndieAuth\Token;

    use DOMDocument;
    use DOMXPath;

    class Endpoint extends \Idno\Common\Page
    {

        /**
         * Fetch syndication endpoints from an Idno hub.
         *
         * @param array $account_strings flat list of syndication
         *   IDs
         * @param array $account_data list of complex account data
         *   conforming to
         *   http://micropub.net/draft/#syndication-targets
         */
        private function getServiceAccountsFromHub(&$account_strings, &$account_data)
        {
            if (\Idno\Core\Idno::site()->hub()) {
                $result = \Idno\Core\Idno::site()->hub()->makeCall('hub/user/syndication', [
                    'content_type' => 'note',
                ]);

                if (!empty($result['content'])) {
                    $content = $result['content'];

                    // parse value from the inputs with name="syndication[]".
                    // TODO consider serving JSON in addition to HTML from hub?
                    $doc = new DOMDocument();
                    $doc->loadHTML($content);
                    $toggles = (new DOMXPath($doc))->query('//*[@name="syndication[]"]');

                    foreach ($toggles as $toggle) {
                        $uid  = $toggle->getAttribute('value');

                        $account = strip_tags($toggle->getAttribute('data-on'));
                        $service = ucwords(explode('::', $uid, 2)[0]);

                        $name =  "$account on $service";
                        $name = trim(preg_replace('/\s+/u', ' ', $name));

                        $account_strings[] = $uid;
                        $account_data[]    = ['uid' => $uid, 'name' => $name];
                    }
                }
            }
        }

        function get($params = array())
        {
            $this->gatekeeper();
            if ($query = trim($this->getInput('q'))) {
                $account_strings = \Idno\Core\Idno::site()->syndication()->getServiceAccountStrings();
                $account_data    = \Idno\Core\Idno::site()->syndication()->getServiceAccountData();
                $this->getServiceAccountsFromHub($account_strings, $account_data);

                switch ($query) {
                    case 'config':
                        header('Content-Type: application/json');
                        echo json_encode([
                            'media-endpoint' => \Idno\Core\Idno::site()->config()->url . 'micropub/endpoint',
                            'syndicate-to' => $account_data,
                        ], JSON_PRETTY_PRINT);
                        break;
                    case 'syndicate-to':
                        if ($this->isAcceptedContentType('application/json')) {
                            header('Content-Type: application/json');
                            echo json_encode([
                                'syndicate-to' => $account_data,
                            ], JSON_PRETTY_PRINT);
                        } else {
                            echo http_build_query([
                                "syndicate-to" => $account_strings,
                            ]);
                        }
                        break;
                }
            }
        }

        function post()
        {
            \Idno\Core\Idno::site()->template()->setTemplateType('json');

            \Idno\Core\Idno::site()->logging()->debug("MicroPub endpoint pinged", $_REQUEST);
            if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] == 'application/json') {
                \Idno\Core\Idno::site()->logging()->debug("JSON Payload", json_decode(file_get_contents('php://input'), true));
            }

            //fail-by-default in case of unhandled errors
            $this->setResponse(500);

            $this->gatekeeper();
            // If we're here, we're authorized

            \Idno\Core\Idno::site()->events()->triggerEvent('indiepub/post/start', ['page' => $this]);

            // are we handling a media endpoint request?
            $action = $this->getInput('action');
            if (empty($action)) {
                if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') == 0 && !empty($_FILES['file'])) {
                    $this->postMedia();
                    return;
                }
            }

            $action = $this->getInput('action', 'create');
            switch ($action) {
                case 'create':
                    $this->postCreate();
                    break;
                case 'delete':
                    $this->postDelete();
                    break;
                default:
                    $this->error(501, 'not_implemented', 'Action not implemented');
            }
        }

        function postMedia()
        {
            $id = \Idno\Entities\File::createFromFile($_FILES['file']['tmp_name'], $_FILES['file']['name'], $_FILES['file']['type']);

            if (!empty($id)) {
                $local_photo = \Idno\Core\Idno::site()->config()->url . 'file/' . $id;
                //\Idno\Core\Idno::site()->events()->triggerEvent('indiepub/post/success', ['page' => $this, 'object' => $entity]); // MP: Removing here, since the object hasn't actually been created yet, and this would trigger this multiple times
                $this->setResponse(201);
                header('Location: ' . $local_photo);

                /* return body with the URL to the photo, based upon Accept header */
                if ($this->isAcceptedContentType('application/json')) {
                    echo json_encode(['url' => $local_photo], JSON_PRETTY_PRINT);
                } else {
                    echo $local_photo;
                }
            } else {
                $this->error(400, 'cannot_save_media', 'Problem saving media');
            }
        }

        function postCreate()
        {
            // If the request is sent with a JSON content type parse the JSON input instead of form input
            if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] == 'application/json') {
                $input = file_get_contents('php://input');
                $this->jsoninput = json_decode($input, true);
                $type = !empty($this->jsoninput['type'][0]) ? $this->jsoninput['type'][0] : 'h-entry';
                $type = str_replace('h-', '', $type);

                $content     = $this->getJSONInput('content', $this->getJSONInput('description'));
                $name        = $this->getJSONInput('name', $this->getJSONInput('title'));
                $in_reply_to = $this->getJSONInput('in-reply-to');
                $syndicate   = $this->getJSONInput('mp-syndicate-to', $this->getJSONInput('syndicate-to'));
                $posse_links = $this->getJSONInput('syndication');
                $bookmark_of = $this->getJSONInput('bookmark-of');
                $like_of     = $this->getJSONInput('like-of');
                $repost_of   = $this->getJSONInput('repost-of');
                $categories  = $this->getJSONInput('category', $this->getJSONInput('tags'));
                $rsvp        = $this->getJSONInput('rsvp');
                $mp_type     = null;
                $photo_url   = $this->getJSONInput('photo');
                $video_url   = $this->getJSONInput('video');
                $audio_url   = $this->getJSONInput('audio');
                $visibility  = $this->getJSONInput('visibility');

                // Handle visibility
                if (is_array($visibility) && array_key_exists(0, $visibility)) {
                    $visibility = $visibility[0];
                }

                // Since Idno does support multiple photos
                /*
                if (is_array($photo_url) && array_key_exists(0, $photo_url)) {
                    $photo_url = $photo_url[0];
                } elseif (is_array($photo_url) && array_key_exists('value', $photo_url)) {
                    // TODO: save the image alt text somewhere and render it in the photo
                    // $alt_text = $photo_url['alt'];
                    $photo_url = $photo_url['value'];
                }
                */

                // Since Idno does not support multiple videos, use the first if more than one was given.
                if (is_array($video_url) && array_key_exists(0, $video_url)) {
                    $video_url = $video_url[0];
                }

                if (is_array($audio_url) && array_key_exists(0, $audio_url)) {
                    $audio_url = $audio_url[0];
                }

                // If no content was specified, use the summary to provide a reasonable fallback behavior.
                if (empty($content)) {
                    $content = $this->getJSONInput('summary');
                }

                if ($created = $this->getJSONInput('published')) {
                    $this->setInput('created', $created);
                }

                // Handle JSON checkins
                if ($checkin = $this->getJSONInput('checkin')) {
                    $type = 'checkin';
                    $place_name = $checkin['properties']['name'][0];
                    $fields = array('street-address', 'locality', 'region', 'country-name', 'postal-code');
                    $parts = array();
                    foreach ($fields as $f) {
                        if (!empty($checkin['properties'][$f])) {
                            $parts[] = $checkin['properties'][$f][0];
                        }
                    }
                    $user_address = implode(', ', $parts);
                    $lat = !empty($checkin['properties']['latitude']) ? $checkin['properties']['latitude'][0] : null;
                    $long = !empty($checkin['properties']['longitude']) ? $checkin['properties']['longitude'][0] : null;
                }

                if (!empty($photo_url)) {
                    if ($this->uploadFromUrl('photo', $photo_url)) {
                        $id = \Idno\Entities\File::createFromFile($_FILES['photo']['tmp_name'], $_FILES['photo']['name'], $_FILES['photo']['type']);
                        $local_photo = \Idno\Core\Idno::site()->config()->url . 'file/' . $id;
                        //$htmlPhoto = '<p><img style="display: block; margin-left: auto; margin-right: auto;" src="' . $local_photo . '" alt="' . $place_name . '"  /></p>';
                    }
                }

            } else {
                // Get details
                $type        = $this->getInput('h', 'entry');
                if ($type == 'annotation') {
                    return $this->postCreateAnnotation();
                }

                $content     = $this->getInput('content', $this->getInput('description'));
                $name        = $this->getInput('name', $this->getInput('title'));
                $in_reply_to = $this->getInput('in-reply-to');
                $syndicate   = $this->getInput('mp-syndicate-to', $this->getInput('syndicate-to'));
                $posse_links = $this->getInput('syndication');
                $bookmark_of = $this->getInput('bookmark-of');
                $like_of     = $this->getInput('like-of');
                $repost_of   = $this->getInput('repost-of');
                $categories  = $this->getInput('category', $this->getInput('tags'));
                $rsvp        = $this->getInput('rsvp');
                $mp_type     = $this->getInput('mp-type');
                $photo_url   = $this->getInput('photo');
                $video_url   = $this->getInput('video');
                $audio_url   = $this->getInput('audio');
                $visibility   = $this->getInput('visibility');
            }

            if (!empty($mp_type)) {
                $type = $mp_type;
            }
            if (is_string($categories)) {
                $categories = (array) $categories;
            }
            if (is_string($posse_links)) {
                $posse_links = (array) $posse_links;
            }

            if ($type == 'entry') {
                $type = 'note';

                if (!empty($_FILES['photo'])) {
                    $type = 'photo';
                } else if ($photo_url) {
                    $type      = 'photo';
                    $success   = $this->uploadFromUrl('photo', $photo_url);
                    if (!$success) {
                        \Idno\Core\Idno::site()->events()->triggerEvent('indiepub/post/failure', ['page' => $this]);
                        $this->error(
                            400, 'invalid_request',
                            "Failed uploading photo from $photo_url"
                        );
                    }
                }

                if (!empty($_FILES['video'])) {
                    $type = 'video';
                    // alias the file because IdnoPlugins/Media/Media.php expects "media"
                    $_FILES['media'] = $_FILES['video'];
                } else if ($video_url) {
                    $type      = 'video';
                    $success   = $this->uploadFromUrl('video', $video_url);
                    // alias the file because IdnoPlugins/Media/Media.php expects "media"
                    $_FILES['media'] = $_FILES['video'];
                    if (!$success) {
                        \Idno\Core\Idno::site()->events()->triggerEvent('indiepub/post/failure', ['page' => $this]);
                        $this->setResponse(500);
                        echo "Failed uploading video from $video_url";
                        exit;
                    }
                }

                if (!empty($_FILES['audio'])) {
                    $type = 'audio';
                    // alias the file because IdnoPlugins/Media/Media.php expects "media"
                        $_FILES['media'] = $_FILES['audio'];
                } else if ($audio_url) {
                    $type      = 'audio';
                    $success   = $this->uploadFromUrl('audio', $audio_url);
                    // alias the file because IdnoPlugins/Media/Media.php expects "media"
                    $_FILES['media'] = $_FILES['audio'];
                    if (!$success) {
                        \Idno\Core\Idno::site()->events()->triggerEvent('indiepub/post/failure', ['page' => $this]);
                        $this->setResponse(500);
                        echo "Failed uploading audio from $audio_url";
                        exit;
                    }
                }

                if ($type == 'note' && !empty($name)) {
                    $type = 'article';
                }
            }
            if ($type == 'checkin' && !$this->jsoninput) {
                // This is legacy for form-encoded requests. Likely the only server sending this request is OwnYourCheckin.
                $place_name = $this->getInput('place_name');
                $location = $this->getInput('location');
                $photo = $this->getInput('photo');
                $latlong = explode(",", $location);
                $lat = str_ireplace("geo:", "", $latlong[0]);
                $long = $latlong[1];
                $q = \IdnoPlugins\Checkin\Checkin::queryLatLong($lat, $long);
                $user_address = $q['display_name'];

            }

            if (!empty($_FILES['photo'])) {
                $id = \Idno\Entities\File::createFromFile($_FILES['photo']['tmp_name'], $_FILES['photo']['name'], $_FILES['photo']['type']);
                $photo = \Idno\Core\Idno::site()->config()->url . 'file/' . $id;

                if (!empty($photo)) {
                    //$htmlPhoto = '<p><img style="display: block; margin-left: auto; margin-right: auto;" src="' . $photo . '" alt="' . $place_name . '"  /></p>';
                }
            }

            if (($type == 'photo' || $type == 'video' || $type == 'audio') && empty($name) && !empty($content)) {
                $name    = $content;
                $content = '';
            }
            if (!empty($bookmark_of)) {
                $type = 'bookmark';
            }
            if (!empty($like_of)) {
                $type = 'like';
            }
            if (!empty($repost_of)) {
                $type = 'repost';
            }
            if (!empty($rsvp)) {
                $type = 'rsvp';
            }

            // setting all categories as hashtags into content field
            if (is_array($categories)) {
                //$hashtags = "";
                foreach ($categories as $key => $category) {
                    if (is_string($category)) { // in JSON requests, category may be an h-card, e.g. person tags
                        $category = trim($category);
                        if ($category) {
                            if (str_word_count($category) > 1) {
                                $category = str_replace("'", " ", $category);
                                $category = ucwords($category);
                                $category = str_replace(" ", "", $category);
                            }
                            //$hashtags .= " #$category";
                            $categories[$key] = " #$category";
                        }
                    }
                }
                $title_words = explode(" ", $name);
                $name = "";
                foreach ($title_words as $word) {
                    if (substr($word, 0, 1) !== "#") {
                        $name .= "$word ";
                    }
                }
            }

            // Get an appropriate plugin, given the content type
            if ($contentType = ContentType::getRegisteredForIndieWebPostType($type)) {
                if ($entity = $contentType->createEntity()) {
                    if (is_array($content)) {
                        $content_value = '';
                        if (!empty($content['html'])) {
                            $content_value = $content['html'];
                        } else if (!empty($content['value'])) {
                            $content_value = htmlspecialchars($content['value']);
                        }
                    } else {
                        $content_value = htmlspecialchars($content);
                    }
                    if (is_array($posse_links) && count($posse_links) > 0) {
                        foreach ($posse_links as $posse_link) {
                            if (!empty($posse_link)) {
                                $posse_service = preg_replace('/^(www\.|m\.)?(.+?)(\.com|\.org|\.net)?$/', '$2', parse_url($posse_link, PHP_URL_HOST));
                                $entity->setPosseLink($posse_service, $posse_link, '', '');
                            }
                        }
                    }
                    //$hashtags = (empty($hashtags) ? "" : "<p>".$hashtags."</p>");
                    $htmlPhoto    = (empty($htmlPhoto) ? "" : "<p>".$htmlPhoto."</p>");
                    $this->setInput('title', $name);
                    $this->setInput('body', $htmlPhoto.$content_value);
                    $this->setInput('inreplyto', $in_reply_to);
                    $this->setInput('bookmark-of', $bookmark_of);
                    $this->setInput('like-of', $like_of);
                    $this->setInput('repost-of', $repost_of);
                    $this->setInput('rsvp', $rsvp);
                    if ($visibility == 'private') {
                        $currentUser = \Idno\Core\Idno::site()->session()->currentUserUUID();
                        $this->setInput('access', $currentUser);
                    } else {
                        $this->setInput('access', 'PUBLIC');
                    }
                    $this->setInput('tags', $categories);
                    if ($type ==  'checkin') {
                        $this->setInput('lat', $lat);
                        $this->setInput('long', $long);
                        $this->setInput('user_address', $user_address);
                        $this->setInput('placename', $place_name);
                    }
                    if ($type == 'bookmark') {
                        $this->setInput('description', $content_value);
                    }
                    if ($created = $this->getInput('published')) {
                        $this->setInput('created', $created);
                    }
                    if (!empty($syndicate)) {
                        if (is_array($syndicate)) {
                            $syndication = $syndicate;
                        } else {
                            $syndication = array(trim(str_replace('.com', '', $syndicate)));
                        }
                        \Idno\Core\Idno::site()->logging()->info("Setting syndication: $syndication");
                        $this->setInput('syndication', $syndication);
                    }
                    if ($entity->saveDataFromInput()) {
                        \Idno\Core\Idno::site()->events()->triggerEvent('indiepub/post/success', ['page' => $this, 'object' => $entity]);
                        $this->setResponse(201);
                        header('Location: ' . $entity->getURL());
                        exit;
                    } else {
                        \Idno\Core\Idno::site()->events()->triggerEvent('indiepub/post/failure', ['page' => $this]);
                        $this->error(
                            400, 'invalid_request',
                            "Couldn't create {$type}"
                        );
                    }

                }

            } else {
                \Idno\Core\Idno::site()->events()->triggerEvent('indiepub/post/failure', ['page' => $this]);
                $this->error(
                    400, 'invalid_request',
                    "Couldn't find content type {$type}"
                );
            }
        }

        /**
         * Add a "like" or a "reply" to a post
         */
        function postCreateAnnotation()
        {
            $url       = $this->getInput('url');
            $content   = $this->getInput('content');
            $type      = $this->getInput('type');
            $username  = $this->getInput('username');
            $userurl   = $this->getInput('userurl');
            $userphoto = $this->getInput('userphoto');
            $rsvp      = $this->getInput('rsvp');

            $verbs = ['like','reply','rsvp'];

            $notEmpty = array('url', 'type', 'username', 'userurl', 'userphoto');
            foreach ($notEmpty as $varName) {
                if ($$varName == '') {
                    $this->error(
                        400, 'invalid_request',
                        '"' . $varName . '" must not be empty'
                    );
                }
            }
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $this->error(400, 'invalid_request', 'URL is invalid');
            }

            $bits = explode('/', $url);
            $slug = end($bits);
            $entity = \Idno\Common\Entity::getBySlug($slug);
            if ($entity === false) {
                $this->error(400, 'not_found', 'Post not found');
            }

            if (!in_array($type, $verbs)) {
                $this->error(
                    400,
                    'invalid_request',
                'Annotation type must be one of: ' . implode(', ', $verbs));
            }
            if ($type === 'reply' && $content == '') {
                $this->error(
                    400, 'invalid_request', '"content" must not be empty'
                );
            }
            if (!filter_var($userurl, FILTER_VALIDATE_URL)) {
                $this->error(400, 'invalid_request', '"userurl" is invalid');
            }
            if (!filter_var($userphoto, FILTER_VALIDATE_URL)) {
                $this->error(400, 'invalid_request', '"userphoto" is invalid');
            }

            $fields = [];
            if ($type == 'rsvp' && !empty($rsvp)) {
                $fields['rsvp'] = $rsvp;
            }

            $ok = $entity->addAnnotation(
                $type, $username, $userurl, $userphoto, $content, null, null, '', $fields
            );
            if (!$ok) {
                $this->error(
                    500, 'internal_error',
                    'Saving annotation failed'
                );
            }
            $entity->save();
            $this->setResponse(204);
            exit();
        }

        function postDelete()
        {
            $url = $this->getInput('url');
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                \Idno\Core\Idno::site()->events()->triggerEvent('indiepub/post/failure', ['page' => $this]);
                $this->error(400, 'invalid_request', 'URL is invalid');
            }

            $entity = \Idno\Common\Entity::getByUUID($url);
            if ($entity === false) {
                \Idno\Core\Idno::site()->events()->triggerEvent('indiepub/post/failure', ['page' => $this]);
                $this->error(400, 'not_found');
            }

            $owner       = $entity->attributes['owner'];
            $currentUser = \Idno\Core\Idno::site()->session()->currentUserUUID();
            if ($owner !== $currentUser) {
                \Idno\Core\Idno::site()->events()->triggerEvent('indiepub/post/failure', ['page' => $this, 'object' => $entity]);
                $this->error(403, 'forbidden');
            }

            $entity->delete();
            $this->setResponse(204);
            \Idno\Core\Idno::site()->events()->triggerEvent('indiepub/post/success', ['page' => $this, 'object' => $entity]);
            exit();
        }

        /**
         * Reply with a micropub error response and exit.
         *
         * @param int    $statusCode  HTTP status code
         * @param string $error       Micropub error code
         * @param string $description Human-readable error description
         *
         * @return void
         */
        protected function error($statusCode, $error, $description)
        {
            $site = \Idno\Core\Idno::site();
            $msgs = $site->session()->getMessages();
            foreach ($msgs as $msg) {
                if ($msg['message_type'] == 'alert-danger') {
                    $description .= ': ' . $msg['message'];
                }
            }

            $this->setResponse($statusCode);
            $tplVars = array(
                'error'             => $error,
                'error_description' => $description,
            );
            $site->template()->setTemplateType('json');
            $site->template()->__($tplVars)->drawPage();
            exit();
        }

        /**
         * Override normal "forbidden" page
         */
        function deniedContent($title = '')
        {
            $this->error(403, 'forbidden', $title);
        }

        /**
         * Micropub optionally allows uploading files from a
         * URL. This method downloads the file at a URL to a
         * temporary location and puts it in the php $_FILES
         * array.
         *
         * @param string $type "photo", "audio" or "video"
         * @param string $url  File URL
         */
        private function uploadFromUrl($type, $url)
        {
            $_url = $url;
            foreach ($_url as $url) {
                // SSRF protection: validate URL before fetching
                if (!\Idno\Core\UrlValidator::isSafeUrl($url)) {
                    \Idno\Core\Idno::site()->logging()->warning("Micropub: Blocked unsafe URL in file upload: {$url}");
                    continue;
                }
                $pathinfo = pathinfo(parse_url($url, PHP_URL_PATH));
                switch ($pathinfo['extension']) {
                    case 'jpg':
                    case 'jpeg':
                        $mimetype = 'image/jpeg';
                        break;
                    case 'png':
                        $mimetype = 'image/png';
                        break;
                    case 'gif':
                        $mimetype = 'image/gif';
                        break;

                    case 'mp4':
                        $mimetype = 'video/mp4';
                        break;
                    case 'ogv':
                        $mimetype = 'video/ogg';
                        break;

                    case 'mp3':
                        $mimetype = 'audio/mpeg';
                        break;
                    case 'oga':
                    case 'ogg':
                        $mimetype = 'audio/ogg';
                        break;
                    case 'wav':
                        $mimetype = 'audio/x-wav';
                        break;
                }

                $tmpname  = tempnam(sys_get_temp_dir(), 'indiepub_');
                $fp       = fopen($url, 'rb');
                if ($fp) {
                    $success = file_put_contents($tmpname, $fp);
                    fclose($fp);
                } else {
                    $success = false;
                }
                if ($success) {

                    // If we haven't got a valid mime, try using magicbyte detection
                    if (empty($mimetype)) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mimetype = finfo_file($finfo, $tmpname);
                        finfo_close($finfo);
                    }

                    $_FILES[$type][] = [
                        'tmp_name' => $tmpname,
                        'name'     => $pathinfo['basename'],
                        'size'     => filesize($tmpname),
                        'type'     => $mimetype,
                    ];
                }
            }
            return $success;
        }

        private function getJSONInput($name, $default = null)
        {
            if (empty($this->jsoninput))
                return null;

            if (!empty($this->jsoninput['properties'][$name])) {
                $val = $this->jsoninput['properties'][$name];
                // Return single value so that it matches form-encoded behavior
                if (is_array($val) && array_key_exists(0, $val) && count($val) == 1) {
                    return $val[0];
                } else {
                    return $val;
                }
            }

            return $default;
        }

    }
}
