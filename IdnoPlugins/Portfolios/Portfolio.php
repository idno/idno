<?php

namespace IdnoPlugins\Portfolios {

    use Idno\Common\Entity;
    use Idno\Core\Idno;
    use Idno\Entities\User;

    /**
     * A portfolio: a named list of its owner's posts, in the order the owner gives them.
     *
     * The list is the "items" field, an array of the posts' UUIDs. Both database back ends
     * index each element of an array field, so the portfolios that hold a post are found with
     * a query for that UUID (containing()), and the order is the order of the array.
     *
     * A portfolio is a draft or published (the entity's publish status): a draft is found by
     * its owner and by administrators only, like any unpublished entity. Its access is always
     * PUBLIC. What a viewer sees inside a published portfolio is decided post by post
     * (getVisibleItems()): a post the viewer may not read is neither shown nor counted.
     *
     * It is not a registered content type, so it is in no stream, and it is not federated.
     */
    class Portfolio extends Entity
    {

        /// Longest name, in characters
        const MAX_NAME = 100;

        /// Longest description, in characters
        const MAX_DESCRIPTION = 2000;

        /// Most posts in one portfolio
        const MAX_ITEMS = 500;

        /// Longest slug made from a name
        const MAX_SLUG = 60;

        /**
         * Set the name: plain text on one line, at most MAX_NAME characters.
         *
         * @return bool False when nothing is left of it, and the name is then not changed
         */
        function setName($name): bool
        {
            $name = static::plainText((string) $name, true);
            $name = trim(mb_substr($name, 0, static::MAX_NAME, 'UTF-8'));
            if ($name === '') {
                return false;
            }
            $this->title = $name;

            return true;
        }

        /**
         * Set the description: plain text, at most MAX_DESCRIPTION characters.
         */
        function setDescriptionText($description): void
        {
            $description = static::plainText((string) $description, false);
            $this->description = trim(mb_substr($description, 0, static::MAX_DESCRIPTION, 'UTF-8'));
        }

        /**
         * The description as the owner typed it: plain text, to be escaped where it is printed.
         */
        function getDescriptionText(): string
        {
            return is_string($this->description) ? $this->description : '';
        }

        /**
         * Text as typed, without control characters and invalid bytes.
         *
         * @param string $text
         * @param bool   $oneLine Turn every run of white space into one space
         */
        protected static function plainText(string $text, bool $oneLine): string
        {
            if (!mb_check_encoding($text, 'UTF-8')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            }
            $text = str_replace(["\r\n", "\r"], "\n", $text);
            $text = (string) preg_replace('/[^\P{C}\n\t]/u', '', $text);
            if ($oneLine) {
                $text = (string) preg_replace('/\s+/u', ' ', $text);
            }

            return trim($text);
        }

        /**
         * The UUIDs of the posts in this portfolio, in order, whoever is looking. Never print
         * these to a viewer without checking each post: use getVisibleItems().
         *
         * @return string[]
         */
        function getItemUUIDs(): array
        {
            $items = $this->items;
            if (empty($items)) {
                return [];
            }
            if (!is_array($items)) {
                $items = [$items];
            }

            return array_values(array_filter($items, function ($uuid) {
                return is_string($uuid) && $uuid !== '';
            }));
        }

        function hasItem($uuid): bool
        {
            return is_string($uuid) && in_array($uuid, $this->getItemUUIDs(), true);
        }

        /**
         * May this post go into this portfolio? It must be a saved post (not a member, a
         * portfolio or a file) that belongs to the portfolio's owner.
         */
        function accepts($post): bool
        {
            if (!($post instanceof Entity) || $post instanceof User || $post instanceof self) {
                return false;
            }
            if (!\Idno\Common\ContentType::getContentTypeObjectFromClass($post->getClass())) {
                return false;
            }
            $owner = $this->getOwnerID() ?: Idno::site()->session()->currentUserUUID();

            return !empty($owner) && $post->getUUID() && $post->getOwnerID() === $owner;
        }

        /**
         * Add a post to the end of the list. Does not save.
         *
         * @return bool False when the post is not the owner's, is already there, or the portfolio is full
         */
        function addItem($post): bool
        {
            if (!$this->accepts($post)) {
                return false;
            }
            $items = $this->getItemUUIDs();
            if (in_array($post->getUUID(), $items, true) || count($items) >= static::MAX_ITEMS) {
                return false;
            }
            $items[] = $post->getUUID();
            $this->items = $items;

            return true;
        }

        /**
         * Take a post out of the list. Does not save.
         *
         * @return bool False when it was not there
         */
        function removeItem($uuid): bool
        {
            $items = $this->getItemUUIDs();
            $position = array_search($uuid, $items, true);
            if ($position === false) {
                return false;
            }
            array_splice($items, $position, 1);
            $this->items = $items;

            return true;
        }

