<?php

    /**
     * ActivityPub shell template.
     *
     * Serves ActivityPub JSON-LD when a request comes in with
     * Accept: application/activity+json  or  application/ld+json
     *
     * Handles two cases:
     *   1. User profile pages  → Person object
     *   2. Content entity pages → Note/Article object
     *
     * Uses plain arrays + json_encode so there is no dependency on the
     * ActivityPhp library. This ensures content negotiation always works.
     */


    unset($vars['body']);

    /* @var \Idno\Common\Entity $object */

    // ── Person (user profile) ──────────────────────────────────────────
    if (isset($vars['user']) && 'person' === $vars['user']?->getActivityStreamsObjectType()) {

        $actorId = $vars['user']->getActivityPubActorID();

        $person = [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
            ],
            'id'                        => $actorId,
            'type'                      => 'Person',
            'url'                       => $vars['user']->getURL(),
            'preferredUsername'          => $vars['user']->getHandle(),
            'name'                      => $vars['user']->getName(),
            'summary'                   => $vars['user']->getDescription(),
            'manuallyApprovesFollowers' => false,
            'inbox'                     => $actorId . '/inbox',
            'outbox'                    => $actorId . '/outbox',
            'followers'                 => $actorId . '/followers',
            'following'                 => $actorId . '/following',
            'publicKey'                 => $vars['user']->getPublicKey(),
            'endpoints'                 => $vars['user']->getActivityPubEndpoints(),
        ];

        $icon = $vars['user']->getIcon();
        if ($icon) {
            $mime = \Idno\Common\Entity::getMediaMimeType($icon);
            $person['icon'] = [
                'type'      => 'Image',
                'url'       => $icon,
                'mediaType' => $mime ?: 'image/png',
            ];
        }

        echo json_encode($person, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    // ── Note / Article (content entity) ────────────────────────────────
    } elseif (isset($vars['object']) && $vars['object']?->isPublic()) {

        $entity = $vars['object'];
        $owner = $entity->getOwner();

        $ccTargets = [];
        if ($owner) {
            $ccTargets[] = $owner->getActivityPubFollowersURL();
        }

        $idnoType = $entity->getActivityStreamsObjectType();
        $typeMap = [
            'note'     => 'Note',
            'article'  => 'Article',
            'image'    => 'Note',
            'event'    => 'Event',
            'place'    => 'Note',
            'bookmark' => 'Note',
            'rsvp'     => 'Note',
            'media'    => 'Note',
            'entity'   => 'Note',
        ];
        $apType = $typeMap[$idnoType] ?? 'Note';

        $note = [
            '@context'     => ['https://www.w3.org/ns/activitystreams'],
            'id'           => $entity->getUUID(),
            'type'         => $apType,
            'url'          => $entity->getURL(),
            'attributedTo' => $entity->getActivityPubActorID(),
            'to'           => $entity->getAddressedTo(),
            'cc'           => $ccTargets,
            'published'    => $entity->getPublishedTime(),
            'content'      => $entity->getFormattedContent(),
        ];

        // For images, use the title as content
        if ($idnoType === 'image') {
            $note['content'] = $entity->getTitle();
        }

        // Updated time
        if ($entity->getUpdatedTime()) {
            $note['updated'] = $entity->getUpdatedTime();
        }

        // Tags/hashtags
        $tags = $entity->getHashTagObjects();
        if (!empty($tags)) {
            $note['tag'] = $tags;
        }

        // Attachments (images, files)
        $attachments = $entity->getFormattedAttachments();
        if (!empty($attachments)) {
            $note['attachment'] = $attachments;
        }

        // Reply handling
        if ($entity->isReply() && $entity->getReplyToURLs()) {
            $replyUrls = $entity->getReplyToURLs();
            $note['inReplyTo'] = is_array($replyUrls) ? $replyUrls[0] : $replyUrls;
        }

        echo json_encode($note, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
