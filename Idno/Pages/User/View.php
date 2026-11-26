<?php

    /**
     * User profile
     */

namespace Idno\Pages\User {

    use Idno\Core\Idno;
    use Idno\Http\Controller\Profile\ProfilePages;
    use Idno\Http\Page\PageContext;
    use Idno\Http\Webmention\ReceivedWebmention;
    use Symfony\Component\HttpFoundation\Response;

    /**
     * A member's profile page: a deprecated shell, removed in 3.0.
     *
     * A profile is served by \Idno\Http\Controller\ProfileController, which the legacy route table hands the requests the profile's patterns match
     * (`/profile/<handle>`, its friendly content paths, and `/actor/<handle>` where the ActivityPub plugin does not take that address). This class has no
     * logic of its own: it hands its requests to the same service (\Idno\Http\Controller\Profile\ProfilePages), so a plugin's page that extends it, a route
     * a plugin registered for it, the profile edit form's POST (which posts to the profile's own address) and the webmentions of the profile are served as before.
     * A DELETE of the address is no handler of this page: it is answered as any page without one answers it, and deletes nothing.
     *
     * @deprecated Use \Idno\Http\Controller\ProfileController (the route `idno_profile`), or extend \Idno\Common\Page; removed in 3.0.
     */
    class View extends \Idno\Legacy\PageShell
    {
        protected const SHELL = self::class;

        public const ACTION = [\Idno\Http\Controller\ProfileController::class, 'view'];

        // Handle GET requests to the entity
        function getContent()
        {
            return $this->delegate(fn (PageContext $page): Response => Idno::container()->get(ProfilePages::class)->view($page));
        }

        // Handle POST requests to the entity (the profile edit form)
        function postContent()
        {
            return $this->delegate(fn (PageContext $page): Response => Idno::container()->get(ProfilePages::class)->save($page));
        }

        /**
         * A webmention to our profile page means someone mentioned us.
         */
        function webmentionContent($source, $target, $source_response, $source_mf2)
        {
            return $this->delegateWebmention(fn (): bool => Idno::container()->get(ProfilePages::class)->receiveWebmention('idno_profile', array_map('strval', array_values($this->arguments)), new ReceivedWebmention($source, $target, $source_response, $source_mf2)));
        }

    }

}
