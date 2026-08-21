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

## notam.php behaviour

- Parameters: `locationLongitude`, `locationLatitude` (decimal degrees) and
  `locationRadius` (integer nautical miles, 1–100; the FAA rejects larger
  values).
- All FAA result pages are merged into one JSON response and cached for one
  hour per distinct query. Only one process per query talks to the FAA;
  concurrent requests wait for it.
- The FAA rate limit (HTTP 429) is shared by all clients of our account. If
  the FAA fails, the last response (up to 24 h old) is served with header
  `X-Cache-Status: stale`, and no further FAA request is made for that query
  for two minutes. Without any cached data the answer is `503` with a
  `Retry-After` header.
- Invalid parameters give `400`; everything else `500` with a generic message
  (details go to the server error log).

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
