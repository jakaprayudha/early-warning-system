-- Data utama demo: Kabupaten Deli Serdang, Sumatera Utara (SQLite).
-- Menggantikan data demo Jawa Barat/DKI. Jalankan: sqlite3 storage/db_ews.sqlite < database/deli_serdang_demo.sql
PRAGMA foreign_keys = OFF;
BEGIN;

DELETE FROM location_hazards;
DELETE FROM sensor_readings;
DELETE FROM sensor_ingest_config;
DELETE FROM sensors;
DELETE FROM monitoring_locations;

UPDATE regions SET code='DS-PROV-SUMUT', name='Sumatera Utara', parent_id=NULL, admin_level='province', timezone='Asia/Jakarta' WHERE id=1;
UPDATE regions SET code='DS-KAB-DELISERDANG', name='Kabupaten Deli Serdang', parent_id=1, admin_level='regency', timezone='Asia/Jakarta' WHERE id=2;

-- Pindahkan rujukan ke wilayah yang akan dihapus
UPDATE thresholds SET region_id=2 WHERE region_id NOT IN (1,2);
UPDATE alert_events SET region_id=2 WHERE region_id NOT IN (1,2);
UPDATE recipient_groups SET region_id=2 WHERE region_id NOT IN (1,2);
UPDATE alert_rules SET region_id=2 WHERE region_id NOT IN (1,2);
DELETE FROM user_regions WHERE region_id NOT IN (1,2);
DELETE FROM regions WHERE id NOT IN (1,2);

CREATE TEMP TABLE seed_kec (code TEXT, name TEXT);
INSERT INTO seed_kec VALUES
 ('HAMPARAN-PERAK','Hamparan Perak'),('LABUHAN-DELI','Labuhan Deli'),('TANJUNG-MORAWA','Tanjung Morawa'),
 ('LUBUK-PAKAM','Lubuk Pakam'),('SIBOLANGIT','Sibolangit'),('PANTAI-LABU','Pantai Labu'),
 ('BIRU-BIRU','Biru-Biru'),('SUNGGAL','Sunggal'),('NAMORAMBE','Namorambe'),
 ('PANCUR-BATU','Pancur Batu'),('PATUMBAK','Patumbak');
INSERT INTO regions (code, name, parent_id, created_at, admin_level, timezone)
SELECT 'DS-KEC-' || code, name, 2, strftime('%s','now'), 'district', 'Asia/Jakarta' FROM seed_kec;

CREATE TEMP TABLE seed_loc (code TEXT, name TEXT, kec TEXT, hazard TEXT, lat REAL, lng REAL);
INSERT INTO seed_loc VALUES
 ('DS-TOR-PAYA-BAKUNG','Desa Paya Bakung','HAMPARAN-PERAK','tornado',3.645777,98.550846),
 ('DS-CUA-KARANG-GADING','Desa Karang Gading','LABUHAN-DELI','weather',3.822343,98.606322),
 ('DS-PAN-PALUH-KURAU','Desa Paluh Kurau','HAMPARAN-PERAK','coastal_tide',3.846125,98.643428),
 ('DS-SUN-NAGA-TIMBUL','Desa Naga Timbul / Sungai Sei Batu Gangging','TANJUNG-MORAWA','river_flood',3.472166,98.806584),
 ('DS-CUA-KANTOR-BPBD','Kantor BPBD','LUBUK-PAKAM','weather',3.558263,98.871069),
 ('DS-SUN-DURIN-SERUGUN','Desa Durin Serugun / Sungai Betimus','SIBOLANGIT','river_flood',3.276807,98.534790),
 ('DS-CUA-KANTOR-CAMAT-SIBOLANGIT','Kantor Camat Sibolangit','SIBOLANGIT','weather',3.266823,98.547029),
 ('DS-PAN-PALUH-SIBAJI','Desa Paluh Sibaji','PANTAI-LABU','coastal_tide',3.679642,98.909754),
 ('DS-SUN-RUMAH-GERAT','Desa Rumah Gerat / Sungai Serurai','BIRU-BIRU','river_flood',3.359281,98.657023),
 ('DS-SUN-SELAMAT','Desa Selamat / Sungai Belawan','SUNGGAL','river_flood',3.539711,98.594172),
 ('DS-TOR-JATI-KESUMA','Desa Jati Kesuma','NAMORAMBE','tornado',3.453845,98.653446),
 ('DS-TOR-DURIN-TONGGAL','Desa Durin Tonggal','PANCUR-BATU','tornado',3.461626,98.620954),
 ('DS-TOR-PATUMBAK-1','Desa Patumbak 1','PATUMBAK','tornado',3.444930,98.709417),
 ('DS-TOR-SEI-MENCIRIM','Desa Sei Mencirim','SUNGGAL','tornado',3.561074,98.564832);

INSERT INTO monitoring_locations (code, name, region_id, location_type, latitude, longitude, vertical_datum, managed_by, notes, is_active, created_at, updated_at)
SELECT l.code, l.name, r.id,
  CASE l.hazard WHEN 'river_flood' THEN 'river_post' WHEN 'coastal_tide' THEN 'coastal_post'
    WHEN 'weather' THEN 'weather_station' ELSE 'village' END,
  l.lat, l.lng, '', 'BPBD Kabupaten Deli Serdang', 'Data titik rawan (demo).', 1,
  strftime('%s','now'), strftime('%s','now')
