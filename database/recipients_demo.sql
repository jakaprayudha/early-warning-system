-- Data demo Penerima notifikasi (FR-06). Idempoten.
INSERT INTO recipient_groups (name, description, region_id, hazard_code, channels, is_active, created_at, updated_at)
SELECT 'Operator BPBD', 'Operator piket pemantauan banjir', 2, 'river_flood', 'dashboard,email', 1, strftime('%s','now'), strftime('%s','now')
WHERE NOT EXISTS (SELECT 1 FROM recipient_groups WHERE name = 'Operator BPBD');
INSERT INTO recipient_groups (name, description, region_id, hazard_code, channels, is_active, created_at, updated_at)
SELECT 'Kepala Pelaksana', 'Pimpinan untuk peringatan tertinggi', 2, NULL, 'dashboard,sms,whatsapp', 1, strftime('%s','now'), strftime('%s','now')
WHERE NOT EXISTS (SELECT 1 FROM recipient_groups WHERE name = 'Kepala Pelaksana');
INSERT INTO recipient_groups (name, description, region_id, hazard_code, channels, is_active, created_at, updated_at)
SELECT 'Koordinator Lapangan', 'Tim lapangan wilayah Bekasi', 2, 'river_flood', 'sms', 1, strftime('%s','now'), strftime('%s','now')
WHERE NOT EXISTS (SELECT 1 FROM recipient_groups WHERE name = 'Koordinator Lapangan');
INSERT INTO recipient_members (group_id, name, position, email, phone, created_at, updated_at)
SELECT g.id, 'Dewi Lestari', 'Operator piket', 'dewi@example.test', '', strftime('%s','now'), strftime('%s','now') FROM recipient_groups g
WHERE g.name = 'Operator BPBD' AND NOT EXISTS (SELECT 1 FROM recipient_members WHERE group_id = g.id);
INSERT INTO recipient_members (group_id, name, position, email, phone, created_at, updated_at)
SELECT g.id, 'Bambang Hartono', 'Kepala Pelaksana', 'bambang@example.test', '+628123456789', strftime('%s','now'), strftime('%s','now') FROM recipient_groups g
WHERE g.name = 'Kepala Pelaksana' AND NOT EXISTS (SELECT 1 FROM recipient_members WHERE group_id = g.id);
INSERT INTO recipient_members (group_id, name, position, email, phone, created_at, updated_at)
SELECT g.id, 'Slamet Riyadi', 'Koordinator', '', '+628111222333', strftime('%s','now'), strftime('%s','now') FROM recipient_groups g
WHERE g.name = 'Koordinator Lapangan' AND NOT EXISTS (SELECT 1 FROM recipient_members WHERE group_id = g.id);
