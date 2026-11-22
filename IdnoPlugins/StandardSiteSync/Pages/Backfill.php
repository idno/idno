<?php

namespace IdnoPlugins\StandardSiteSync\Pages;

use Idno\Common\Page;
use Idno\Common\Entity;
use IdnoPlugins\StandardSiteSync\ATProtoClient;
use IdnoPlugins\StandardSiteSync\Main;

/**
 * Backfill the current user's content to their AT Protocol PDS.
 * Route: /account/settings/standardsitesync/backfill
 */
class Backfill extends Page
{
    public function postContent()
    {
        $this->gatekeeper();

        $user = \Idno\Core\Idno::site()->session()->currentUser();
        $session = Main::getATProtoSessionForUser($user);
        $settingsUrl = \Idno\Core\Idno::site()->config()->getDisplayURL() . 'account/settings/standardsitesync/';

        if (empty($session['access_token']) || empty($session['did'])) {
            \Idno\Core\Idno::site()->session()->addErrorMessage('Not connected to an AT Protocol PDS.');
            $this->forward($settingsUrl);
            return;
        }

        // Ensure the publication record exists
        try {
            $client = new ATProtoClient($session);
            $client->putPublication();
            Main::saveATProtoSessionForUser($user, $client->getSession());
        } catch (\Exception $e) {
            \Idno\Core\Idno::site()->logging()->error(
                'StandardSiteSync: Failed to create publication for backfill: ' . $e->getMessage()
            );
            \Idno\Core\Idno::site()->session()->addErrorMessage(
                'Failed to create publication record: ' . \Idno\Core\ExceptionMessage::forVisitor($e)
            );
            $this->forward($settingsUrl);
            return;
        }

        // Fetch this user's published, public content entities
        $entities = Entity::get(
            [
                'publish_status' => 'published',
                'owner'          => $user->getUUID(),
            ],
            [],
            PHP_INT_MAX,
            0
        );

        if (empty($entities)) {
            \Idno\Core\Idno::site()->session()->addMessage('No content found to backfill.');
            $this->forward($settingsUrl);
            return;
        }

        $queue = \Idno\Core\Idno::site()->queue();
        $useAsync = $queue->isAsynchronous();
        $count = 0;

        foreach ($entities as $entity) {
            // Skip non-content entities
            if ($entity instanceof \Idno\Entities\User) {
                continue;
            }
            if ($entity instanceof \Idno\Entities\AsynchronousQueuedEvent) {
                continue;
            }

            $type = $entity->getActivityStreamsObjectType();
            if (empty($type) || $type === 'entity') {
                continue;
            }

            // Only sync public content
            if (method_exists($entity, 'isPublic') && !$entity->isPublic()) {
                continue;
            }

            if ($useAsync) {
                $queue->enqueue('default', 'standardsitesync/backfill', [
                    'entity_id' => $entity->getID(),
                ], $user->getUUID());
            } else {
                // Synchronous fallback: sync directly
                try {
                    $latestSession = Main::getATProtoSessionForUser($user);
                    $client = new ATProtoClient($latestSession);

                    if (!$client->documentExists($entity)) {
                        $client->putDocument($entity);
                        Main::saveATProtoSessionForUser($user, $client->getSession());
                    }
                } catch (\Exception $e) {
                    \Idno\Core\Idno::site()->logging()->error(
                        'StandardSiteSync: Backfill error for entity ' . $entity->getID() . ': ' . $e->getMessage()
                    );
                }
            }

            $count++;
        }

        if ($useAsync) {
            \Idno\Core\Idno::site()->session()->addMessage(
                'Backfill started: ' . $count . ' posts queued for sync to your PDS. They will be processed in the background.'
            );
        } else {
            \Idno\Core\Idno::site()->session()->addMessage(
                'Backfill complete: ' . $count . ' posts processed.'
            );
        }

        $this->forward($settingsUrl);
    }
}
