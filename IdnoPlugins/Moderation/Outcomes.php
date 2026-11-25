<?php

namespace IdnoPlugins\Moderation {

    /**
     * What a resolved case ended as, in words for administrators.
     */
    class Outcomes
    {

        public static function labels()
        {
            $language = \Idno\Core\Idno::site()->language();

            return [
                'dismissed' => $language->_('Dismissed'),
                'removed' => $language->_('Content removed'),
                'restored' => $language->_('Content restored'),
                'banned' => $language->_('Member banned'),
                'ban_lifted' => $language->_('Ban lifted'),
            ];
        }
    }

}
