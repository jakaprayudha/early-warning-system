USE db_ews;

START TRANSACTION;

UPDATE regions
SET admin_level = 'province'
WHERE code IN ('DEMO-REGION-JABAR', 'DEMO-REGION-DKI');

UPDATE regions
SET admin_level = 'regency'
WHERE code IN (
    'DEMO-REGION-BEKASI',
    'DEMO-REGION-BANDUNG',
    'DEMO-REGION-CIREBON',
    'DEMO-REGION-JAKUT'
);

INSERT INTO monitoring_locations (
    code, name, region_id, location_type, latitude, longitude,
    elevation_m, vertical_datum, managed_by, notes, is_active,
    created_at, updated_at
)
SELECT
    v.code, v.name, regions.id, v.location_type, v.latitude, v.longitude,
    v.elevation_m, v.vertical_datum, v.managed_by, v.notes, v.is_active,
    UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM (
    SELECT
        'DEMO-POS-CITARUM' AS code,
        'Pos Pantau Sungai Citarum - Bekasi' AS name,
        'DEMO-REGION-BEKASI' AS region_code,
        'river_post' AS location_type,
        -6.2383 AS latitude, 107.1396 AS longitude, 12.5 AS elevation_m,
        'MSL' AS vertical_datum, 'BBWS Citarum (demo)' AS managed_by,
        'Pos tinggi muka air.' AS notes, 1 AS is_active
    UNION ALL SELECT
        'DEMO-POS-KALIBEKASI', 'Pos Pantau Kali Bekasi',
        'DEMO-REGION-BEKASI', 'river_post',
        -6.2601, 106.9993, 8.0, 'MSL', 'Dinas PU (demo)', '', 1
    UNION ALL SELECT
        'DEMO-STA-DAGO', 'Stasiun Hujan Dago - Bandung',
        'DEMO-REGION-BANDUNG', 'weather_station',
        -6.8854, 107.6139, 770.0, 'MSL', 'BMKG (demo)',
        'Curah hujan dan angin.', 1
    UNION ALL SELECT
        'DEMO-LEMBANG', 'Kecamatan Lembang - Bandung',
        'DEMO-REGION-BANDUNG', 'village',
        -6.8115, 107.6177, 1250.0, 'MSL', 'BPBD (demo)', '', 1
    UNION ALL SELECT
        'DEMO-POS-CIREBON', 'Pos Pengamatan Cirebon',
        'DEMO-REGION-CIREBON', 'station',
        -6.7063, 108.5570, 5.0, 'MSL', 'BPBD (demo)',
        'Pengamatan angin kencang.', 1
    UNION ALL SELECT
        'DEMO-MUARA-ANGKE', 'Pesisir Muara Angke - Jakarta Utara',
        'DEMO-REGION-JAKUT', 'coastal_post',
        -6.1020, 106.7740, 1.2, 'LWS', 'BPBD DKI (demo)',
        'Rob dan pasang tinggi.', 1
    UNION ALL SELECT
        'DEMO-POS-NONAKTIF', 'Pos Lama Jakarta Utara',
        'DEMO-REGION-JAKUT', 'river_post',
        -6.1300, 106.8300, 2.0, 'MSL', 'Dinas PU (demo)',
        'Dinonaktifkan untuk contoh.', 0
) AS v
JOIN regions ON regions.code = v.region_code
WHERE NOT EXISTS (
    SELECT 1
    FROM monitoring_locations
    WHERE monitoring_locations.code = v.code
);

INSERT IGNORE INTO location_hazards (location_id, hazard_code)
SELECT monitoring_locations.id, v.hazard_code
FROM (
    SELECT 'DEMO-POS-CITARUM' AS code, 'river_flood' AS hazard_code
    UNION ALL SELECT 'DEMO-POS-KALIBEKASI', 'river_flood'
    UNION ALL SELECT 'DEMO-POS-KALIBEKASI', 'weather'
    UNION ALL SELECT 'DEMO-STA-DAGO', 'weather'
    UNION ALL SELECT 'DEMO-LEMBANG', 'weather'
    UNION ALL SELECT 'DEMO-LEMBANG', 'tornado'
    UNION ALL SELECT 'DEMO-POS-CIREBON', 'tornado'
    UNION ALL SELECT 'DEMO-MUARA-ANGKE', 'coastal_tide'
    UNION ALL SELECT 'DEMO-POS-NONAKTIF', 'river_flood'
) AS v
JOIN monitoring_locations ON monitoring_locations.code = v.code;

COMMIT;