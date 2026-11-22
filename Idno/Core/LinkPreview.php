<?php

namespace Idno\Core {

    /**
     * Centralized link preview system.
     *
     * Listens for the 'published' event on any entity and, if the entity's
     * body contains a URL, enqueues an async job to fetch OpenGraph / meta
     * tag metadata. The result is cached directly on the entity as
     * `link_preview` so templates can render it server-side.
     *
     * Any plugin can use this by:
     *  1. Including a text `body` property on its entity.
     *  2. Rendering `entity/LinkPreviewCard` with $vars['preview'] in its
     *     view template when `$entity->link_preview` is populated.
     */
    class LinkPreview extends \Idno\Common\Component
    {

        /**
         * Extract the first HTTP(S) URL from a string of text.
         *
         * @param  string $text
         * @return string|null The URL, or null if none found.
         */
        public static function extractFirstUrl($text)
        {
            if (preg_match('/(?<!=)(?<!["\'])((ht|f)tps?:\/\/[^\s<>"\']+)/i', $text, $matches)) {
                return rtrim($matches[1], '.,!?;:)');
            }

            return null;
        }

        /**
         * Build a normalized preview array from UnfurledUrl data.
         *
         * @param  string $url  The source URL.
         * @param  array  $data The raw data from UnfurledUrl::unfurl().
         * @return array  Preview with keys: url, title, description, image, domain, site_name.
         *                The url and the image are http or https addresses, or empty.
         */
        public static function buildPreviewFromData($url, $data)
        {
            // The link and the image are printed as addresses: keep only http and https ones
            $url = UrlValidator::httpUrl($url);

            $preview = [
                'url'         => $url,
                'title'       => '',
                'description' => '',
                'image'       => '',
                'domain'      => parse_url($url, PHP_URL_HOST) ?: '',
            ];

            if (!empty($data['og']['og:title'])) {
                $preview['title'] = $data['og']['og:title'];
            } elseif (!empty($data['title'])) {
                $preview['title'] = $data['title'];
            }

            if (!empty($data['og']['og:description'])) {
                $preview['description'] = $data['og']['og:description'];
            } elseif (!empty($data['description'])) {
                $preview['description'] = $data['description'];
            }

            if (!empty($data['og']['og:image'])) {
                // This comes from the page that was fetched
                $preview['image'] = UrlValidator::httpUrl($data['og']['og:image']);
            }

            if (!empty($data['og']['og:site_name'])) {
                $preview['site_name'] = $data['og']['og:site_name'];
            }

            return $preview;
        }

        function registerEventHooks()
        {

            // When any entity is published, check for a URL and enqueue preview fetching
            Idno::site()->events()->addListener(
                'published', function (Event $event) {
                    $eventdata = $event->data();
                    $object = $eventdata['object'] ?? null;
                    if (empty($object)) {
                        return;
                    }

                    $body = $object->body ?? '';
                    if (empty($body)) {
                        return;
                    }

                    $url = self::extractFirstUrl($body);
                    if (empty($url)) {
                        return;
                    }

                    Idno::site()->queue()->enqueue('default', 'linkpreview/fetch', [
                        'entity_id' => (string) $object->_id,
                        'url'       => $url,
                    ]);
                }
            );

            // Handle the async link preview fetch
            Idno::site()->events()->addListener(
                'linkpreview/fetch', function (Event $event) {
                    $eventdata = $event->data();
                    $entityId  = $eventdata['entity_id'] ?? '';
                    $url       = $eventdata['url'] ?? '';

                    if (empty($entityId) || empty($url)) {
                        return;
                    }

                    $entity = \Idno\Common\Entity::getByID($entityId);
                    if (empty($entity)) {
                        return;
                    }

                    // Skip if already cached for this URL
                    if (!empty($entity->link_preview) && ($entity->link_preview['url'] ?? '') === $url) {
                        $event->setResponse(['status' => 'already_cached']);

                        return;
                    }

                    // Reuse a cached UnfurledUrl if one exists, otherwise fetch fresh
                    $unfurled = \Idno\Entities\UnfurledUrl::getBySourceURL($url);
                    if (empty($unfurled)) {
                        $unfurled = new \Idno\Entities\UnfurledUrl();
                        $unfurled->setAccess('PUBLIC');
                        if (!$unfurled->unfurl($url)) {
                            $event->setResponse(['status' => 'unfurl_failed']);

                            return;
                        }
                        $unfurled->save();
                    }

                    $preview = self::buildPreviewFromData($url, $unfurled->data);

                    $entity->link_preview = $preview;
                    $entity->save();

                    $event->setResponse(['status' => 'ok', 'preview' => $preview]);
                }
            );
        }
    }

}
