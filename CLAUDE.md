# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Proxy scripts for **Enroute Flight Navigation**. They run on the Akaflieg Freiburg web servers and forward app requests to US data servers, hiding device IPs for privacy. Plain PHP, no framework, no Composer, no test suite. Deployment is by copying the scripts to the web server; the production develop instance lives at `https://enroute-data.akaflieg-freiburg.de/enrouteProxyDevelop/`.

## Endpoints

- **metar.php** and **taf.php** — near-identical twins (keep them in sync when changing one). They download the full aviationweather.gov cache file (`metars.cache.xml.gz` / `tafs.cache.xml.gz`), store per-station rows in MySQL, and answer bounding-box queries (`?format=xml&bbox=minLat,minLon,maxLat,maxLon`) with XML matching the aviationweather.gov schema (stored `asXML()` strings are spliced into the output verbatim). Cache TTL is 5 minutes with a 60 s backoff after failed refreshes, tracked in `*_cache_state`; tables are auto-created.
- **notam.php** — proxies the FAA NOTAM Management Service (NMS) API: OAuth bearer token cached in `nms_token_cache`, `NMS_API_BASE/notams` queries (validated envelope, pages merged into one geojson response), cached in `notam_cache` with a stale fallback (max 24 h), a per-query negative cache, a global cooldown row `nfail_global` after HTTP 429 or token failures, a per-query `GET_LOCK`, and a 2-slot semaphore of named locks limiting parallel FAA requests, to protect the FAA rate limit (HTTP 429, shared by all clients; the FAA tolerates only 2 concurrent requests from our server). The token is only fetched when the FAA must actually be asked. Request/response format, error codes, caching and metrics are documented in README.md (keep it in sync when changing behaviour); the schema is in notam.sql, since unlike metar/taf the tables are *not* auto-created.

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
./get_token.sh               # Fetch an FAA bearer token manually (uses NMS_AUTH_URL, NMS_CLIENT_ID/SECRET)
```

Note that local runs still need reachable MySQL credentials, since every endpoint touches the cache database.
