-- Schema of the tables used by notam.php (database `enroutecaches`).
--
-- Unlike metar.php and taf.php, notam.php does not create its tables. Run this
-- file once when setting up a new database.
--
-- notam_cache            Merged FAA responses under 'notam_<md5(url)>',
--                        negative-cache rows under 'nfail_<md5(url)>' and the
--                        global cooldown row 'nfail_global' (value: FAA HTTP
--                        status). `expiration` is the end of freshness; old
--                        rows are deleted by performMaintenance().
-- nms_token_cache        The current NMS bearer token: always one row, id = 1,
--                        times in UTC.
-- cache_metrics          Daily counts of 'hit', 'miss' and 'stale' responses.
-- cache_metrics_monthly  Monthly totals, rolled up from cache_metrics after
--                        30 days.

CREATE TABLE IF NOT EXISTS `notam_cache` (
  `cache_key` varchar(255) NOT NULL,
  `cache_value` mediumtext DEFAULT NULL,
  `expiration` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`cache_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nms_token_cache` (
  `id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `access_token` text NOT NULL,
  `expires_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_single_row` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Caches the current NMS API bearer token (always at most one row).';

CREATE TABLE IF NOT EXISTS `cache_metrics` (
  `metric` enum('hit','miss','stale') NOT NULL,
  `count` int(10) unsigned NOT NULL DEFAULT 0,
  `date` date NOT NULL,
  PRIMARY KEY (`metric`,`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cache_metrics_monthly` (
  `year` smallint(5) unsigned NOT NULL,
  `month` tinyint(3) unsigned NOT NULL,
  `metric` enum('hit','miss','stale') NOT NULL,
  `count` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`metric`,`year`,`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
