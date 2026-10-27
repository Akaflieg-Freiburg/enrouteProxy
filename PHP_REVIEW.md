# PHP review findings

Reviewed: 2026-10-27. Scope: `metar.php`, `taf.php`, and `notam.php`, with `notam.sql` and `README.md` as supporting sources.

This records a review, not implemented fixes. Line references describe the files at review time. All three PHP files passed `php -l`; the findings below concern runtime behavior and correctness.

## Summary and priorities

The highest-priority problems are:

1. TAF response construction drops nested forecast data.
2. Malformed or truncated FAA results can become complete-looking, successful NOTAM responses and be cached for an hour.
3. Structurally invalid weather feeds can replace a usable cache with an empty dataset.
4. NOTAM locking and authentication paths undermine stale-cache availability and rate-limit protection.

Recommended repair order: XML preservation and upstream validation; NOTAM cache/authentication ordering and locking; maximum stale age; input validation, time handling, retry backoff, and metrics maintenance.

## High-priority data correctness findings

### 1. Weather response construction destroys nested XML content

- **Locations:** `taf.php:291–302`; `metar.php:291–302`.
- **Evidence:** code inspection and the published upstream XML schemas. The local PHP installation lacks SimpleXML, so this path was not executed locally.

The response builders copy immediate children using `addChild()` with `(string)$child`, then copy their attributes. Casting a `SimpleXMLElement` to a string does not serialize its child elements.

For TAF, the actual structured forecast is nested inside `<forecast>`:

```xml
<forecast>
  <fcst_time_from>...</fcst_time_from>
  <fcst_time_to>...</fcst_time_to>
  <wind_speed_kt>15</wind_speed_kt>
  <sky_condition sky_cover="BKN" cloud_base_ft_agl="1500"/>
</forecast>
```

The proxy emits an empty or whitespace-only forecast element instead. Forecast periods, winds, visibility, clouds, icing, turbulence, and temperature subelements are lost. `raw_text` survives, but the structured forecast does not.

METAR has the same defect for nested `quality_control_flags`. Immediate attribute-only elements such as METAR `sky_condition` survive.

**Recommendation:** copy entire cached METAR/TAF subtrees into the response. Use a deep DOM import if DOM is available, or a complete recursive XML copy. Verify nested elements, repeated elements, attributes, and escaped text.

### 2. Malformed FAA envelopes become successful empty or partial NOTAM results

- **Location:** `notam.php:405–418`.
- **Evidence:** reproduced with loopback HTTP fixtures calling the actual function definitions.

The parser only requires decoded JSON to be an array. Missing or malformed `data.geojson` is silently ignored; missing `totalPages` terminates pagination successfully.

| Upstream fixture | Returned result |
| --- | --- |
| HTTP 200 with `{"message":"Unexpected response envelope"}` | Successful response with zero items |
| Valid first page, then HTTP 200 with `{"error":"upstream application failure"}` | Successful response containing only page one |

`getCachedOrFreshData()` caches such results as fresh for an hour, potentially replacing a usable stale result with incorrect empty or partial data.

**Recommendation:** validate each page's envelope, feature-list shape, and pagination fields. Distinguish an explicitly valid empty result from a missing payload. Throw `FaaApiException` on malformed pages to use the stale/error path.

### 3. The pagination limit silently returns incomplete NOTAM data as complete

- **Location:** `notam.php:393–438`.
- **Evidence:** reproduced with a fixture advertising 51 pages and one item per page.

At `MAX_PAGES`, the code logs truncation but returns success. The reproduction returned 50 items with `pageNum = 1`, `totalPages = 1`, and `totalCount = 50`, without indicating omitted upstream pages. This result is eligible for the normal response cache.

**Recommendation:** keep the limit, but throw an upstream exception if it is reached before the final page. Do not cache partial results as complete responses.

### 4. A structurally invalid weather feed can erase the cache

- **Locations:** `metar.php:113–144`; `taf.php:113–144`.
- **Evidence:** code inspection; not executed locally because SimpleXML is unavailable.

`updateDatabase()` checks XML well-formedness through `SimpleXMLElement`, but does not validate the expected envelope or station records before deleting existing data. A well-formed document with no expected records can delete the dataset, iterate over no records, and commit an empty cache.

The transaction protects against insertion exceptions, but an empty iteration is not a failure. Station IDs and coordinates also are not validated before insertion; missing coordinates are cast to floats.

**Recommendation:** validate the envelope and records before deletion. Define a policy for an unexpectedly empty global dataset. Commit only validated replacements.

## NOTAM caching, locking, and authentication

### 5. Lock waiters do not recheck the negative cache

- **Location:** `notam.php:215–235`.
- **Evidence:** reproduced with mocked PDO state changing during lock acquisition.

The negative cache is checked before acquiring the query lock. Only the successful response cache is checked again afterward.

Failure sequence:

1. A and B both observe no negative-cache entry.
2. A obtains the lock; B waits.
3. A receives HTTP 429, writes the negative-cache entry, and releases the lock.
4. B acquires the lock and calls FAA without checking the new failure entry.