        /**
         * Move a post up (a negative number) or down the list by so many places. Does not save.
         *
         * @return bool False when it is not there or cannot move that far
         */
        function moveItem($uuid, int $by): bool
        {
            $items = $this->getItemUUIDs();
            $position = array_search($uuid, $items, true);
            if ($position === false || $by === 0) {
                return false;
            }
            $target = $position + $by;
            if ($target < 0 || $target >= count($items)) {
                return false;
            }
            array_splice($items, $position, 1);
            array_splice($items, $target, 0, [$uuid]);
            $this->items = $items;

            return true;
        }

        /// The posts the current viewer may read, once worked out
        private $visible = null;

        /// Who $visible was worked out for
        private $visibleFor = null;

        /**
         * The posts of this portfolio that the current viewer may read, in order. A post that
         * is a draft, is private, has been deleted or is no longer the owner's is left out.
         *
         * @return Entity[]
         */
        function getVisibleItems(): array
        {
            $viewer = (string) Idno::site()->session()->currentUserUUID();
            if ($this->visible !== null && $this->visibleFor === $viewer . '|' . implode('|', $this->getItemUUIDs())) {
                return $this->visible;
            }
            $visible = [];
            foreach ($this->getItemUUIDs() as $uuid) {
                $post = Entity::getByUUID($uuid, false);
                if (!($post instanceof Entity) || $post instanceof User || $post instanceof self) {
                    continue;
                }
                if ($post->getOwnerID() !== $this->getOwnerID() || !$post->canRead()) {
                    continue;
                }
                $visible[] = $post;
            }
            $this->visible = $visible;
            $this->visibleFor = $viewer . '|' . implode('|', $this->getItemUUIDs());

            return $visible;
        }

        function countVisibleItems(): int
        {
            return count($this->getVisibleItems());
        }

        /**
         * The part of the address that names this portfolio: its slug, or its id when its
         * name gave no slug.
         */
        function getAddressKey(): string
        {
            $slug = $this->portfolio_slug;

            return is_string($slug) && $slug !== '' ? $slug : (string) $this->getID();
        }

        function getURL()
        {
            $owner = $this->getOwner();
            if (!($owner instanceof User) || !$this->getID()) {
                return Idno::site()->config()->getDisplayURL();
            }

            return Idno::site()->config()->getDisplayURL() . 'profile/' . rawurlencode($owner->getHandle()) . '/portfolio/' . rawurlencode($this->getAddressKey());
        }

        function getEditURL()
        {
            return Idno::site()->config()->getDisplayURL() . 'portfolio/edit/' . $this->getID();
        }

        function getDeleteURL()
        {
            return Idno::site()->config()->getDisplayURL() . 'portfolio/delete/' . $this->getID();
        }

        /**
         * Where posts are removed from this portfolio and moved within it
         */
        function getItemsURL()
        {
            return Idno::site()->config()->getDisplayURL() . 'portfolio/items/' . $this->getID();
        }

        /**
         * The address of a member's list of portfolios
         */
        static function listURL(User $owner): string
        {
            return Idno::site()->config()->getDisplayURL() . 'profile/' . rawurlencode($owner->getHandle()) . '/portfolios';
        }

        /**
         * A slug for a name: lower-case ASCII letters, digits and hyphens, or an empty string.
         */
        static function slugify(string $name): string
        {
            if (class_exists('\Normalizer')) {
                $decomposed = \Normalizer::normalize($name, \Normalizer::FORM_D);
                if (is_string($decomposed)) {
                    $name = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
                }
            }
            $slug = strtolower($name);
            $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
            $slug = trim(substr($slug, 0, static::MAX_SLUG), '-');

            return $slug;
        }

        /**
         * Give a new portfolio its slug, made from its name and unlike the slugs of the
         * owner's other portfolios. It is never changed afterwards, so that the address
         * stays the same when the name changes.
         */
        protected function chooseSlug(): void
        {
            $base = static::slugify((string) $this->getTitle());
            // A slug that looks like an id (24 hex digits, or a UUID) could be taken for another portfolio's id
            if ($base === '' || preg_match('/^([0-9a-f]{24}|[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12})$/', $base)) {
                $this->portfolio_slug = '';

                return;
            }
            $db = Idno::site()->db();
            $ignored = $db->getIgnoreAccess();
            $db->setIgnoreAccess(true);
            try {
                $slug = $base;
                $number = 1;
                while (static::getOne(['owner' => $this->getOwnerID(), 'portfolio_slug' => $slug])) {
                    $number++;
                    $slug = $base . '-' . $number;
                    if ($number > 200) {
                        $slug = '';
                        break;
                    }
                }
            } finally {
                $db->setIgnoreAccess($ignored);
            }
            $this->portfolio_slug = $slug;
        }

