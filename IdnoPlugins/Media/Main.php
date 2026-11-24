<?php

namespace IdnoPlugins\Media {

    class Main extends \Idno\Common\Plugin
    {

        function registerTranslations()
        {

            \Idno\Core\Idno::site()->language()->register(
                new \Idno\Core\GetTextTranslation(
                    'media', dirname(__FILE__) . '/languages/'
                )
            );
        }

        function registerPages()
        {
            \Idno\Core\Idno::site()->routes()->addRoute('/media/edit/?', '\IdnoPlugins\Media\Pages\Edit');
            \Idno\Core\Idno::site()->routes()->addRoute('/media/edit/:id/?', '\IdnoPlugins\Media\Pages\Edit');
            \Idno\Core\Idno::site()->routes()->addRoute('/media/delete/:id/?', '\IdnoPlugins\Media\Pages\Delete');
        }

        /**
         * Get the total file usage
         * @param bool $user
         * @return int
         */
        function getFileUsage($user = false)
        {

            $total = 0;

            if (!empty($user)) {
                $search = ['user' => $user];
            } else {
                $search = [];
            }

            if ($media = Media::get($search, [], 9999, 0)) {
                foreach ($media as $post) {
                    /* @var Media $post */
                    if ($attachments = $post->getAttachments()) {
                        foreach ($attachments as $attachment) {
                            $total += $attachment['length'];
                        }
                    }
                }
            }

            return $total;
        }

        function getAdminIcon()
        {
            return 'film';
        }
    }
}