The reproduction observed one negative-cache read and an upstream request despite a negative-cache row appearing while waiting.

**Recommendation:** recheck both successful and negative cache entries inside the lock.

### 6. Token acquisition precedes every response-cache decision

- **Location:** `notam.php:501–512`.
- **Evidence:** control-flow inspection.

The handler calls `getToken()` before `getCachedOrFreshData()`. If renewal fails, neither fresh nor stale cached responses can be served, and an existing negative-cache entry cannot prevent the authentication attempt. The outer handler returns HTTP 500.

**Recommendation:** acquire a token only when an upstream data fetch is necessary. Route transient authentication failures through stale fallback, while retaining appropriate separate handling for configuration errors.

### 7. Token renewal proceeds without obtaining its lock

- **Location:** `notam.php:275–286`.
- **Evidence:** reproduced with `GET_LOCK` returning `0`; the function still contacted the authentication fixture and returned a new token.

When lock acquisition fails, execution still reaches `getTokenFromFaa()`. Timed-out waiters can renew concurrently and overwrite the shared token row, contrary to the documented single-renewer behavior.

**Recommendation:** re-read the token after failing to acquire the lock, use a suitable cached token if available, and otherwise take an explicit failure/fallback path. Do not renew without coordination.

### 8. HTTP 401 does not invalidate or renew a rejected token

- **Locations:** `notam.php:267–271`, `notam.php:234–239`.
- **Evidence:** an isolated 401 fixture produced a negative-cache write and stale response, with no token-cache operation in the fetch path.

Cached token validity depends only on the stored expiry. An early-rejected token keeps being used after each negative-cache interval until scheduled renewal.

**Recommendation:** handle invalid-token responses with one coordinated renewal and one bounded retry. Invalidate only if the rejected token is still the cached token, so delayed failures cannot invalidate a newer token.

### 9. The 24-hour stale limit is not enforced when serving data

- **Locations:** `notam.php:169–178`, `notam.php:254–258`.
- **Evidence:** inspection and a mocked stale-row fixture accepted by the serving path. No real database-age test was performed.

`readCache()` returns a value and a freshness flag, with no maximum-age check. `serveStaleOrFail()` accepts any cached row. The 24-hour limit depends entirely on probabilistic maintenance, so older rows remain eligible under low traffic or maintenance failure. Cleanup also cannot invalidate a row already loaded by the current request.

**Recommendation:** enforce maximum usable age in the read/serve path. Treat cleanup as storage maintenance rather than an eligibility check.

### 10. Monthly metric aggregation can double-count

- **Location:** `notam.php:127–140`.
- **Evidence:** SQL and concurrency analysis; not tested against MySQL.

Adding old daily totals to monthly totals and deleting the daily rows are separate autocommitted operations. If aggregation succeeds but deletion fails, a later run adds the same totals again. Concurrent maintenance runs can also aggregate the same rows before either deletes them.

**Recommendation:** make rollup atomic and serialize competing runs, or make aggregation idempotent. A transaction needs an appropriate locking strategy to prevent overlapping readers from adding identical totals.

### 11. Redirected responses are classified using the initial status

- **Location:** `notam.php:65–73`; also used by authentication.
- **Evidence:** a loopback redirect followed by valid HTTP 200 JSON was fetched, but the function threw `Failed to get data from FAA API. Status code: 302`.

PHP follows redirects by default and exposes the redirect chain's headers. `extractHttpStatusCode()` reads only the first status line.

**Recommendation:** define an explicit redirect policy. If redirects are supported, evaluate the final response status. If prohibited, disable following them.

## Weather validation and cache behavior

### 12. Bounding-box validation accepts malformed numbers and crashes on arrays

- **Locations:** both weather files, `:229–264`.
- **Evidence:** actual endpoint inclusion reproduced array-valued input failure; isolated evaluation of the validation expression reproduced numeric coercion.

`?format=xml&bbox[]=45,5,55,15` passes an array to `explode()` and throws `TypeError` before the `try` block. The later `catch (Exception)` would not catch `TypeError` either.

Conversion with `floatval()` also destroys evidence of invalid input before validation:

| Input | Converted coordinates | Accepted |
| --- | --- | --- |
| `abc,def,ghi,jkl` | `0,0,0,0` | Yes |
| `45junk,5,55,15` | `45,5,55,15` | Yes |
| `,,,` | `0,0,0,0` | Yes |

**Recommendation:** require a scalar string, exactly four components, and valid finite numeric values before conversion. Then check ranges and ordering. Return a controlled client error for invalid input.

### 13. Weather cache age assumes matching PHP and database time zones

- **Locations:** both weather files, `:94–104`.
- **Evidence:** interpreting a current UTC timestamp under PHP's `Europe/Berlin` zone made a fresh timestamp appear 3,600 seconds old on the review date. Actual server time-zone settings were not inspected.

