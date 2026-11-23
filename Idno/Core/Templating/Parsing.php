<?php

namespace Idno\Core\Templating {

    use Idno\Entities\User;

    trait Parsing
    {

        /**
         * Apply a function to the text of a piece of HTML, and only to the text: never to the
         * inside of a tag (an attribute value is not text), a comment, the content of an
         * existing <a> element, or the content of <script>, <style> and <textarea>. Plain
         * text (no tags) is all text.
         *
         * @param  string   $html
         * @param  callable $fn Takes a string of text, returns the string to put in its place
         * @return string
         */
        protected static function mapTextOutsideTags($html, callable $fn)
        {
            $html   = (string) $html;
            $tokens = preg_split(
                '/(<!--.*?-->|<\/?[a-zA-Z][^\s>\/]*(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>)/s',
                $html, -1, PREG_SPLIT_DELIM_CAPTURE
            );
            if ($tokens === false) {
                return $html;
            }

            $out    = '';
            $inLink = 0;
            $rawEnd = null; // the closing tag that ends script / style / textarea content
            foreach ($tokens as $i => $token) {
                if ($i % 2 === 0) { // text between tags
                    $out .= ($inLink > 0 || $rawEnd !== null || $token === '') ? $token : $fn($token);
                    continue;
                }
                $out .= $token;
                if ($token[1] === '!') {
                    continue; // a comment
                }
                if (!preg_match('/^<(\/?)([a-zA-Z][^\s>\/]*)/', $token, $m)) {
                    continue;
                }
                $name = strtolower($m[2]);
                if ($rawEnd !== null) {
                    if ($m[1] === '/' && $name === $rawEnd) {
                        $rawEnd = null;
                    }
                } elseif ($m[1] === '' && in_array($name, ['script', 'style', 'textarea'], true) && substr($token, -2) !== '/>') {
                    $rawEnd = $name;
                } elseif ($name === 'a') {
                    $inLink = $m[1] === '/' ? max(0, $inLink - 1) : $inLink + 1;
                }
            }

            return $out;
        }


        /**
         * Automatically links URLs embedded in a piece of text
         *
         * @param  string $text
         * @param  string $code Optionally, code to inject into the anchor tag (eg to add classes). '%URL%' is replaced with the URL. Default: blank.
         * @param  bool $wbr Optionally, inject <wbr /> tag. Default: true.
         * @return string
         */
        function parseURLs($text, $code = '', $wbr = true)
        {
            return static::mapTextOutsideTags($text, function ($text) use ($code, $wbr) {
                return preg_replace_callback(
                    '/(?<!=)(?<!["\'])((ht|f)tps?:\/\/[^\s<>"\']+)/i', function ($matches) use ($code, $wbr) {
                        $url  = $matches[1];
                        $punc = '';

                        while ($url) {
                            $last = substr($url, -1, 1);
                            if (strstr('.!?,;:(', $last)
                            // strip ) if there isn't a matching ( earlier in the url
                            || ($last === ')' && !strstr($url, '('))
                            ) {
                                $punc = $last . $punc;
                                $url  = substr($url, 0, -1);
                            } else {
                                break; // found a non-punctuation character
                            }
                        }

                        $result = "<a href=\"" . $url ."\"";
                        if (!\Idno\Common\Entity::isLocalUUID($url)) {
                            $result .= " target=\"_blank\" ";
                        }
                        if ($code) {
                            $result .= ' ' . str_replace("%URL%", $url, $code);
                        }
                        $result .= ">";
                        if ($wbr) {
                            $result .= preg_replace('/([\/=]+)/', '${1}<wbr />', static::sampleTextChars($url, 100));
                        } else {
                            $result .= preg_replace('/([\/=]+)/', '${1}', static::sampleTextChars($url, 100));
                        }
                        $result .= "</a>$punc";

                        return $result;

                    }, $text
                );
            });
        }

