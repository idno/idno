<?php

namespace Idno\Entities\Reader {

    use Idno\Common\Entity;

    #[\Idno\Storage\Attribute\AsEntity('feed_item', collection: 'reader')]
    #[\Idno\Storage\Attribute\IndexedField('feed_url')]
    class FeedItem extends Entity
    {

        public static $retrieve_collection = 'reader';
        public $collection = 'reader';

        /**
         * Sets the URL of the feed this item belongs to
         *
         * @param $url
         */
        function setFeedURL($url)
        {
            $this->feed_url = $url;
        }

        /**
         * Retrieves the URL of the feed this item belongs to
         *
         * @param  $url
         * @return mixed
         */
        function getFeedURL()
        {
            return $this->feed_url;
        }

        /**
         * Retrieves the body of this item
         *
         * @return string
         */
        function getBody()
        {
            return $this->body;
        }

        /**
         * Sets the non-HTML value of this item
         *
         * @param $content
         */
        function setValue($content)
        {
            $this->value = $content;
        }

        /**
         * Retrieves the non-HTML value of this item
         *
         * @return mixed
         */
        function getValue()
        {
            return $this->value;
        }

        /**
         * Retrieves the URL of a photo associated with this item
         *
         * @param  $photo
         * @return mixed
         */
        function getPhoto($photo)
        {
            return $this->photo;
        }

        /**
         * Retrieves the name of the author of this item
         *
         * @return mixed
         */
        function getAuthorName()
        {
            return $this->authorName;
        }

        /**
         * Retrieves the URL of the author photo associated with this piece
         *
         * @param  $author_photo
         * @return mixed
         */
        function getAuthorPhoto()
        {
            if ($photo = $this->authorPhoto) {
                return $photo;
            }
            $bn     = hexdec(substr(md5($this->getUUID()), 0, 15));
            $number = 1 + ($bn % 5);

            return \Idno\Core\Idno::site()->config()->getStaticURL() . 'gfx/users/default-' . str_pad($number, 2, '0', STR_PAD_LEFT) . '.png';
        }

        /**
         * Retrieves the URL of the author of this item
         *
         * @return mixed
         */
        function getAuthorURL()
        {
            return $this->authorURL;
        }

        /**
         * Retrieves the URLs to syndicated versions of this item
         *
         * @return array
         */
        function getSyndication()
        {
            if (!empty($this->syndication)) {
                return $this->syndication;
            }

            return array();
        }

        /**
         * Given a parsed microformats 2 structure for this item, populates this object
         *
         * @param $item
         * @param $url
         */
        function loadFromMF2($mf)
        {
            $this->setTitle($this->mfpath($mf, "name/1"));
            $this->setPublishDate($this->mfpath($mf, "published/1"));
            $this->setBody($this->mfpath($mf, "content/html/1"));
            $this->setPhoto($this->mfpath($mf, "photo/1"));
            $this->setURL($this->mfpath($mf, "url/1"));
            $this->setAuthorName($this->mfpath($mf, "author/name/1"));
            $this->setAuthorPhoto($this->mfpath($mf, "author/photo/1"));
            $this->setAuthorURL($this->mfpath($mf, "author/url/1"));
            $this->setSyndication($this->mfpath($mf, "syndication"));
        }

        function mfpath($mf, $path)
        {
            $elts = array_filter(
                explode("/", $path), function ($e) {
                    return $e != "";
                }
            );

            return array_reduce(
                $elts, function ($result, $elt) {
                    return $this->mfprop($result, $elt);
                }, $mf
            );
        }

        function mfprop($mfs, $prop)
        {
            $props = array();
            if ($prop == "1") {
                if (isset($mfs[0])) {
                    return $mfs[0];
                }

                return null;
            }
            foreach ($mfs as $mf) {
                if (isset($mf["properties"][$prop])) {
                    $thisprops = $this->scrubstrings($mf["properties"][$prop]);
                } else if ($prop == "children" && isset($mf[$prop])) {
                    $thisprops = $mf[$prop];
                } else if (($prop == "html") && isset($mf[$prop])) {
                    $thisprops = array($mf[$prop]);
                } else if (($prop == "value") && isset($mf[$prop])) {
                    $thisprops = $this->scrubstrings(array($mf[$prop]));
                } else {
                    continue;
                }
                $props = array_merge($props, $thisprops);
            }

            return $props;
        }

        function scrubstrings($arr)
        {
            return array_map(
                function ($elt) {
                    if (gettype($elt) == "string") {
                        return htmlspecialchars($elt);
                    }

                    return $elt;
                }, $arr
            );
        }

        /**
         * Sets the time that this item was published
         *
         * @param $time
         */
        function setPublishDate($time)
        {
            $this->created = strtotime($time);
        }

        /**
         * Sets the body of this item to the given content string
         *
         * @param $content
         */
        function setBody($content)
        {
            $this->body = $content;
        }

        /**
         * Sets the URL of a photo associated with this item
         *
         * @param $photo
         */
        function setPhoto($photo)
        {
            $this->photo = $photo;
        }

        /**
         * Sets the URL of this feed item. The address is the followed site's word, so it is kept only when it is an http or https
         * address (as an annotation's is): a `javascript:` address is a link that runs a script. Any other is no address.
         *
         * @param $url
         */
        function setURL($url)
        {
            $url       = \Idno\Core\UrlValidator::httpUrl($url);
            $this->url = $url === '' ? null : $url;
        }

        /**
         * Sets the name of the author of this item
         *
         * @param $author_name
         */
        function setAuthorName($author_name)
        {
            $this->authorName = $author_name;
        }

        /**
         * Sets the URL of the author photo associated with this piece
         *
         * @param $author_photo
         */
        function setAuthorPhoto($author_photo)
        {
            $this->authorPhoto = $author_photo;
        }

        /**
         * Sets the URL of the author of this item
         *
         * @param $url
         */
        function setAuthorURL($url)
        {
            $this->authorURL = $url;
        }

        /**
         * Sets an array containing the syndication points of this item
         *
         * @param $syndication
         */
        function setSyndication($syndication)
        {
            $this->syndication = $syndication;
        }

        /**
         * Given a SimplePie-parsed XML item, populates this object
         *
         * @param $item
         */
        function loadFromXMLItem($item)
        {
            $this->setTitle($item->get_title());
            $this->setPublishDate($item->get_date("c"));
            $this->setBody($item->get_content());
            $this->setURL($item->get_permalink());

            if ($author = $item->get_author()) {
                $this->setAuthorName($author->get_name());
                $this->setAuthorURL($author->get_link());
            }
        }

        function mftype($parsed, $type)
        {
            return array_filter(
                $parsed["items"], function ($elt) use ($type) {
                    return in_array($type, $elt["type"]);
                }
            );
        }

        /**
         * Saves this item if it hasn't been saved yet
         *
         * @return $this|bool|false|Entity
         */
        function saveIfNotSaved()
        {
            if ($object = FeedItem::getOne(array('url' => $this->url))) {
                return $object;
            }
            if ($this->save()) {
                return $this;
            }

            return false;
        }

    }

}

