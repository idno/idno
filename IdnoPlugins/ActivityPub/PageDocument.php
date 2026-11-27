<?php

namespace IdnoPlugins\ActivityPub;

use Idno\Common\Entity;
use Idno\Common\Page;
use Idno\Entities\User;

/**
 * What a page answers when it is asked for as ActivityPub
 * (Accept: application/activity+json, or application/ld+json with the ActivityStreams profile).
 *
 * The answer is decided from the page that is being served: the entity it is the permalink
 * of, and its response code. It is never decided from template variables, which stay set
 * between draws: the default picture of a member is drawn by a template that sets "user",
 * and that once made every post of a member without a picture answer with the member.
 *
 * - The permalink of a post that other servers may have (ActivityBuilder::isFederated()):
 *   ActivityBuilder::buildDocument(), the object that is delivered.
 * - The profile of a member: ActivityBuilder::buildActor(), as the actor's own address; 410
 *   for a banned member.
 * - A page that answers with an error: that error.
 * - Anything else has no ActivityPub form (406): a page that is not a permalink, a kind of
 *   entity that is not federated (a portfolio), a post that only its author can see.
 */
class PageDocument
{

    /**
     * @param Page|null $page The page being served
     * @return array ['status' => int, 'document' => array]
     */
    public static function forPage(?Page $page): array
    {
        $status = $page ? $page->response() : 200;
        if ($status >= 400) {
            return self::error($status);
        }

        $entity = ($page && $page->isPermalink()) ? $page->getEntity() : null;

        if ($entity instanceof User) {
            if ($entity->isBanned() || $entity->isClosed()) {
                return self::error(410);
            }

            return ['status' => 200, 'document' => ActivityBuilder::buildActor($entity)];
        }

        if ($entity instanceof Entity) {
            if (ActivityBuilder::isFederated($entity)) {
                return ['status' => 200, 'document' => ActivityBuilder::buildDocument($entity)];
            }
        }

        // The page is there for this visitor, but there is nothing to give another server
        return self::error(406);
    }

    private static function error(int $status): array
    {
        $messages = [
            403 => 'Forbidden',
            404 => 'Not found',
            406 => 'This page has no ActivityPub representation',
            410 => 'Gone',
        ];

        return ['status' => $status, 'document' => ['error' => $messages[$status] ?? 'Error']];
    }
}
