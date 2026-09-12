# Hypertube — Torrent & Streaming

A video-on-demand app that finds movies across web-hosted torrent indexes, downloads them through a Python/libtorrent worker, and streams them back to the browser during and after the download.

## Language

### Search

**Movie** (search result):
A single entry in search results, grouping every candidate — across all search sources — that resolves to the same title+year identity. Exists only during search; becomes a download once a candidate is chosen.
_Avoid_: result, item

**Candidate**:
One specific torrent link found for a movie, from one search source. A movie groups several candidates; only one is actively downloaded at a time, the rest are kept as fallback.
_Avoid_: torrent, link, option

**Search source**:
One of the movie-search providers (archive.org, PublicDomainTorrents.info) a candidate comes from.
_Avoid_: provider, source (ambiguous with download method, below)

**Health check**:
Fetching and tracker-announcing a candidate's `.torrent` file to confirm it has reachable seeders/peers, done before a candidate is attempted for download.
_Avoid_: verify, validate, ping

### Download

**Cached download**:
An already-completed download for the same movie (matched by normalized title), reused for a new request instead of starting a fresh download.
_Avoid_: existing download, duplicate

**Download method** (the `source` field on a download):
How a file was actually fetched — `torrent` (via BitTorrent) or `url` (direct HTTP download) — plus, once shown, the exact link used. Distinct from the search source that found the candidate.
_Avoid_: provenance, source (without saying which one you mean)

**Downloaded bytes**:
The count of bytes contiguous from the very start of the file that the worker has verified via libtorrent's piece hashes — not the naive total of all bytes fetched. Sequential downloading can finish a later piece before an earlier one, leaving a hole; this figure is the exact bound the streaming endpoint honors.
_Avoid_: chunk, progress

**Priority tier**:
Of the two groups a queued download falls into when a concurrency slot frees: jobs someone has actively re-requested in the last few minutes (promoted first, oldest-first among themselves), and everything else (oldest-first). Exists to stop an old, unrequested job waiting forever behind a stream of fresh requests for other movies.
_Avoid_: priority, urgent

### Playback & processing

**Playable**:
Whether a download's file is currently safe to start playing in the browser. For a file that needs processing, this requires the download to be complete AND that processing to have finished — not merely "some bytes exist."
_Avoid_: ready, watchable, streamable

**Remux**:
A fast, lossless container fix-up (moving the `moov` atom to the front of an mp4) with no re-encoding — as opposed to a transcode. Takes seconds.
_Avoid_: convert, process, optimize

**Transcode**:
A full ffmpeg re-encode of a video into a browser-playable format, for containers/codecs a browser can't play natively (e.g. avi). Slower than a remux; the only processing step shown to users as "optimisation."
_Avoid_: convert, optimize, process
