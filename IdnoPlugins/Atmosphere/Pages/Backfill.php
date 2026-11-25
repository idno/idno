<?php

namespace IdnoPlugins\Atmosphere\Pages;

use Idno\Common\Page;
use Idno\Common\Entity;
use IdnoPlugins\Atmosphere\ATProtoClient;
use IdnoPlugins\Atmosphere\Main;

/**
 * Backfill the current user's content to their AT Protocol PDS.
 * Route: /account/settings/atmosphere/backfill
 */
class Backfill extends Page
{
    public function postContent()
    {
        $this->gatekeeper();

        $user = \Idno\Core\Idno::site()->session()->currentUser();
        $session = Main::getATProtoSessionForUser($user);
        $settingsUrl = \Idno\Core\Idno::site()->config()->getDisplayURL() . 'account/settings/atmosphere/';

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
                'Atmosphere: Failed to create publication for backfill: ' . $e->getMessage()
            );
            \Idno\Core\Idno::site()->session()->addErrorMessage(
                'Failed to create publication record: ' . $e->getMessage()
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
        $useAsync = ($queue instanceof \Idno\Core\AsynchronousQueue);
        $count = 0;

        foreach ($entities as $entity) {
            // Only posts that are public and published are ever written to a PDS
            if (!Main::shouldSync($entity)) {
                continue;
            }

            if ($useAsync) {
                $queue->enqueue('default', 'atmosphere/backfill', [
                    'entity_id' => $entity->getID(),
                ], $user->getUUID());
            } else {
                // Synchronous fallback: sync directly. A post that has its record is left alone.
                Main::syncEntity($entity, true);
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
