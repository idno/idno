<?php

namespace IdnoPlugins\Atmosphere;

/**
 * Puts a record under its key without undoing what the member did on their PDS.
 */
class RecordWriter
{
    /**
     * Create a record, or replace the version this site wrote last.
     *
     * @param  ATProtoClient $client
     * @param  string        $collection
     * @param  string        $rkey
     * @param  array         $record
     * @param  string|null   $known The CID of the version this site wrote last, when it wrote one
     * @return string|null The CID of the record; null when the record this site wrote is no longer on
     *                     the PDS (the member deleted it there), in which case nothing is written
     */
    public static function put(ATProtoClient $client, string $collection, string $rkey, array $record, ?string $known): ?string
    {
        if ($known === null) {
            $result = $client->putRecord($collection, $rkey, $record);
        } else {
            try {
                // Only replace the version this site wrote
                $result = $client->putRecord($collection, $rkey, $record, $known);
            } catch (XrpcException $e) {
                if ($e->getStatus() !== 400 && $e->getStatus() !== 409) {
                    throw $e;
                }
                if ($client->getRecord($collection, $rkey) === null) {
                    return null;
                }
                // It was changed somewhere else; this site has the post, so its version is written
                $result = $client->putRecord($collection, $rkey, $record);
            }
        }

        $cid = $result['cid'] ?? null;
        if (!is_string($cid) || !preg_match(PostRecord::CID_PATTERN, $cid)) {
            throw new \Idno\Exceptions\TechnicalException('The PDS did not answer a write with the CID of the record');
        }

        return $cid;
    }
}
