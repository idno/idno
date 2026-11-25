<?php

namespace IdnoPlugins\Moderation {

    /**
     * The reasons a member can give for a report.
     */
    class Reasons
    {

        /// Reasons that sort to the top of the queue and mark the mail to administrators as urgent
        const URGENT = ['child-safety', 'violence', 'self-harm'];

        /**
         * Every reason, in the order the report form shows them: slug => label and hint
         *
         * @return array
         */
        public static function all()
        {
            $language = \Idno\Core\Idno::site()->language();

            return [
                'spam' => ['label' => $language->_('Spam'), 'hint' => $language->_('Ads, scams, repeated or misleading links')],
                'harassment' => ['label' => $language->_('Harassment or bullying'), 'hint' => $language->_('Targeting, insults, pile-ons')],
                'hate' => ['label' => $language->_('Hate speech'), 'hint' => $language->_('Attacks on people for who they are')],
                'violence' => ['label' => $language->_('Violence or threats'), 'hint' => $language->_('Threats, incitement, graphic violence')],
                'sexual' => ['label' => $language->_('Sexual content or nudity'), 'hint' => $language->_('Explicit or unwanted sexual material')],
                'child-safety' => ['label' => $language->_('Child safety'), 'hint' => $language->_('Anything that puts a child at risk')],
                'self-harm' => ['label' => $language->_('Self-harm or suicide'), 'hint' => $language->_('Someone may be in danger, or it is encouraged')],
                'impersonation' => ['label' => $language->_('Impersonation'), 'hint' => $language->_('Pretending to be someone else')],
                'privacy' => ['label' => $language->_('Privacy or personal information'), 'hint' => $language->_('Private details shared without consent')],
                'misinformation' => ['label' => $language->_('False or misleading information'), 'hint' => $language->_('Claims that are untrue and could cause harm')],
                'copyright' => ['label' => $language->_('Copyright or trademark'), 'hint' => $language->_("Someone's work used without permission")],
                'other' => ['label' => $language->_('Something else'), 'hint' => $language->_('Tell the moderators in your own words')],
            ];
        }

        public static function isValid($slug)
        {
            return is_string($slug) && array_key_exists($slug, static::all());
        }

        public static function isUrgent($slug)
        {
            return in_array($slug, self::URGENT, true);
        }

        /**
         * The label of a reason; the slug itself for one this version does not know
         */
        public static function label($slug)
        {
            $all = static::all();

            return isset($all[$slug]) ? $all[$slug]['label'] : (string) $slug;
        }
    }

}
