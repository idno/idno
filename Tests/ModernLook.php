<?php

namespace Tests {

    use Idno\Core\Idno;

    /**
     * For a test class that runs with the site in its modern look (the `modern` template
     * type): useModernLook() in setUpBeforeClass(), restoreLook() in tearDownAfterClass().
     *
     * The look is what such a test is about, not the theme that happens to supply it. Which
     * theme that is today is said here and nowhere else, so a test stays true when another
     * theme takes its place.
     */
    trait ModernLook
    {

        /// The theme that draws the modern look
        protected static $modernTheme = 'Twenty26';

        private static $themeBefore;

        protected static function useModernLook(): void
        {
            static::$themeBefore = Idno::site()->config()->theme;
            Idno::site()->config()->theme = static::$modernTheme;
            Idno::site()->config()->save();
        }

        protected static function restoreLook(): void
        {
            Idno::site()->config()->theme = static::$themeBefore;
            Idno::site()->config()->save();
        }

        /**
         * A page is drawn in the modern look: with the look's own stylesheet, and with none of
         * the default look's grid, form classes or jQuery.
         *
         * @param string   $page  The page's HTML
         * @param string   $name  What the page is, for the failure message
         * @param string[] $marks Further text the page must not hold
         */
        protected function assertModernLook(string $page, string $name, array $marks = []): void
        {
            $this->assertMatchesRegularExpression('#<link[^>]*href="[^"]*/dist/modern\.min\.css#', $page, $name . ' should load the modern look\'s stylesheet');
            foreach (array_merge(['col-md-', 'form-horizontal', 'control-group', 'jumbotron', '$(', 'jQuery'], $marks) as $mark) {
                $this->assertStringNotContainsString($mark, $page, $name . ' should not hold "' . $mark . '"');
            }
        }
    }

}
