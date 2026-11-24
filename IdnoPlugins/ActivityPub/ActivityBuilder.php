<?php

namespace IdnoPlugins\ActivityPub;

use Idno\Common\Entity;
use Idno\Entities\User;

/**
 * Builds ActivityPub activity objects for outbound federation.
 * Uses plain arrays rather than the ActivityPhp library for full control over outgoing activities.
 */
class ActivityBuilder
{

    const CONTEXT = [
        'https://www.w3.org/ns/activitystreams',
        'https://w3id.org/security/v1',
    ];

    /**
     * Extended context including GoToSocial namespace for interaction policies
     * and FEP-044f terms for quote post support.
     */
    const CONTEXT_WITH_QUOTES = [
        'https://www.w3.org/ns/activitystreams',
        'https://w3id.org/security/v1',
        [
            'gts'                  => 'https://gotosocial.org/ns#',
            'interactionPolicy'    => ['@id' => 'gts:interactionPolicy', '@type' => '@id'],
            'canQuote'             => ['@id' => 'gts:canQuote', '@type' => '@id'],
            'automaticApproval'    => ['@id' => 'gts:automaticApproval', '@type' => '@id'],
            'QuoteAuthorization'   => 'https://w3id.org/fep/044f#QuoteAuthorization',
            'QuoteRequest'         => 'https://w3id.org/fep/044f#QuoteRequest',
            'interactingObject'    => ['@id' => 'gts:interactingObject', '@type' => '@id'],
            'interactionTarget'    => ['@id' => 'gts:interactionTarget', '@type' => '@id'],
        ],
    ];

    const PUBLIC_AUDIENCE = 'https://www.w3.org/ns/activitystreams#Public';

    /**
     * Map of Idno activity streams types to ActivityPub object types.
     */
    const TYPE_MAP = [
        'note'     => 'Note',
        'article'  => 'Article',
        'image'    => 'Note',  // Images federate as Notes with attachments
        'event'    => 'Event',
        'place'    => 'Note',  // Checkins federate as Notes with location
        'bookmark' => 'Note',
        'rsvp'     => 'Note',
        'media'    => 'Note',
        'video'    => 'Note',  // Linked videos federate as Notes with a link
        'entity'   => 'Note',
    ];

    /**
     * Entity subtypes that are not publishable content and should be excluded
     * from outbox counts and content queries.
     * Uses '!' prefix for DB-level exclusion via Entity::countFromX/getFromX.
     */
    const NON_CONTENT_SUBTYPES = [
        '!Idno\\Entities\\User',
        '!IdnoPlugins\\ActivityPub\\Entities\\ActivityPubFollower',
        '!Idno\\Entities\\AsynchronousQueuedEvent',
    ];

