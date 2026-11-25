# Lexicon files

Copies of the published AT Protocol schema files that this plugin's records and requests
are checked against in `PostRecordTest` and `PostSyncTest` (through `LexiconValidator`).
They are not changed here. To update them, fetch the same paths at a newer commit and
note the commit below.

| Files | Source | Commit |
|---|---|---|
| `app.bsky.*.json` | <https://github.com/bluesky-social/bsky>, `lexicons/app/bsky/...` (the canonical source of the `app.bsky` lexicons, according to `lexicons/README.md` in `bluesky-social/atproto`) | `d92c6fe74100d33b5250f076b767e6bb8cea9026` (fetched 2026-11-24) |
| `com.atproto.*.json` | <https://github.com/bluesky-social/atproto>, `lexicons/com/atproto/...` | `b642ec489196aa0c934b20c061de784345e71d3c` (fetched 2026-11-24) |

`app.bsky.feed.post` is the record that is written. The others are what it refers to
(facets, the embeds of its `embed` union, `com.atproto.repo.strongRef`,
`com.atproto.label.defs`) and the procedures that are called (`putRecord`,
`deleteRecord`, `uploadBlob`).

| Files | Source | Version |
|---|---|---|
| `live.voxport.podcast.episode.json` | the published schema record `at://did:plc:hhtgwctzcuhlkqrxu526sco5/com.atproto.lexicon.schema/live.voxport.podcast.episode` (the DID that `_lexicon.podcast.voxport.live` names), read with `com.atproto.repo.listRecords` from its PDS | CID `bafyreibtvk5eds3knox6aw4bsxt4a5x7hldl4a3dz7d3e2gljgdx5fymoy` (fetched 2026-11-24) |
| `live.voxport.podcast.series.json` | the same repository, `.../live.voxport.podcast.series` | CID `bafyreig4ztipi5hy4idap6dn6d5qzk3az6r77rd5mlausl6ebyks2zp7dy` (fetched 2026-11-24) |

These are VoxPort's podcast lexicons: an audio post is written as an episode of the
member's series, beside its Bluesky post.
