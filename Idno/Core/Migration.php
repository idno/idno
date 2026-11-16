<?php

namespace Idno\Core {

    use Idno\Common\Entity;
    use Idno\Entities\File;
    use Idno\Entities\User;

    class Migration extends \Idno\Common\Component
    {

        /**
         * Prepares an archive containing all of this site's data.
         *
         * @return string
         */
        static function exportToFolder($dir = false)
        {

            set_time_limit(0);  // Switch off the time limit for PHP

            $page = Idno::site()->currentPage();
            if (!empty($page)) {
                Idno::site()->currentPage()->setPermalink(true);
            }

            // Prepare a unique name for the archive
            $name = md5(time() . rand(0, 9999) . Idno::site()->config()->getURL());

            // If $folder is false or doesn't exist, use the temporary directory and ensure it has a slash on the end of it
            if (empty($dir)) {
                $dir = Idno::site()->config()->getTempDir();
            }

            $dir = rtrim($dir, '/\ ') . DIRECTORY_SEPARATOR;

            // Make the temporary directory, or fail out
            if (!@mkdir($dir . $name, 0777, true)) {
                \Idno\Core\Idno::site()->logging()->debug("Could not make temporary directory {$dir}{$name}");
                return false;
            }
            $json_path = $dir . $name . DIRECTORY_SEPARATOR . 'json' . DIRECTORY_SEPARATOR;
            if (!@mkdir($json_path)) {
                \Idno\Core\Idno::site()->logging()->debug("Could not make {$json_path}");
                return false;
            }
            $html_path = $dir . $name . DIRECTORY_SEPARATOR . 'html' . DIRECTORY_SEPARATOR;
            if (!@mkdir($html_path)) {
                \Idno\Core\Idno::site()->logging()->debug("Could not make {$html_path}");
                return false;
            }
            $file_path = $dir . $name . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR;
            if (!@mkdir($file_path)) {
                \Idno\Core\Idno::site()->logging()->debug("Could not make {$file_path}");
                return false;
            }

            if (!@mkdir($file_path . 'readable', 0777, true)) {
                \Idno\Core\Idno::site()->logging()->debug("Could not make {$file_path}readable");
                return false;
            }
            if (!@mkdir($file_path . 'uploads', 0777, true)) {
                \Idno\Core\Idno::site()->logging()->debug("Could not make {$file_path}uploads");
                return false;
            }

            // If we've made it here, we've created a temporary directory with the hash name

            // Write some details about the export
            file_put_contents(
                $dir . $name . DIRECTORY_SEPARATOR . 'known.json', json_encode(
                    [
                    'url' => Idno::site()->config()->getURL(),
                    'title' => Idno::site()->config()->getTitle(),
                    // Include some version info in case we change the export format
                    'version' => \Idno\Core\Version::version(),
                    'build' => \Idno\Core\Version::build(),
                    ], JSON_PRETTY_PRINT
                )
            );

            // We now also want to output the current loaded config
            file_put_contents($dir . $name . DIRECTORY_SEPARATOR . 'config.json', json_encode(\Idno\Core\Idno::site()->config(), JSON_PRETTY_PRINT));

            // Let's export everything.
            $fields = array();
            $query_parameters = array();
            $collection = 'entities';

            $limit = 10;
            $offset = 0;

            \Idno\Core\Idno::site()->logging()->debug("Exporting entities...");

            $f = fopen($dir . $name . DIRECTORY_SEPARATOR . 'entities.json', 'wb');
            fwrite($f, '[');

            while ($results = Idno::site()->db()->getRecords($fields, $query_parameters, $limit, $offset, $collection)) {
                foreach ($results as $id => $row) {

                    $object = Idno::site()->db()->rowToEntity($row);
                    if (!empty($object->_id) && $object instanceof Entity) {
                        $object_name = $object->_id;
                        $attachments = $object->attachments;
                        if (empty($attachments)) {
                            $attachments = [];
                        }
                        foreach (['thumbnail', 'thumbnail_large', 'thumbs_large'] as $thumbnail) {
                            if (!empty($object->$thumbnail)) {
                                if (preg_match('/file\/([a-zA-Z0-9]+)\//', $object->$thumbnail, $matches)) {
                                    $attachments[] = [
                                        'url' => $object->$thumbnail,
                                        '_id' => $matches[1]
                                    ];
                                }
                            }
                        }
                        if (!empty($attachments)) {
                            foreach ($attachments as $key => $attachment) {
                                if ($data = File::getFileDataFromAttachment($attachment)) {
                                    $filename = "" . $attachment['_id'];
                                    $id = "" . $attachment['_id']; // Ensure MongoIDs are cast to string (see #978)
                                    if ($ext = pathinfo($attachment['url'], PATHINFO_EXTENSION)) {
                                        $filename .= '.' . $ext;
                                    }
                                    if (!empty($attachment['mime-type'])) {
                                        $mime_type = $attachment['mime-type'];
                                    } else {
                                        $mime_type = 'application/octet-stream';
                                    }
                                    file_put_contents($file_path . 'readable/' . $filename, $data);
                                    $attachments[$key]['url'] = '../files/' . Idno::site()->config()->pathHost() . '/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3] . '/' . $id . '.file'; //$filename;
                                    $data_file = $file_path . 'uploads/' . \Idno\Core\Idno::site()->config()->pathHost() . '/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3] . '/' . $id . '.data';
                                    foreach (array($file_path . 'uploads/' . \Idno\Core\Idno::site()->config()->pathHost(), $file_path . \Idno\Core\Idno::site()->config()->pathHost() . '/' . $id[0], $file_path . 'uploads/' . \Idno\Core\Idno::site()->config()->pathHost() . '/' . $id[0] . '/' . $id[1], $file_path . 'uploads/' . \Idno\Core\Idno::site()->config()->pathHost() . '/' . $id[0] . '/' . $id[1] . '/' . $id[2], $file_path . 'uploads/' . \Idno\Core\Idno::site()->config()->pathHost() . '/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3]) as $up_path) {
                                        if (!is_dir($up_path)) {
                                            $result = mkdir($up_path, 0777, true);
                                        }
                                    }
                                    file_put_contents($file_path . 'uploads/' . Idno::site()->config()->pathHost() . '/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3] . '/' . $id . '.file', $data);
                                    file_put_contents($data_file, json_encode(['filename' => $filename, 'mime_type' => $mime_type]), JSON_PRETTY_PRINT);
                                }
                            }
                            $object->attachments = $attachments;
                        }
                        $json_object = json_encode($object, JSON_PRETTY_PRINT);
                        file_put_contents($json_path . $object_name . '.json', $json_object);

                        fwrite($f, $json_object . ',');

                        if (is_callable(array($object, 'draw'))) {
                            try {
                                $html = trim($object->draw());
                                if (!empty($html)) {
                                    file_put_contents($html_path . $object_name . '.html', $html);
                                }
                            } catch (\Error $e) { // Sometimes calling draw will break since some of the expected context is unavailable at this point. Don't let this kill the whole export.
                                \Idno\Core\Idno::site()->logging()->debug($e->getMessage());
                            }
                        }
                        //unset($results[$id]);
                        //unset($object);
                        gc_collect_cycles();    // Clean memory
                    }
                }

                $results = null;
                $offset += $limit;
            }

            fwrite($f, '{}]'); // Fudge to allow json decode
            fclose($f);

            \Idno\Core\Idno::site()->logging()->debug("Generating export records...");
            $db = \Idno\Core\Idno::site()->db();
            if ($db instanceof \Idno\Legacy\RepositoryDataConcierge) {
                $export_ext = 'sql';
            } else {
                $export_ext = 'json';
            }

            $limit = 10;
            $offset = 0;
            $f = fopen($dir . $name . DIRECTORY_SEPARATOR . 'exported_data.' . $export_ext, 'wb');

            if ($export_ext == 'json') {
                fwrite($f, '[');
            }

            while ($exported_records = \Idno\Core\Idno::site()->db()->exportRecords('entities', $limit, $offset)) {

                if ($export_ext == 'json') {
                    fwrite($f, trim($exported_records, '[],') . ',');
                } else {
                    fwrite($f, $exported_records);
                }

                $offset += $limit;
            }
            if ($export_ext == 'json') {
                fwrite($f, '{}]'); // Fudge to allow json decode
            }
            fclose($f);

            // As we're successful, return the unique name of the archive
            \Idno\Core\Idno::site()->logging()->debug("Archive constructed at {$dir}{$name}");
            return $dir . $name;
        }

        /**
         * Given the path to an Idno export, creates a complete .tar.gz file and returns the path to that.
         * If $save_path is false, will save to the temporary folder.
         *
         * @param  $path
         * @param  $save_path
         * @return string
         */
        static function archiveExportFolder($path, $save_path = false)
        {

            if (!is_dir($path)) {
                return false;
            }
            if (substr($path, -1) != DIRECTORY_SEPARATOR) {
                $path .= DIRECTORY_SEPARATOR;
            }
            if (!file_exists($path . 'known.json')) {
                \Idno\Core\Idno::site()->logging()->debug("{$path}known.json file does not exist");
                return false;
            }
            if (!class_exists('PharData')) {
                \Idno\Core\Idno::site()->logging()->debug("Phar support missing");
                return false;
            }

            $filename = str_replace('.', '_', Idno::site()->config()->host);
            if (empty($filename)) {
                $filename = 'export';
            }

            if (file_exists(site()->config()->getTempDir() . $filename . '.tar')) {
                @unlink(site()->config()->getTempDir() . $filename . '.tar');
                @unlink(site()->config()->getTempDir() . $filename . '.tar.gz');
            }

            $archive = new \PharData(site()->config()->getTempDir() . $filename . '.zip');
            $archive->buildFromDirectory($path);

            //$archive->compress(\Phar::GZ);

            \Idno\Core\Idno::site()->logging()->debug("archiveExportFolder() completed");

            return $archive->getPath();
        }

        /**
         * Wrapper function that exports Idno data and returns the path to the archive of it.
         *
         * @param  $dir Path to export
         * @return bool|string
         */
        static function createCompressedArchive($dir = false)
        {
            if ($path = self::exportToFolder($dir)) {
                if ($archive = self::archiveExportFolder($path)) {
                    self::cleanUpFolder($path);

                    return $archive;
                }
            }

            return false;
        }

        /**
         * Given the path to an archive folder, recursively removes it
         *
         * @param $path
         */
        static function cleanUpFolder($path)
        {
            foreach (glob("{$path}/*") as $file) {
                if (is_dir($file)) {
                    self::cleanUpFolder($file);
                } else {
                    @unlink($file);
                }
            }
            @rmdir($path);
        }

        /**
         * Given the XML source of an export, imports each post into Idno.
         *
         * @param $xml
         */
        static function importFeedXML(string $xml): bool
        {
            // Blogger will be imported as blog posts, so make sure we can import those ...
            if (!($text = Idno::site()->plugins()->get('Text'))) {
                return false;
            }

            $xml_parser = new \SimplePie();
            $xml_parser->set_raw_data($xml);
            $xml_parser->init();

            $imported = 0;

            if ($items = $xml_parser->get_items()) {

                foreach ($items as $item) {
                    /* @var \SimplePie_Item $item */

                    $post_type = 'post';
                    if ($categories = $item->get_categories()) {
                        foreach ($categories as $category) {
                            if (!empty($category->term) && !empty($category->scheme)) {
                                if ($category->scheme == 'http://schemas.google.com/g/2005#kind') {
                                    foreach (['settings', 'template', 'comment'] as $term) {
                                        if (substr_count($category->term, $term)) {
                                            $post_type = $term;
                                        }
                                    }
                                }
                            }
                        }
                    }

                    if ($post_type == 'post') {
                        $body = $item->get_content();
                        $tags = [];
                        if ($categories = $item->get_categories()) {
                            foreach ($categories as $category) {
                                if (empty($category->scheme) || $category->scheme != 'http://schemas.google.com/g/2005#kind') {
                                    $tags[] = '#' . preg_replace('/\W+/', '', $category->term);
                                }
                            }
                        }
                        if (!empty($tags)) {
                            $body .= '<p>' . implode(' ', $tags) . '</p>';
                        }

                        $body = self::importImagesFromBodyHTML($body, 'blogspot.com');

                        $object = new \IdnoPlugins\Text\Entry();
                        $object->setTitle(html_entity_decode($item->get_title()));
                        $object->created = strtotime(($item->get_date("c")));
                        $object->body = ($body);
                        //$object->publish(true);
                        $new_id = $object->save();
                        if (!empty($new_id)) {
                            $imported++;
                            \Idno\Core\Idno::site()->logging()->debug("..id=$new_id");
                        } else {
                            \Idno\Core\Idno::site()->logging()->debug("..failed to import ");
                        }
                    }
                }

                // for now, lets assume a successful save
                if ($imported > 0) {
                    return true;
                }
            }
        }

        static function importImagesFromBodyHTML(string $body, string $src_url): string
        {
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            $doc = new \DOMDocument();
            if (@$doc->loadHTML($body)) {
                if ($images = $doc->getElementsByTagName('img')) {
                    foreach ($images as $image) {
                        $src = $image->getAttribute('src');

                        // Validate the URL hostname matches the expected source domain
                        $parsed_url = parse_url($src);
                        if (empty($parsed_url['host'])) {
                            continue;
                        }
                        $host = strtolower($parsed_url['host']);
                        $expected_domain = strtolower($src_url);
                        if ($host !== $expected_domain && substr($host, -(strlen($expected_domain) + 1)) !== '.' . $expected_domain) {
                            continue;
                        }

                        // Validate file extension is an allowed image type
                        $path_part = isset($parsed_url['path']) ? $parsed_url['path'] : '';
                        $extension = strtolower(pathinfo($path_part, PATHINFO_EXTENSION));
                        if (!in_array($extension, $allowed_extensions)) {
                            continue;
                        }

                        // Use tempnam() for safe temp file creation
                        $dir = Idno::site()->config()->getTempDir();
                        $newname = tempnam($dir, 'import_');
                        if ($newname === false) {
                            continue;
                        }

                        if (@file_put_contents($newname, fopen($src, 'r'))) {
                            switch ($extension) {
                                case 'jpg':
                                case 'jpeg':
                                    $mime = 'image/jpg';
                                    break;
                                case 'gif':
                                    $mime = 'image/gif';
                                    break;
                                case 'png':
                                    $mime = 'image/png';
                                    break;
                                case 'webp':
                                    $mime = 'image/webp';
                                    break;
                                default:
                                    $mime = 'application/octet-stream';
                            }
                            if ($file = File::createFromFile($newname, basename($path_part), $mime, true)) {
                                $newsrc = \Idno\Core\Idno::site()->config()->getURL() . 'file/' . $file->file['_id'];
                                $body = str_replace($src, $newsrc, $body);
                            }
                        }
                        @unlink($newname);
                    }
                }
            }
            return $body;
        }

        /**
         * Given the XML source of a Blogger export, imports each post into Idno.
         *
         * @param $xml
         */
        static function importBloggerXML(string $xml): bool
        {
            return self::importFeedXML($xml);
        }

        /**
         * Given the XML source of a WordPress export, imports each post into Idno.
         *
         * @param $xml
         */
        static function importWordPressXML(string $xml): bool
        {
            // XML will be imported as blog posts, so make sure we can import those ...
            if (!($text = \Idno\Core\Idno::site()->plugins()->get('Text'))) {
                return false;
            }

            libxml_use_internal_errors(true);

            if ($data = simplexml_load_string($xml, null, LIBXML_NOCDATA)) {

                $namespaces = $data->getDocNamespaces(false);
                $namespaces[] = null;

                unset($namespace_data);
                unset($xml);

                $imported = 0;

                if (!empty($data->channel->item)) {
                    foreach ($data->channel->item as $item_structure) {
                        $item = [];
                        foreach ($namespaces as $ns => $namespace) {
                            if ($properties = (array) $item_structure->children($namespace)) {
                                foreach ($properties as $name => $val) {
                                    if (!empty($ns)) {
                                        $name = $ns . ':' . $name;
                                    }
                                    $item[$name] = $val;
                                }
                            }
                        }

                        if ($item['wp:post_type'] == 'post' && $item['wp:status'] == 'publish') {

                            $title = $item['title'];
                            if (!empty($item['content:encoded'])) {
                                $body = $item['content:encoded'];
                            } else if (!empty($item['description'])) {
                                $body = $item['description'];
                            } else {
                                $body = '';
                            }
                            if (!empty($item['wp:post_date'])) {
                                $published = strtotime($item['wp:post_date']);
                            } else if (!empty($item['pubDate'])) {
                                $published = strtotime($item['pubDate']);
                            } else {
                                $published = time();
                            }
                            if (!empty($item['category'])) {
                                $tags = [];
                                if (!is_array($item['category'])) {
                                    $item['category'] = [$item['category']];
                                }
                                foreach ($item['category'] as $category) {
                                    $category = strtolower(trim($category));
                                    if ($category != 'general' && $category != 'uncategorized') {
                                        $tags[] = '#' . preg_replace('/\W+/', '', $category);
                                    }
                                }
                                if (!empty($tags)) {
                                    $body .= '<p>' . implode(' ', $tags) . '</p>';
                                }
                            }

                            // TODO add ability to control which images are imported from the html
                            $body = self::importImagesFromBodyHTML($body, 'wordpress.com');
                            if (empty($item['title']) && strlen($body) < 600) {
                                \Idno\Core\Idno::site()->logging()->debug("Creating new Status post");
                                $object = new \IdnoPlugins\Status\Status();
                                $object->created = $published;
                                $object->body = ($body);
                                $new_id = $object->save();
                                //$object->publish(true); // Pinging probably a bad idea for imports, plus it is vvvvveeeeeeeerrrrrrrrrryyyyyyy slow
                                if (!empty($new_id)) {
                                    $imported++;
                                    \Idno\Core\Idno::site()->logging()->debug("..id=$new_id");
                                } else {
                                    \Idno\Core\Idno::site()->logging()->debug("..failed to import ");
                                }
                            } else {
                                \Idno\Core\Idno::site()->logging()->debug("Creating new Entry from '$title'");
                                $object = new \IdnoPlugins\Text\Entry();
                                $object->setTitle(html_entity_decode($title));
                                $object->created = $published;
                                $object->body = ($body);
                                $new_id = $object->save();

                                //$object->publish(true);
                                if (!empty($new_id)) {
                                    $imported++;
                                    \Idno\Core\Idno::site()->logging()->debug("..id=$new_id");
                                } else {
                                    \Idno\Core\Idno::site()->logging()->debug("..failed to import ");
                                }
                            }

                            // TODO: should probably skip comments if failed to create post
                            if (!empty($item['wp:comment'])) {
                                if (!is_array($item['wp:comment'])) {
                                    $item['wp:comment'] = [$item['wp:comment']];
                                }
                                foreach ($item['wp:comment'] as $comment_obj) {
                                    $comment = (array) $comment_obj;
                                    if ($object->addAnnotation(
                                        'reply', $comment['comment_author'], $comment['comment_author_url'], '', $comment['comment_content'], null, strtotime($comment['comment_date_gmt']), null, [], false
                                    )
                                    ) {
                                        $object->save();
                                    }
                                }
                            }
                        }
                    }

                    // For now, lets assume that everything saved ok, if something was imported
                    if ($imported > 0) {
                        return true;
                    }
                }
            }

            // Catch and log any XML parsing errors and report them
            foreach (libxml_get_errors() as $error) {
                \Idno\Core\Idno::site()->logging()->error($error->message);
            }

            libxml_clear_errors();
        }

        /**
         * Retrieve all posts as an RSS feed
         *
         * @param  bool|true $hide_private Should we hide private posts? Default: true.
         * @param  string    $user_uuid    User UUID to export for. Default: all users.
         * @param  bool|false $wxr_mode    Generate WXR (WordPress eXtended RSS) format. Default: false.
         * @return resource a file pointer resource on success, false on error
         */
        static function getExportRSS($hide_private = true, $user_uuid = '', $wxr_mode = false)
        {
            $types = \Idno\Common\ContentType::getRegisteredClasses();
            if ($hide_private) {
                $groups = ['PUBLIC'];
            } else {
                $groups = [];
            }
            if (!empty($user_uuid)) {
                $search = ['owner' => $user_uuid];
                if ($user = User::getByUUID($user_uuid)) {
                    $title = $user->getTitle();
                    $description = $user->getDescription();
                    $base_url = $user_uuid;
                }
            } else {
                $search = [];
                $title = Idno::site()->config()->getTitle();
                $description = Idno::site()->config()->getDescription();
                $base_url = Idno::site()->config()->getDisplayURL();
            }

            // If $folder is false or doesn't exist, use the temporary directory and ensure it has a slash on the end of it
            $dir = Idno::site()->config()->getTempDir();

            $name = md5(time() . rand(0, 9999) . Idno::site()->config()->getURL());

            // Make the temporary directory, or fail out
            if (!@mkdir($dir . $name)) {
                \Idno\Core\Idno::site()->logging()->debug("Could not make temporary directory {$dir}{$name}");
                return false;
            }

            $rss_path = $dir . $name . DIRECTORY_SEPARATOR . 'rss' . DIRECTORY_SEPARATOR;

            if (!@mkdir($rss_path)) {
                \Idno\Core\Idno::site()->logging()->debug("Could not make temporary directory {$rss_path}");
                return false;
            }

            // Create export of items
            $limit = 10;
            $offset = 0;

            $f = fopen($rss_path . 'items.rss.fragment', 'wb');

            $rssVars = $wxr_mode ? ['wxr_mode' => true] : [];

            while ($feed = \Idno\Common\Entity::getFromX($types, $search, array(), $limit, $offset, $groups)) {

                foreach ($feed as $item) {

                    $tmp = new \DOMDocument();
                    $tmp->formatOutput = true;

                    $rssNode = $item->rssSerialise($rssVars);
                    fwrite($f, $tmp->saveXML($tmp->importNode($rssNode, true)) . "\n");
                    unset($rssNode, $tmp);
                }

                unset($feed);
                gc_collect_cycles();
                $offset += $limit;
            }

            fclose($f);

            // Build the empty export template (items are injected from the fragment file,
            // not from the template, so pass an empty items array)
            $rss_theme = new DefaultTemplate();
            $rss_theme->setTemplateType('rss');

            file_put_contents(
                $rss_path . 'template.rss.fragment', $rss_theme->__(
                    array(
                        'title' => $title,
                        'description' => $description,
                        'body' => $rss_theme->__(
                            array(
                            'items' => [],
                            'offset' => 0,
                            'count' => 0,
                            'subject' => [],
                            'base_url' => $base_url,
                            'wxr_mode' => $wxr_mode,
                            )
                        )->draw('pages/home'),
                        'wxr_mode' => $wxr_mode,
                    )
                )->drawPage(false)
            );

            // Now construct full rss
            $f = fopen($rss_path . 'export.rss', 'wb');

            $f_template = fopen($rss_path . 'template.rss.fragment', 'rb');

            while ($content = fgets($f_template)) {

                // Write out the content
                fwrite($f, $content);

                // We've found our insertion point, so we need to mix in our second file.
                if (strpos($content, '<!--##KNOWNFEEDITEMS##-->') !== false) {
                    $f_items = fopen($rss_path . 'items.rss.fragment', 'rb');

                    while ($items = fgets($f_items)) {
                        fwrite($f, $items);
                    }

                    fclose($f_items);
                }
            }

            fclose($f_template);

            fclose($f);

            // Got here, open a readable file pointer
            return fopen($rss_path . 'export.rss', 'r');
        }

    }

}
