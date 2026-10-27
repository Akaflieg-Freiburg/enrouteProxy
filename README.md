# Proxy Scripts

These are proxy scripts that forward requests from **Enroute Flight Navigation**
to data servers elsewhere (but typically in the United States). The purpose of 
these scripts is that they hide the device IPs from the data servers and thus 
produce an extra layer of privacy.

The scripts are installed in our web servers, so **Enroute Flight Navigation**
knows how to find and use them.

All scripts read their credentials from environment variables, which can for
instance be set with the directive "SetEnv" in Apache's ".htaccess" files:

- `DB_USER`, `DB_PASS` — MySQL credentials (all scripts)
- `NMS_AUTH_URL`, `NMS_CLIENT_ID`, `NMS_CLIENT_SECRET`, `NMS_API_BASE` —
  FAA NOTAM Management Service (NMS) API (notam.php only)

## notam.php

Proxy for the FAA NOTAM Management Service (NMS) API. The times and limits
mentioned below are constants at the top of [notam.php](notam.php).

### Request

`notam.php?locationLongitude=<lon>&locationLatitude=<lat>&locationRadius=<radius>`

- `locationLongitude`, `locationLatitude`: decimal degrees with a dot as
  decimal separator, no exponent (e.g. `7`, `-7.25`).
- `locationRadius`: integer nautical miles, 1–100 (the FAA rejects larger
  values).

### Response

`200` with `Content-Type: application/json`:

```json
{
  "pageSize": 42,
  "pageNum": 1,
  "totalCount": 42,
  "totalPages": 1,
  "items": [ "...NMS geojson features..." ]
}
```

`items` holds the geojson features of all FAA result pages, merged into one
list. The pagination fields keep the shape of the old FAA API: `pageNum` and
`totalPages` are always 1, `pageSize` and `totalCount` are the number of
items.

The header `X-Cache-Status` is `hit` (served from the cache), `miss` (just
fetched from the FAA) or `stale` (the FAA failed, so an older cached response
was served).

### Errors

Errors come with the JSON body `{"error": "<message>"}`.

- `400`: invalid parameters; the message says which.
- `503` with `Retry-After: 120`: the FAA (data or auth endpoint) failed or
  is in a cooldown, and there is no cached response for this query.
- `500`: anything else (database, configuration). The message is generic;
  details go to the server error log.

### Caching and rate limiting

- Responses are cached in the table `notam_cache` under the key
  `notam_<md5>`, where `<md5>` is the hash of the full upstream URL. Only
  requests with identical parameter strings share a cache entry.
- A cached response is fresh for one hour; after that, the next request
  fetches a new one. The old response is served as a fallback for up to
  24 hours; older rows are ignored even before maintenance deletes them.
- Only one process per query talks to the FAA (MySQL lock
  `notamlock_<md5>`). Concurrent requests for the same query wait for it, for
  up to 20 s, then re-check the cache and the negative cache.
- At most 2 FAA requests are in flight at the same time from this server
  (MySQL locks `notam_faa_slot_<n>`), because the FAA answers further
  parallel requests with HTTP 429 (observed 2026-10). Requests for different
  queries queue for a free slot for up to 20 s; a request that gets none is
  served `stale` or `503` without the FAA having failed.
- A fetch must finish within 30 s in total (all pages) and at most 50 pages
  are read; otherwise it counts as an FAA failure. The current NMS API
  answers everything on one page and ignores `pageNum`.
- An FAA answer is only accepted if it has the NMS envelope
  `{"status": "Success", "data": {"geojson": [...]}}` (an empty result has
  no `geojson`). `"status": "Failure"`, a missing envelope or invalid JSON is
  an FAA failure, so a broken answer never replaces a good cached one.
- If an FAA request fails, a negative-cache row `nfail_<md5>` blocks further
  FAA requests for that query for two minutes. During that time the last
  response is served as `stale`, or `503` if there is none.
- The FAA rate limit (HTTP 429) is shared by all clients of our account. A
  429, or any failure to obtain a token, therefore also writes the row
  `nfail_global`, which blocks FAA requests for *all* queries: for the time
  in the FAA's `Retry-After` header if present (at most 10 minutes), else
  two minutes.
- The OAuth bearer token is cached in `nms_token_cache` (a single row with
  `id = 1`, expiry in UTC) and renewed 60 s before it expires. The token is
  only fetched when the FAA actually has to be asked, never for cache hits.
  Only one process renews it (MySQL lock `nms_token`); a process that waits
  more than 20 s for the lock gives up (FAA failure, see above) rather than
  renewing on its own. If the FAA rejects the cached token with HTTP 401, it
  is discarded, renewed once and the request retried.