        /**
         * Link any hashtags in the text
         *
         * @param  $text
         * @return string
         */
        function parseHashtags($text)
        {
            // Decode &auml; to ä so that #Äpfel links, but leave alone every entity that stands for
            // < > & " ' or a backtick, in any spelling (named, decimal or hex, any case, leading
            // zeros): the text has already been sanitized or escaped, and decoding one of those
            // would turn it back into markup.
            $text = preg_replace_callback(
                '/&(?:#[0-9]+|#[xX][0-9a-fA-F]+|[A-Za-z][A-Za-z0-9]*);/',
                function ($matches) {
                    $decoded = html_entity_decode($matches[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($decoded === $matches[0] || strpbrk($decoded, "<>&\"'`") !== false) {
                        return $matches[0];
                    }

                    return html_entity_decode($matches[0]);
                },
                $text
            );
            return static::mapTextOutsideTags($text, function ($text) {
                return preg_replace_callback(
                    '/(?<=^|[\>\s\n])(\#[\p{L}0-9\_]+)/u', function ($matches) {
                        $url = $matches[1];
                        $tag = str_replace('#', '', $matches[1]);

                        if (preg_match('/\#[0-9]{1,3}$/', $matches[1])) {
                            return $matches[1];
                        }

                        if (preg_match('/\#[A-Fa-f0-9]{6}$/', $matches[1])) {
                            return $matches[1];
                        }

                        return '<a href="' . \Idno\Core\Idno::site()->config()->getDisplayURL() . 'tag/' . urlencode($tag) . '" class="p-category" rel="tag">' . $url . '</a>';
                    }, $text
                );
            });
        }

        /**
         * Change @user links into active users.
         *
         * @param type $text        The text to parse
         * @param type $in_reply_to If specified, the function will make a (hopefully) sensible guess as to where the user is located
         */
        function parseUsers($text, $in_reply_to = null)
        {
            return static::mapTextOutsideTags(
                $text, function ($text) use ($in_reply_to) {
                    return $this->parseUsersInText($text, $in_reply_to);
                }
            );
        }

        /**
         * parseUsers() for a piece of text that holds no markup
         */
        protected function parseUsersInText($text, $in_reply_to = null)
        {

            $usermatch_regex = '/(?<=^|[\>\s\n\.])(\@[\w0-9\_]+)/i';
            $r = $text;

            if (!empty($in_reply_to)) {

                // TODO: do this in a more pluggable way

                // It is only safe to make assumptions on @users if only one reply to is given
                if (!is_array($in_reply_to) || (is_array($in_reply_to) && count($in_reply_to) == 1)) {

                    if (is_array($in_reply_to)) {
                        $in_reply_to = $in_reply_to[0];
                    }

                    $r = preg_replace_callback(
                        $usermatch_regex, function ($matches) use ($in_reply_to) {
                            $url = $matches[1];

                            // Find and replace twitter
                            if (strpos($in_reply_to, 'twitter.com') !== false) {
                                return '<a href="https://twitter.com/' . urlencode(ltrim($matches[1], '@')) . '" target="_blank">' . $url . '</a>';
                                // Activate github
                            } else if (strpos($in_reply_to, 'github.com') !== false) {
                                return '<a href="https://github.com/' . urlencode(ltrim($matches[1], '@')) . '" target="_blank">' . $url . '</a>';
                            } else {
                                return \Idno\Core\Idno::site()->events()->triggerEvent(
                                    "template/parseusers", [
                                    'in_reply_to' => $in_reply_to,
                                    'in_reply_to_domain' => parse_url($in_reply_to, PHP_URL_HOST),
                                    'username' => ltrim($matches[1], '@'),
                                    'match' => $url
                                    ], $url
                                );
                            }
                        }, $text
                    );

                }

            } else {
                // No in-reply, so we assume a local user
                $r = preg_replace_callback(
                    $usermatch_regex, function ($matches) {
                        $url = $matches[1];

                        $username = ltrim($matches[1], '@');

                        if ($user = User::getByHandle($username)) {
                            return '<a href="' . \Idno\Core\Idno::site()->config()->url . 'profile/' . urlencode($username) . '" >' . $url . '</a>';
                        } else {
                            return $url;
                        }

                    }, $text
                );
            }

            return $r;
        }


    }
}
