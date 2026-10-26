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
- `503` with `Retry-After: 120`: the FAA failed and there is no cached
  response for this query.
- `500`: anything else (database, configuration, token). The message is
  generic; details go to the server error log.

### Caching and rate limiting

- Responses are cached in the table `notam_cache` under the key
  `notam_<md5>`, where `<md5>` is the hash of the full upstream URL. Only
  requests with identical parameter strings share a cache entry.
- A cached response is fresh for one hour; after that, the next request
  fetches a new one. The old response is kept for 24 hours as a fallback.
- Only one process per query talks to the FAA (MySQL lock
  `notamlock_<md5>`). Concurrent requests for the same query wait for it, for
  up to 20 s.
- The FAA rate limit (HTTP 429) is shared by all clients of our account. If
  an FAA request fails, a negative-cache row `nfail_<md5>` blocks further FAA
  requests for that query for two minutes. During that time the last response
  is served as `stale`, or `503` if there is none.
- The OAuth bearer token is cached in `nms_token_cache` (a single row with
  `id = 1`, expiry in UTC) and renewed 60 s before it expires. Only one process
  renews it (MySQL lock `nms_token`).

### Setup

1. Create the tables once with [notam.sql](notam.sql). Unlike metar.php and
   taf.php, notam.php does not create its tables itself.
2. Set `DB_USER`, `DB_PASS` and the four `NMS_*` variables (see above).
3. Copy notam.php to the web server.

### Metrics and maintenance

Every `hit`, `miss` and `stale` response is counted per day in the table
`cache_metrics`. On about 1% of requests, notam.php also

- rolls daily counts older than 30 days up into `cache_metrics_monthly`, and
- deletes cached responses older than 24 hours and expired `nfail_` rows.

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
- `./get_token.sh`: fetch a bearer token from the FAA staging auth endpoint by
  hand (uses `NMS_CLIENT_ID` and `NMS_CLIENT_SECRET`).

## Notes on the FAA NMS feed (observed 2026-08)

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

The scripts in this directory were kindly donated by Markus Sachs.