    /**
     * Convert an Idno entity to an ActivityPub object (Note, Article, etc.).
     *
     * @param Entity $entity The Idno entity
     * @return array ActivityPub object
     */
    public static function buildObject(Entity $entity): array
    {
        $idnoType = $entity->getActivityStreamsObjectType();
        $apType = self::TYPE_MAP[$idnoType] ?? 'Note';
        $owner = $entity->getOwner();

        $object = [
            'id'           => $entity->getUUID(),
            'type'         => $apType,
            'url'          => $entity->getURL(),
            'attributedTo' => $entity->getActivityPubActorID(),
            'published'    => date(\DateTime::RFC3339, $entity->created),
            'to'           => $entity->getAddressedTo(),
            'cc'           => [],
        ];

        // Add followers collection to cc for public content
        if ($owner && $entity->isPublic()) {
            $object['cc'][] = $owner->getActivityPubFollowersURL();
        }

        // Content handling varies by type
        if ($apType === 'Article') {
            $object['name'] = $entity->getTitle();
            $object['content'] = $entity->getFormattedContent();
        } elseif ($idnoType === 'image') {
            // For images, use the title as content and attach the image
            $object['content'] = $entity->getTitle();
        } else {
            $object['content'] = $entity->getFormattedContent();
        }

        // Updated time
        if ($entity->updated && $entity->updated !== $entity->created) {
            $object['updated'] = date(\DateTime::RFC3339, $entity->updated);
        }

        // Tags/hashtags
        $tags = $entity->getHashTagObjects();
        if (!empty($tags)) {
            $object['tag'] = $tags;
        }

        // Attachments (images, files)
        $attachments = $entity->getFormattedAttachments();
        if (!empty($attachments)) {
            $object['attachment'] = $attachments;
        }

        // Reply handling
        if ($entity->isReply()) {
            $replyUrls = $entity->getReplyToURLs();
            if (!empty($replyUrls)) {
                $object['inReplyTo'] = is_array($replyUrls) ? $replyUrls[0] : $replyUrls;
            }
        }

        // Interaction policy: allow anyone to quote (FEP-044f)
        $object['interactionPolicy'] = [
            'canQuote' => [
                'automaticApproval' => [self::PUBLIC_AUDIENCE],
            ],
        ];

        // Location data (for checkins)
        if ($idnoType === 'place' || !empty($entity->lat)) {
            if (!empty($entity->lat) && !empty($entity->long)) {
                $location = [
                    'type' => 'Place',
                ];
                if (!empty($entity->placename)) {
                    $location['name'] = $entity->placename;
                }
                $location['latitude'] = (float)$entity->lat;
                $location['longitude'] = (float)$entity->long;
                if (!empty($entity->address)) {
                    $location['name'] = $location['name'] ?? $entity->address;
                }
                $object['location'] = $location;
            }
        }

        return $object;
    }

    /**
     * Build a Create activity wrapping an entity.
     *
     * @param Entity $entity The entity being created
     * @return array ActivityPub Create activity
     */
    public static function buildCreate(Entity $entity): array
    {
        $object = self::buildObject($entity);
        $owner = $entity->getOwner();

        return self::wrapActivity('Create', $owner, $object, $entity->getUUID() . '#create');
    }

    /**
     * Build an Update activity wrapping an entity.
     *
     * @param Entity $entity The entity being updated
     * @return array ActivityPub Update activity
     */
    public static function buildUpdate(Entity $entity): array
    {
        $object = self::buildObject($entity);
        $owner = $entity->getOwner();

        return self::wrapActivity('Update', $owner, $object, $entity->getUUID() . '#update-' . time());
    }

    /**
     * Build a Delete activity for an entity.
     *
     * @param Entity $entity The entity being deleted
     * @return array ActivityPub Delete activity with Tombstone object
     */
    public static function buildDelete(Entity $entity): array
    {
        $idnoType = $entity->getActivityStreamsObjectType();
        $apType = self::TYPE_MAP[$idnoType] ?? 'Note';
        $owner = $entity->getOwner();

        $tombstone = [
            'id'         => $entity->getUUID(),
            'type'       => 'Tombstone',
            'formerType' => $apType,
        ];

        $activity = self::wrapActivity('Delete', $owner, $tombstone, $entity->getUUID() . '#delete');

        // Deletions should be addressed to public + followers
        if ($owner) {
            $activity['to'] = [self::PUBLIC_AUDIENCE];
            $activity['cc'] = [$owner->getActivityPubFollowersURL()];
        }

        return $activity;
    }