FROM seed_loc l JOIN regions r ON r.code = 'DS-KEC-' || l.kec;

INSERT INTO location_hazards (location_id, hazard_code)
SELECT ml.id, l.hazard FROM seed_loc l JOIN monitoring_locations ml ON ml.code = l.code;

-- Satu sensor per lokasi; umur data bervariasi agar status kesehatan realistis
INSERT INTO sensors (code, name, sensor_type, location_id, parameter, unit, protocol, technical_contact, expected_interval_minutes, status, last_data_at, last_heartbeat_at, last_value, notes, created_at, updated_at)
SELECT 'SN-' || substr(l.code, 4),
  CASE l.hazard WHEN 'river_flood' THEN 'TMA ' WHEN 'coastal_tide' THEN 'Pasang surut ' WHEN 'weather' THEN 'Penakar hujan ' ELSE 'Anemometer ' END || l.name,
  CASE l.hazard WHEN 'river_flood' THEN 'water_level' WHEN 'coastal_tide' THEN 'tide_gauge' WHEN 'weather' THEN 'rain_gauge' ELSE 'anemometer' END,
  ml.id,
  CASE l.hazard WHEN 'river_flood' THEN 'Tinggi muka air' WHEN 'coastal_tide' THEN 'Tinggi pasang' WHEN 'weather' THEN 'Curah hujan' ELSE 'Kecepatan angin' END,
  CASE l.hazard WHEN 'river_flood' THEN 'cm' WHEN 'coastal_tide' THEN 'm' WHEN 'weather' THEN 'mm/jam' ELSE 'km/jam' END,
  CASE l.hazard WHEN 'river_flood' THEN 'http_api' WHEN 'coastal_tide' THEN 'file_upload' WHEN 'weather' THEN 'mqtt' ELSE 'manual' END,
  'Tim teknis BPBD Deli Serdang', 15, 'active',
  strftime('%s','now') - (ml.id % 4) * 360 - 120,
  strftime('%s','now') - (ml.id % 4) * 360 - 120,
  CASE l.hazard WHEN 'river_flood' THEN (90 + ml.id * 7) || ' cm' WHEN 'coastal_tide' THEN '1.' || (ml.id % 9) || ' m'
    WHEN 'weather' THEN (8 + ml.id) || ' mm/jam' ELSE (20 + ml.id * 2) || ' km/jam' END,
  '', strftime('%s','now'), strftime('%s','now')
FROM seed_loc l JOIN monitoring_locations ml ON ml.code = l.code;

-- Variasi status: terlambat, pemeliharaan, belum ada data
UPDATE sensors SET last_data_at = strftime('%s','now') - 5400, last_heartbeat_at = strftime('%s','now') - 5400 WHERE code = 'SN-SUN-DURIN-SERUGUN';
UPDATE sensors SET status = 'maintenance', last_data_at = strftime('%s','now') - 86400 WHERE code = 'SN-PAN-PALUH-KURAU';
UPDATE sensors SET last_data_at = NULL, last_heartbeat_at = NULL, last_value = '' WHERE code = 'SN-TOR-PATUMBAK-1';

-- Selaraskan data seed lain dengan Deli Serdang
UPDATE alert_rules SET name = replace(name, 'Bekasi', 'Deli Serdang');
UPDATE alert_rules SET region_id = 2;

UPDATE alert_events SET region_id=(SELECT id FROM regions WHERE code='DS-KEC-TANJUNG-MORAWA'), location_name='Desa Naga Timbul / Sungai Sei Batu Gangging' WHERE id=1;
UPDATE alert_events SET region_id=(SELECT id FROM regions WHERE code='DS-KEC-LUBUK-PAKAM'), location_name='Kantor BPBD' WHERE id=2;
UPDATE alert_events SET region_id=(SELECT id FROM regions WHERE code='DS-KEC-PANTAI-LABU'), location_name='Desa Paluh Sibaji' WHERE id=3;
UPDATE alert_events SET region_id=(SELECT id FROM regions WHERE code='DS-KEC-NAMORAMBE'), location_name='Desa Jati Kesuma' WHERE id=4;
UPDATE alert_events SET region_id=(SELECT id FROM regions WHERE code='DS-KEC-SIBOLANGIT'), location_name='Kantor Camat Sibolangit' WHERE id=5;
UPDATE alert_events SET region_id=(SELECT id FROM regions WHERE code='DS-KEC-SIBOLANGIT'), location_name='Desa Durin Serugun / Sungai Betimus' WHERE id=6;

UPDATE thresholds SET region_id=(SELECT id FROM regions WHERE code='DS-KEC-LABUHAN-DELI') WHERE id=3;
UPDATE thresholds SET region_id=(SELECT id FROM regions WHERE code='DS-KEC-PANTAI-LABU') WHERE id=4;

COMMIT;
PRAGMA foreign_keys = ON;