MySQL returns `TIMESTAMP` in its session time zone. PHP interprets the timezone-less string using `strtotime()` and its own default zone. Neither connection establishes a shared zone. Mismatches can cause excessive refreshes or delay refreshes.

**Recommendation:** compute freshness entirely in SQL with the database clock, or explicitly establish consistent UTC handling.

### 14. Weather refresh failures have no retry backoff

- **Locations:** both weather files, `:179–192` and `:205–207`.
- **Evidence:** control-flow inspection.

A failed refresh leaves the cache expired, so the next request immediately tries again. Nonblocking locks prevent simultaneous refreshes but not repeated failed downloads during an outage.

With an empty cache, concurrent requests that cannot acquire the refresh lock can return an empty successful dataset while the first refresh is still running.

**Recommendation:** track a next-retry time independently of the last successful dataset. Distinguish absence of a usable dataset from a legitimate query with no matching stations.

## Additional findings

### 15. Weather errors use HTTP 200

- **Locations:** both weather files, `:233–263`, `:309–314`.
- **Evidence:** inspection.

Validation and internal-error responses set XML bodies without setting error status codes. Clients and monitoring can interpret failure as success.

**Recommendation:** use HTTP 400 for invalid input and appropriate 5xx responses for server failures, preserving the XML error shape where needed for compatibility.

### 16. NOTAM coordinate validation accepts a trailing newline

- **Location:** `notam.php:451–454`.
- **Evidence:** `isValidLongitude("7\n")` returned `true`.

PCRE `$` can match before a final newline. The accepted coordinate is concatenated verbatim into the upstream URL.

**Recommendation:** use strict end-of-string anchoring such as `\z` and encode query parameters when constructing URLs. This reproduction establishes malformed-input acceptance, not a broader injection exploit.

### 17. Account-wide throttling has only per-query backoff

- **Locations:** `notam.php:204–207`, `notam.php:236–238`.
- **Evidence:** inspection; account-wide FAA rate limiting is documented in `README.md`.

After an account-wide HTTP 429, other queries still call FAA immediately. Negative caching only protects repeated identical requests.

**Recommendation:** consider a shared cooldown specifically for 429 responses, respecting upstream `Retry-After` where available. Retain per-query failure caching for other errors.

### 18. Pagination has no whole-operation deadline

- **Location:** `notam.php:393–423`.
- **Evidence:** inspection.

Up to 50 pages each receive a separate timeout. A fetch can occupy a PHP worker and retain its query lock much longer than the 20-second waiter timeout. Stream timeouts are not an explicit total-operation deadline.

**Recommendation:** enforce an elapsed-time budget for the complete fetch and use stale/error handling on exhaustion instead of returning partial results.

### 19. Weather transaction guarantees depend on the storage engine

- **Locations:** both weather files, `:79–85`.
- **Evidence:** schema inspection; deployed storage engines were not inspected.

Automatically created tables do not specify `ENGINE=InnoDB`, although delete-and-replace assumes transactional storage. Modern defaults commonly satisfy this assumption, but the schema does not establish it. `CREATE TABLE IF NOT EXISTS` also cannot repair an existing nontransactional table.

**Recommendation:** specify and verify a transactional engine during schema setup.

## Existing behavior worth preserving

- SQL values are generally passed through prepared statements.
- Weather replacement uses transactions and duplicate-station upserts.
- Weather refresh locks are nonblocking, allowing existing cached data to remain available during refresh.
- NOTAM rechecks the successful response cache after acquiring its query lock.
- Most acquired locks are released in `finally` blocks.
- Token expiry calculations explicitly use UTC.
- NOTAM rejects array-valued request parameters before processing.
- Detailed internal failures generally go to logs rather than clients.
- METAR and TAF implementations remain effectively identical after normalizing endpoint-specific names; shared fixes should be applied to both.

## Verification and limitations

Executed:

- `for file in metar.php taf.php notam.php; do php -l "$file" || exit 1; done` — all passed.
- Actual endpoint reproductions for array-valued `bbox` input.
- Loopback HTTP fixtures for malformed JSON envelopes, partial pagination, page-limit truncation, redirects, and HTTP 401.
- Mocked-PDO checks for negative-cache timing, failed token-lock acquisition, and stale-row handling.
- Isolated numeric-validation and time-zone checks.

Temporary review harnesses were created outside the repository at `/tmp/opencode/enroute-review.php` and `/tmp/opencode/enroute-review-run.py`. They are scratch artifacts, not a committed regression suite, and may not persist.

Not exercised:

- Real MySQL locking, transaction isolation, metric-rollup races, storage engines, or time-zone settings.
- FAA authentication or NOTAM requests.
- Weather XML execution, because the local PHP installation lacks SimpleXML.

The XML review used these public schemas:

- <https://aviationweather.gov/data/schema/taf1_3.xsd>
- <https://aviationweather.gov/data/schema/metar1_3.xsd>

No live database or FAA services were modified. No fixes were applied to the PHP files during the review.
