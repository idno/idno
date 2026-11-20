<?php

    /**
     * Idno webmention client.
     *
     * This class extends the IndieWeb webmention client and better integrates it
     * with Idno core.
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Core {

    class MentionClient extends \IndieWeb\MentionClient
    {

        protected static function _post($url, $body, $headers = array())
        {
            $response = Webservice::safeSend('post', $url, $body, $headers);
            return [
                'code'    => $response['response'],
                'headers' => self::_parse_headers(isset($response['header']) ? $response['header'] : ''),
                'body'    => $response['content'],
            ];
        }

        protected static function _head($url, $headers = array())
        {
            $response = Webservice::safeSend('head', $url, null, $headers);
            return [
                'code'    => $response['response'],
                'headers' => self::_parse_headers(isset($response['header']) ? $response['header'] : ''),
                'url'     => $response['effective_url']
            ];
        }

        protected static function _get($url, $headers = array())
        {
            $response = Webservice::safeSend('get', $url, null, $headers);
            return [
                'code'    => $response['response'],
                'headers' => self::_parse_headers(isset($response['header']) ? $response['header'] : ''),
                'body'    => $response['content'],
                'url'     => $response['effective_url']
            ];
        }

    }

}
