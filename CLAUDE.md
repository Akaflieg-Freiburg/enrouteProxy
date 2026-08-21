# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Proxy scripts for **Enroute Flight Navigation**. They run on the Akaflieg Freiburg web servers and forward app requests to US data servers, hiding device IPs for privacy. Plain PHP, no framework, no Composer, no test suite. Deployment is by copying the scripts to the web server; the production develop instance lives at `https://enroute-data.akaflieg-freiburg.de/enrouteProxyDevelop/`.

## Endpoints

- **metar.php** and **taf.php** — near-identical twins (keep them in sync when changing one). They download the full aviationweather.gov cache file (`metars.cache.xml.gz` / `tafs.cache.xml.gz`), store per-station rows in MySQL, and answer bounding-box queries (`?format=xml&bbox=minLat,minLon,maxLat,maxLon`) with XML matching the aviationweather.gov schema. Cache TTL is 5 minutes; tables are auto-created.
- **notam.php** — proxies the FAA NOTAM Management Service (NMS) API. Flow: obtain an OAuth client-credentials bearer token (cached in the `nms_token_cache` DB table, renewed 60 s before expiry, refreshed under a MySQL named lock) → query `NMS_API_BASE/notams` for a lat/lon/radius, following pagination (max 50 pages) and merging all pages into a single geojson response → cache the merged response in `notam_cache` (key `notam_<md5(url)>`) for 1 hour, keeping rows 24 h as a stale fallback. Only one process per query talks to the FAA (`GET_LOCK`). On FAA failure (notably HTTP 429, whose limit is shared by all clients) a negative-cache row `nfail_<md5>` suppresses FAA requests for 2 minutes, stale data is served with `X-Cache-Status: stale`, or `503` + `Retry-After` if there is none. Bad input gives `400`; other errors `500` with a generic message. Also records hit/miss/stale counts in `cache_metrics` (non-fatal) and occasionally (1% of requests) aggregates them into `cache_metrics_monthly` and purges old cache rows. Unlike metar/taf, its tables are *not* auto-created. Comments are partly in German.

All three share the MySQL database `enroutecaches` on host `sql731.your-server.de` (hardcoded).

## Configuration

All credentials come from environment variables, set in production via Apache `SetEnv` in `.htaccess` (gitignored):

- `DB_USER`, `DB_PASS` — MySQL credentials (all scripts)
- `NMS_AUTH_URL`, `NMS_CLIENT_ID`, `NMS_CLIENT_SECRET`, `NMS_API_BASE` — FAA NMS API (notam.php only)

## Running and testing

There is no test framework; testing is done with curl against a running instance:

```bash
# Serve locally (env vars above must be set), then:
php -S localhost:8000
./testRunLocal.sh            # NOTAM request against localhost:8000

./testRunProxyDevelop.sh     # NOTAM request against the deployed develop instance
./get_token.sh               # Fetch an FAA staging bearer token manually (uses NMS_CLIENT_ID/SECRET)
```

Note that local runs still need reachable MySQL credentials, since every endpoint touches the cache database.
