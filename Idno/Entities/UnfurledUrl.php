<?php

namespace Idno\Entities {

    use Idno\Common\Component;

    class UnfurledUrl extends BaseObject
    {

        /**
         * Copied and modified from https://github.com/mapkyca/php-ogp, extract information from graph headers
         *
         * @param type $content
         */
        private static function parseHeaders($content)
        {
            return \ogp\Parser::parse($content);
        }

        /**
         * Basic security, don't allow every domain to push oembed.
         */
        public function isOEmbedWhitelisted()
        {

            $host = parse_url($this->source_url, PHP_URL_HOST);

            if (!empty($host)) {
                $host = str_replace('www.', '', $host);
                return in_array(
                    $host, [
                    'youtube.com',
                    'youtu.be',
                    'instagram.com',
                    'soundcloud.com',
                    'twitter.com',
                    'vimeo.com',
                    'amazon.com',
                    'amazon.co.uk',
                    'radiopublic.com',
                    ]
                );
            }

            return false;
        }

        /**
         * Unfurl and unpack a url, extracting title, description, open-graph and oembed
         *
         * @param type $url
         */
        public function unfurl($url)
        {

            $url = trim($url);
            $unfurled = [];

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return false;
            }

            // SSRF protection: safeSend() refuses the URL, and anything it redirects to, if it is
            // on this machine or its private network
            $response = \Idno\Core\Webservice::safeSend('get', $url);
            $contents = empty($response['error']) ? $response['content'] : false;
            if (!empty($contents)) {

                // Extract OpenGraph/Facebook/Twitter stuff
                $graphheaders = self::parseHeaders($contents);
                if (!empty($graphheaders)) {

                    // Unfurled url
                    $unfurled = array_merge($unfurled, $graphheaders);

                    // See if there's any mf2 in content
                    $parser = new \Mf2\Parser($contents, $url);
                    try {
                        $mf2 = $parser->parse();
                        if (!empty($mf2)) {
                            $unfurled['mf2'] = $mf2;
                        }
                    } catch (\Exception $e) {
                        \Idno\Core\Idno::site()->logging()->debug($e->getMessage());
                    }
                }

                $this->data = $unfurled;
                $this->source_url = $url;

                return true;
            }

            return false;
        }

        public static function getBySourceURL($url)
        {
            if (!static::isLookupValue($url)) {
                return false;
            }
            return static::getOne(['source_url' => $url]);
        }

    }

}
