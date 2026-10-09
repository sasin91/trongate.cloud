-- Requests per app per 5-minute window (UTC), from Traefik's router counters:
-- sampled by the worker's sample_traffic task (App_traffic), kept 7 days.
-- le_*: requests answered within that many seconds (cumulative, as Traefik's
-- histogram buckets); all requests is ok + client_errors + server_errors.
CREATE TABLE IF NOT EXISTS app_traffic (
  app_id INT NOT NULL,
  bucket DATETIME NOT NULL,
  ok INT UNSIGNED NOT NULL DEFAULT 0,
  client_errors INT UNSIGNED NOT NULL DEFAULT 0,
  server_errors INT UNSIGNED NOT NULL DEFAULT 0,
  bytes_out BIGINT UNSIGNED NOT NULL DEFAULT 0,
  le_0_025 INT UNSIGNED NOT NULL DEFAULT 0,
  le_0_05 INT UNSIGNED NOT NULL DEFAULT 0,
  le_0_1 INT UNSIGNED NOT NULL DEFAULT 0,
  le_0_25 INT UNSIGNED NOT NULL DEFAULT 0,
  le_0_5 INT UNSIGNED NOT NULL DEFAULT 0,
  le_1 INT UNSIGNED NOT NULL DEFAULT 0,
  le_2_5 INT UNSIGNED NOT NULL DEFAULT 0,
  le_5 INT UNSIGNED NOT NULL DEFAULT 0,
  le_10 INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (app_id, bucket),
  KEY idx_bucket (bucket)
);

-- The last value sample_traffic read of each Traefik counter, per Traefik pod,
-- so each run adds only what changed. series: sha1 of the metric line's name and labels.
CREATE TABLE IF NOT EXISTS traffic_counter (
  pod_uid VARCHAR(64) NOT NULL,
  series CHAR(40) NOT NULL,
  value DOUBLE NOT NULL,
  seen_at DATETIME NOT NULL,
  PRIMARY KEY (pod_uid, series),
  KEY idx_seen (seen_at)
);
