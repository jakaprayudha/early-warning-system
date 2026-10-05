USE db_ews;

START TRANSACTION;

INSERT INTO sensors (
    code,
    name,
    sensor_type,
    location_id,
    parameter,
    unit,
    protocol,
    endpoint,
    technical_contact,
    expected_interval_minutes,
    status,
    last_heartbeat_at,
    last_data_at,
    `last_value`,
    notes,
    created_at,
    updated_at
)
SELECT
    v.code,
    v.name,
    v.sensor_type,
    monitoring_locations.id,
    v.parameter,
    v.unit,
    v.protocol,
    v.endpoint,
    v.contact,
    v.expected_interval,
    v.status,
    CASE
        WHEN v.age_min IS NULL THEN NULL
        ELSE UNIX_TIMESTAMP() - (v.age_min * 60)
    END,
    CASE
        WHEN v.age_min IS NULL THEN NULL
        ELSE UNIX_TIMESTAMP() - (v.age_min * 60)
    END,
    v.latest_value,
    v.notes,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
FROM (
    SELECT
        'DEMO-SN-CITARUM-TMA' AS code,
        'TMA Citarum Bekasi' AS name,
        'water_level' AS sensor_type,
        'DEMO-POS-CITARUM' AS location_code,
        'Tinggi muka air' AS parameter,
        'cm' AS unit,
        'http_api' AS protocol,
        'https://example.test/api/citarum/tma' AS endpoint,
        'Tim IT BBWS (demo)' AS contact,
        10 AS expected_interval,
        'active' AS status,
        4 AS age_min,
        '182 cm' AS latest_value,
        '' AS notes

    UNION ALL SELECT
        'DEMO-SN-KALIBEKASI-TMA',
        'TMA Kali Bekasi',
        'water_level',
        'DEMO-POS-KALIBEKASI',
        'Tinggi muka air',
        'cm',
        'mqtt',
        'mqtts://example.test/ews/kalibekasi',
        'Dinas PU (demo)',
        10,
        'active',
        55,
        '95 cm',
        'Contoh sensor terlambat.'

    UNION ALL SELECT
        'DEMO-SN-DAGO-HUJAN',
        'Penakar hujan Dago',
        'rain_gauge',
        'DEMO-STA-DAGO',
        'Curah hujan',
        'mm/jam',
        'http_api',
        'https://example.test/api/dago/hujan',
        'BMKG (demo)',
        15,
        'active',
        7,
        '12 mm/jam',
        ''

    UNION ALL SELECT
        'DEMO-SN-LEMBANG-ANGIN',
        'Anemometer Lembang',
        'anemometer',
        'DEMO-LEMBANG',
        'Kecepatan angin',
        'km/jam',
        'manual',
        '',
        'BPBD (demo)',
        60,
        'active',
        20,
        '34 km/jam',
        'Pembacaan oleh petugas.'

    UNION ALL SELECT
        'DEMO-SN-ANGKE-PASUT',
        'Pasang surut Muara Angke',
        'tide_gauge',
        'DEMO-MUARA-ANGKE',
        'Tinggi pasang',
        'm',
        'file_upload',
        '',
        'BPBD DKI (demo)',
        30,
        'maintenance',
        300,
        '1.1 m',
        'Sedang kalibrasi.'

    UNION ALL SELECT
        'DEMO-SN-CIREBON-CUACA',
        'Stasiun cuaca Cirebon',
        'weather_station',
        'DEMO-POS-CIREBON',
        'Suhu',
        '°C',
        'http_api',
        'https://example.test/api/cirebon',
        'BPBD (demo)',
        15,
        'active',
        NULL,
        '',
        'Belum pernah mengirim data.'

    UNION ALL SELECT
        'DEMO-SN-LAMA',
        'Sensor lama Jakarta Utara',
        'water_level',
        'DEMO-POS-NONAKTIF',
        'Tinggi muka air',
        'cm',
        'manual',
        '',
        '',
        60,
        'inactive',
        4000,
        '60 cm',
        'Dinonaktifkan.'
) AS v
JOIN monitoring_locations
    ON monitoring_locations.code = v.location_code
WHERE NOT EXISTS (
    SELECT 1
    FROM sensors
    WHERE sensors.code = v.code
);

COMMIT;