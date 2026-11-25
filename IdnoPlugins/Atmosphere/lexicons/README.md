# Idno's lexicons

The schemas of the AT Protocol records that Idno defines itself, in the `co.idno.feed`
namespace. A member's status updates, videos and audio are written to their PDS as
these records (see `FeedRecord.php`). They are not Bluesky records, so nothing written
with them shows up in Bluesky.

| File | Record |
|---|---|
| `co.idno.feed.status.json` | A status update |
| `co.idno.feed.video.json` | A video, by its address |
| `co.idno.feed.audio.json` | An audio file, by its address |

## Publishing them

A PDS stores records of a lexicon it does not know without checking them, so the plugin
works before these are published. Publishing lets other software find the schemas
(<https://atproto.com/specs/lexicon#lexicon-publication-and-resolution>):

1. Choose the account that will hold them, and note its DID.
2. Add the DNS record `_lexicon.feed.idno.co  TXT  "did=<that DID>"`. The name is the
   namespace (`co.idno.feed`) reversed; one record covers the three schemas.
3. In that account's repository, write each file as a record of the collection
   `com.atproto.lexicon.schema`, with the NSID (`co.idno.feed.status`, ...) as the record
   key and `"$type": "com.atproto.lexicon.schema"` added to the file's content.

## Changing them

Once published, a lexicon can only grow: new fields must be optional, and no field may
be removed, renamed, made required or given a tighter limit
(<https://atproto.com/specs/lexicon#lexicon-evolution>). A change that breaks those rules
needs a new name. Read the three files with that in mind before publishing them.
