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