        function save($overrideAccess = false)
        {
            $owner_id = $this->getOwnerID();
            if (empty($owner_id) && Idno::site()->session()->isLoggedIn()) {
                $this->setOwner(Idno::site()->session()->currentUser());
            }
            if (empty($this->_id)) {
                if (!isset($this->attributes['portfolio_slug'])) {
                    $this->chooseSlug();
                }
                // Not a post: keep it out of the way of the slugs that post addresses use
                if (!$this->getSlug()) {
                    $this->slug = 'portfolio-' . bin2hex(random_bytes(8));
                }
            }
            if ($this->getPublishStatus() !== 'draft') {
                $this->setPublishStatus('published');
            }
            $this->access = 'PUBLIC';
            $this->items = $this->getItemUUIDs();
            $this->visible = null;

            return parent::save($overrideAccess);
        }

        /**
         * A member's portfolios that the current viewer may see (all of them for the member
         * and for administrators, the published ones for anyone else), newest first.
         *
         * @return Portfolio[]
         */
        static function forOwner(User $owner, $limit = 200): array
        {
            $found = static::get(['owner' => $owner->getUUID()], [], $limit) ?: [];

            return array_values(array_filter($found, function ($portfolio) {
                return $portfolio instanceof self && $portfolio->canRead();
            }));
        }

        /**
         * Has this member a published portfolio?
         */
        static function hasPublished(User $owner): bool
        {
            return !empty(static::get(['owner' => $owner->getUUID(), 'publish_status' => 'published'], [], 1));
        }

        /**
         * The portfolio of this member that an address names, by slug or by id, if the
         * current viewer may see it.
         */
        static function findFor(User $owner, $key): ?Portfolio
        {
            $key = (string) $key;
            if ($key === '' || strlen($key) > 255) {
                return null;
            }
            $portfolio = static::getOne(['owner' => $owner->getUUID(), 'portfolio_slug' => $key]);
            if (!($portfolio instanceof self)) {
                $portfolio = static::findByID($key);
            }
            if (!($portfolio instanceof self) || $portfolio->getOwnerID() !== $owner->getUUID() || !$portfolio->canRead()) {
                return null;
            }

            return $portfolio;
        }

        /**
         * The portfolio with this id, if there is one and the current viewer may see it.
         */
        static function findByID($id): ?Portfolio
        {
            $id = (string) $id;
            if (!preg_match('/^[A-Za-z0-9\-]{1,64}$/', $id)) {
                return null;
            }
            $portfolio = Entity::getByID($id);

            return $portfolio instanceof self && $portfolio->canRead() ? $portfolio : null;
        }

        /**
         * The portfolios that hold this post and that the current viewer may see.
         *
         * @return Portfolio[]
         */
        static function containing($uuid, $limit = 200): array
        {
            if (!is_string($uuid) || $uuid === '') {
                return [];
            }
            $found = static::get(['items' => $uuid], [], $limit) ?: [];

            return array_values(array_filter($found, function ($portfolio) use ($uuid) {
                return $portfolio instanceof self && $portfolio->hasItem($uuid);
            }));
        }

        /**
         * Take a post out of every portfolio, whoever is asking: called when the post is deleted.
         */
        static function removeEverywhere($uuid): void
        {
            if (!is_string($uuid) || $uuid === '') {
                return;
            }
            $db = Idno::site()->db();
            $ignored = $db->getIgnoreAccess();
            $db->setIgnoreAccess(true);
            try {
                $rounds = 0;
                while (($found = static::get(['items' => $uuid], [], 50)) && $rounds++ < 100) {
                    $changed = false;
                    foreach ($found as $portfolio) {
                        if ($portfolio instanceof self && $portfolio->removeItem($uuid) && $portfolio->save(true)) {
                            $changed = true;
                        }
                    }
                    if (!$changed) {
                        break;
                    }
                }
            } finally {
                $db->setIgnoreAccess($ignored);
            }
        }

        /**
         * What lists of mixed entities (a member's drafts, for one) call this kind of thing.
         */
        function getContentTypeCategoryTitle()
        {
            return Idno::site()->language()->_('Portfolio');
        }

        /**
         * What a feed reader is told a portfolio is: its description, as HTML.
         */
        function getDescription()
        {
            return nl2br(htmlspecialchars($this->getDescriptionText(), ENT_QUOTES, 'UTF-8'));
        }

        /**
         * Only what the current viewer may see: the name, the description, the address and
         * the addresses of the posts they may read.
         */
        public function jsonSerialize(): mixed
        {
            $owner = $this->getOwner();

            return [
                'id' => (string) $this->getID(),
                'uuid' => $this->getUUID(),
                'objectType' => 'portfolio',
                'displayName' => (string) $this->getTitle(),
                'content' => $this->getDescriptionText(),
                'status' => $this->getPublishStatus() === 'draft' ? 'draft' : 'published',
                'published' => date(\DateTime::RFC3339, (int) $this->created),
                'updated' => date(\DateTime::RFC3339, (int) $this->updated),
                'url' => $this->getURL(),
                'owner' => $owner instanceof User ? $owner->getURL() : null,
                'items' => array_map(function (Entity $post) {
                    return $post->getUUID();
                }, $this->getVisibleItems()),
            ];
        }

    }

}
