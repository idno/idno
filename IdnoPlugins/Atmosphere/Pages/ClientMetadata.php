<?php

namespace IdnoPlugins\Atmosphere\Pages;

use Idno\Common\Page;

/**
 * Serves the OAuth client metadata document.
 * This must be publicly accessible at a stable URL.
 * Route: /atmosphere/client-metadata.json
 */
class ClientMetadata extends Page
{
    public function getContent()
    {
        $siteUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();

        $metadata = [
            'client_id'                      => $siteUrl . 'atmosphere/client-metadata.json',
            'client_name'                    => \Idno\Core\Idno::site()->config()->getTitle(),
            'client_uri'                     => $siteUrl,
            'redirect_uris'                  => [$siteUrl . 'account/settings/atmosphere/callback'],
            'scope'                          => 'atproto transition:generic',
            'grant_types'                    => ['authorization_code', 'refresh_token'],
            'response_types'                 => ['code'],
            'token_endpoint_auth_method'     => 'none',
            'application_type'               => 'web',
            'dpop_bound_access_tokens'       => true,
        ];

        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=3600');
        echo json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
}