- A successful FAA answer is returned to the client even if it cannot be
  written to the cache (logged).

### Setup

1. Create the tables once with [notam.sql](notam.sql). Unlike metar.php and
   taf.php, notam.php does not create its tables itself.
2. Set `DB_USER`, `DB_PASS` and the four `NMS_*` variables (see above).
3. Copy notam.php to the web server.

### Metrics and maintenance

Every `hit`, `miss` and `stale` response is counted per day in the table
`cache_metrics`. On about 1% of requests, notam.php also

- rolls daily counts older than 30 days up into `cache_metrics_monthly`
  (one transaction, so a failure cannot double count), and
- deletes cached responses older than 24 hours and expired `nfail_` rows.

Only one process runs maintenance at a time (MySQL lock `notam_maintenance`).
Failures here are logged and never affect the response. Hit rate per day:

```sql
SELECT date,
       SUM(IF(metric = 'hit', count, 0)) / SUM(count) AS hit_rate,
       SUM(count) AS responses
FROM cache_metrics
GROUP BY date
ORDER BY date DESC;
```

### Testing

There is no test suite; testing is done with curl.

- `./testRunLocal.sh`: request against a local `php -S localhost:8000`. The
  environment variables must be set, and the database must be reachable.
- `./testRunProxyDevelop.sh`: request against the develop instance at
  `https://enroute-data.akaflieg-freiburg.de/enrouteProxyDevelop/`.
- `./get_token.sh`: fetch a bearer token from the FAA auth endpoint by hand
  (uses `NMS_AUTH_URL`, `NMS_CLIENT_ID` and `NMS_CLIENT_SECRET`).

## Notes on the FAA NMS feed (observed 2026-08)

- The response envelope is `{"status": "Success", "data": {"geojson":
  [...]}}`; errors are `{"status": "Failure", "message": "..."}` with an
  HTTP 4xx/5xx. There are no pagination fields; `pageNum` and `pageSize` are
  ignored and all matching NOTAMs come in one answer (about 700 kB for a
  100 NM radius around Freiburg, observed 2026-10).

- Response ordering is nondeterministic between identical requests.
- Field names differ from the old FAA API: `affectedFir`, `minimumFl`,
  `maximumFl` (old: `affectedFIR`, `minimumFL`, `maximumFL`); both `location`
  and `icaoLocation` exist.
- `classification` ∈ {INTL, MIL, DOM, FDC, …}; `series` is present;
  `schedule` only when the NOTAM has a D) item.
- Encodings are inconsistent between old and new records: `radius` "001" vs
  "1", `estimated` "true" vs true, `effectiveEnd` may be the string "PERM".
- The geojson `geometry` point is the location's ARP for aerodrome-scope
  NOTAMs and the Q-line coordinate for FIR/W-scope NOTAMs; the 11-character
  `coordinates` string (Q-line) is always present.
- Every feature carries `notamTranslation[].formattedText` with the full ICAO
  text (Q/A/B/C/D/E/F/G lines).
- Swiss W-series NOTAMs were missing from NMS from 2026-08-19; reported to
  NOTAMS@faa.gov on 2026-08-21.

## metar.php and taf.php

Near-identical scripts (keep them in sync) that serve METARs and TAFs from
the aviationweather.gov cache files for a bounding box:

`metar.php?format=xml&bbox=minLat,minLon,maxLat,maxLon`

The answer is XML in the shape of the aviationweather.gov schema, with the
complete `<METAR>` / `<TAF>` elements of the upstream file (including nested
elements such as TAF `<forecast>` periods).

- The whole upstream file is downloaded, validated and stored per station in
  the table `metar_cache` / `taf_cache` (auto-created, InnoDB). The data is
  refreshed when it is older than 5 minutes; only one process refreshes
  (MySQL lock), the others keep serving the existing data.
- A download that is not the expected `<response><data>` document with at
  least one usable record is rejected and the existing data kept. After a
  failed refresh, no new attempt is made for 60 s. The refresh times are kept
  in `metar_cache_state` / `taf_cache_state` (computed in SQL, so the time
  zones of PHP and MySQL do not matter).
- Errors come as `<response><errors><error>…</error></errors></response>`:
  `400` for invalid parameters, `503` (with `Retry-After: 60`) if there is no
  dataset at all yet, `500` for anything else. A legitimately empty bounding
  box is a `200` with `num_results="0"`.

The scripts in this directory were kindly donated by Markus Sachs.