    /**
     * Build an Accept activity in response to a Follow.
     *
     * @param array $followActivity The original Follow activity being accepted
     * @param User  $user           The local user accepting the follow
     * @return array ActivityPub Accept activity
     */
    public static function buildAccept(array $followActivity, User $user): array
    {
        $actorId = $user->getActivityPubActorID();

        // Build a minimal embedded Follow object containing only the standard
        // ActivityPub properties.  The original Follow from the remote server
        // may include implementation-specific fields whose short names are
        // defined in contexts we don't carry.  Echoing those fields back
        // without their original context causes strict JSON-LD processors
        // (e.g. Fedify, used by Ghost) to fail deserialization.  Keeping
        // only id/type/actor/object ensures every term resolves under the
        // Accept's own ActivityStreams context.
        $followObject = [
            'id'     => $followActivity['id'] ?? '',
            'type'   => $followActivity['type'] ?? 'Follow',
            'actor'  => is_string($followActivity['actor'] ?? null)
                            ? $followActivity['actor']
                            : ($followActivity['actor']['id'] ?? ''),
            'object' => is_string($followActivity['object'] ?? null)
                            ? $followActivity['object']
                            : ($followActivity['object']['id'] ?? ''),
        ];

        $followerActor = $followObject['actor'];

        $accept = [
            // Use only the ActivityStreams context — the security/v1 context
            // is not needed for Accept activities and adding unnecessary
            // contexts can cause issues with strict JSON-LD processors.
            '@context'  => 'https://www.w3.org/ns/activitystreams',
            'id'        => $actorId . '#accept-' . md5($followActivity['id'] ?? time()),
            'type'      => 'Accept',
            'actor'     => $actorId,
            'object'    => $followObject,
        ];

        // Address the Accept to the remote actor who sent the Follow.
        // Use an array for the `to` field per ActivityStreams convention —
        // while JSON-LD normalizes strings and arrays equivalently, some
        // implementations pre-process addressing fields expecting arrays.
        if (!empty($followerActor)) {
            $accept['to'] = [$followerActor];
        }

        return $accept;
    }

    /**
     * Build an Accept activity in response to a QuoteRequest (FEP-044f).
     *
     * @param array  $quoteRequest The original QuoteRequest activity
     * @param User   $user         The local user whose post is being quoted
     * @param string $stampUrl     The QuoteAuthorization stamp URL
     * @return array ActivityPub Accept activity
     */
    public static function buildQuoteAccept(array $quoteRequest, User $user, string $stampUrl): array
    {
        $actorId = $user->getActivityPubActorID();

        return [
            '@context'  => self::CONTEXT_WITH_QUOTES,
            'id'        => $actorId . '#quote-accept-' . md5($quoteRequest['id'] ?? time()),
            'type'      => 'Accept',
            'actor'     => $actorId,
            'to'        => $quoteRequest['actor'] ?? '',
            'object'    => $quoteRequest,
            'result'    => $stampUrl,
            'published' => date(\DateTime::RFC3339),
        ];
    }

    /**
     * Generate a QuoteAuthorization stamp URL for a quote approval (FEP-044f).
     * Uses HMAC to produce deterministic, unforgeable stamp URLs without database storage.
     *
     * @param string $interactionTarget The URI of the quoted post
     * @param string $interactingObject The URI of the quoting post
     * @return string The stamp URL
     */
    public static function buildQuoteStampUrl(string $interactionTarget, string $interactingObject): string
    {
        $siteUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();
        $secret = \Idno\Core\Idno::site()->config()->site_secret ?? \Idno\Core\Idno::site()->config()->getDisplayURL();
        $sig = hash_hmac('sha256', $interactionTarget . "\n" . $interactingObject, $secret);

        return $siteUrl . 'activitypub/quote-stamp'
            . '?target=' . urlencode($interactionTarget)
            . '&object=' . urlencode($interactingObject)
            . '&sig=' . $sig;
    }

    /**
     * Wrap an object in an activity.
     *
     * @param string    $type   Activity type (Create, Update, Delete, etc.)
     * @param User|null $actor  The actor performing the activity
     * @param array     $object The object of the activity
     * @param string    $id     Activity ID
     * @return array ActivityPub activity
     */
    public static function wrapActivity(string $type, ?User $actor, array $object, string $id = ''): array
    {
        $actorId = $actor ? $actor->getActivityPubActorID() : '';

        $activity = [
            '@context'  => self::CONTEXT_WITH_QUOTES,
            'id'        => $id ?: ($actorId . '#activity-' . md5(time() . mt_rand())),
            'type'      => $type,
            'actor'     => $actorId,
            'published' => date(\DateTime::RFC3339),
            'object'    => $object,
        ];

        // Inherit addressing from object if available
        if (!empty($object['to'])) {
            $activity['to'] = $object['to'];
        }
        if (!empty($object['cc'])) {
            $activity['cc'] = $object['cc'];
        }

        return $activity;
    }
}
