-- Convert DNS record geo routes from edge-country routing to raw DNS answers.
ALTER TABLE dns_record_geo_routes ADD COLUMN IF NOT EXISTS route_scope TEXT NOT NULL DEFAULT 'country';
ALTER TABLE dns_record_geo_routes ADD COLUMN IF NOT EXISTS continent_code TEXT NULL;

-- CDNLite is pre-1.0/fresh-install-only; old EDGE_PROXY rows represented the
-- removed edge-country route model and are not valid raw GeoDNS answers.
DELETE FROM dns_record_geo_routes WHERE answer_type = 'EDGE_PROXY';

UPDATE dns_record_geo_routes
SET route_scope = CASE
  WHEN country_code IS NULL THEN 'default'
  ELSE 'country'
END
WHERE country_code IS NULL OR route_scope NOT IN ('default', 'country', 'continent');

ALTER TABLE dns_record_geo_routes ALTER COLUMN answer_type DROP DEFAULT;

DROP INDEX IF EXISTS dns_record_geo_routes_country_idx;

ALTER TABLE dns_record_geo_routes DROP CONSTRAINT IF EXISTS dns_record_geo_routes_route_scope_check;
ALTER TABLE dns_record_geo_routes DROP CONSTRAINT IF EXISTS dns_record_geo_routes_country_code_check;
ALTER TABLE dns_record_geo_routes DROP CONSTRAINT IF EXISTS dns_record_geo_routes_continent_code_check;
ALTER TABLE dns_record_geo_routes DROP CONSTRAINT IF EXISTS dns_record_geo_routes_answer_type_check;
ALTER TABLE dns_record_geo_routes DROP CONSTRAINT IF EXISTS dns_record_geo_routes_check;
ALTER TABLE dns_record_geo_routes DROP CONSTRAINT IF EXISTS dns_record_geo_routes_raw_answer_check;
ALTER TABLE dns_record_geo_routes DROP CONSTRAINT IF EXISTS dns_record_geo_routes_scope_target_check;

ALTER TABLE dns_record_geo_routes
  ADD CONSTRAINT dns_record_geo_routes_route_scope_check CHECK (route_scope IN ('default', 'country', 'continent')),
  ADD CONSTRAINT dns_record_geo_routes_country_code_check CHECK (country_code IS NULL OR country_code ~ '^[A-Z]{2}$'),
  ADD CONSTRAINT dns_record_geo_routes_continent_code_check CHECK (continent_code IS NULL OR continent_code IN ('AF', 'AN', 'AS', 'EU', 'NA', 'OC', 'SA')),
  ADD CONSTRAINT dns_record_geo_routes_answer_type_check CHECK (answer_type IN ('A', 'AAAA')),
  ADD CONSTRAINT dns_record_geo_routes_raw_answer_check CHECK (edge_node_id IS NULL AND edge_pool_id IS NULL AND answer_value IS NOT NULL),
  ADD CONSTRAINT dns_record_geo_routes_scope_target_check CHECK (
    (route_scope = 'default' AND country_code IS NULL AND continent_code IS NULL)
    OR (route_scope = 'country' AND country_code IS NOT NULL AND continent_code IS NULL)
    OR (route_scope = 'continent' AND country_code IS NULL AND continent_code IS NOT NULL)
  );

CREATE UNIQUE INDEX IF NOT EXISTS dns_record_geo_routes_default_idx
  ON dns_record_geo_routes (dns_record_id)
  WHERE route_scope = 'default';

CREATE UNIQUE INDEX IF NOT EXISTS dns_record_geo_routes_country_idx
  ON dns_record_geo_routes (dns_record_id, country_code)
  WHERE route_scope = 'country';

CREATE UNIQUE INDEX IF NOT EXISTS dns_record_geo_routes_continent_idx
  ON dns_record_geo_routes (dns_record_id, continent_code)
  WHERE route_scope = 'continent';
