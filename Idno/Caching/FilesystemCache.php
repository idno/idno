<?php

namespace Idno\Caching {

    use Symfony\Component\Cache\Adapter\FilesystemAdapter;

    /**
     * Implement a persistent cache using the local filesystem.
     *
     * Uses cachepath, then uploadpath and finally system temp as the base path.
     */
    class FilesystemCache extends PersistentCache
    {

        public function __construct()
        {
            $domain = md5(\Idno\Core\Idno::site()->config()->host);
            if (empty($domain)) {
                throw new \Idno\Exceptions\TechnicalException(\Idno\Core\Idno::site()->language()->_("No domain specified for cache"));
            }

            $pathbase = \Idno\Core\Idno::site()->config()->cachepath;
            if (empty($pathbase)) {
                $pathbase = \Idno\Core\Idno::site()->config()->uploadpath;
            }
            if (empty($pathbase)) {
                $pathbase = \Idno\Core\Idno::site()->config()->getTempDir();
            }
            $engine = new FilesystemAdapter($domain, 0, $pathbase);

            $this->setCacheEngine($engine);

        }

    }

}
