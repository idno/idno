<?php

namespace Idno\Pages\Chrome {

    use Idno\Common\Page;

    class Manifest extends Page
    {

        function getContent()
        {

            header('Content-type: application/json');
            echo json_encode($this->manifest(), JSON_PRETTY_PRINT);

        }

        /**
         * The web app manifest: the site's name, its icons and where the app starts.
         *
         * @return array
         */
        function manifest()
        {
            $icons = \Idno\Core\Idno::site()->getSiteIcons();

            // Calculate application scope, match that of service worker
            $scope = '/';
            $url   = parse_url(\Idno\Core\Idno::site()->config()->getDisplayURL());
            if (!empty($url['path'])) {
                $scope = $url['path'];
            }

            $manifest = [
                'name'       => \Idno\Core\Idno::site()->config()->getTitle(),
                'short_name' => \Idno\Core\Idno::site()->config()->getTitle(),
                'icons'      => [],
                'start_url'  => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'session/login',
                'display'    => 'standalone',
                'scope'      => $scope
            ];

            // Crufty, but slightly more extendable icons
            foreach (['36', '48', '72', '96', '144', '192'] as $size) {

                $namebits = explode('.', $icons['defaults']['default_' . $size]);

                $manifest['icons'][] = [
                    'src'   => $icons['defaults']['default_' . $size],
                    'sizes' => $size . 'x' . $size, // Assume square for now.
                    'type'  => 'image/' . end($namebits)
                ];
            }

            return $manifest;
        }

    }

}
