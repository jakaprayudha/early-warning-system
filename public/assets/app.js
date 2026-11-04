document.querySelectorAll('form').forEach((form) => {
    const password = form.querySelector('input[name="password"]');
    const confirmation = form.querySelector('input[name="password_confirmation"]');
    if (!password || !confirmation) return;

    const validatePasswords = () => {
        confirmation.setCustomValidity(
            confirmation.value && confirmation.value !== password.value
                ? 'Password tidak sama.'
                : ''
        );
    };
    password.addEventListener('input', validatePasswords);
    confirmation.addEventListener('input', validatePasswords);
});

const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
const sidebarBackdrop = document.querySelector('[data-sidebar-backdrop]');
if (sidebarToggle && sidebarBackdrop) {
    const setSidebarOpen = (open) => {
        document.body.classList.toggle('sidebar-open', open);
        sidebarToggle.setAttribute('aria-expanded', String(open));
    };

    sidebarToggle.addEventListener('click', () => {
        setSidebarOpen(sidebarToggle.getAttribute('aria-expanded') !== 'true');
    });
    sidebarBackdrop.addEventListener('click', () => setSidebarOpen(false));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') setSidebarOpen(false);
    });
}

const locationMap = document.querySelector('[data-location-map]');
if (locationMap) {
    const points = JSON.parse(locationMap.dataset.points || '[]');
    const styles = JSON.parse(locationMap.dataset.styles || '{}');
    const tileLayer = locationMap.querySelector('[data-map-tiles]');
    const markerLayer = locationMap.querySelector('[data-map-markers]');
    const coordsLabel = locationMap.querySelector('[data-map-coords]');
    const TILE = 256;
    const svgNS = 'http://www.w3.org/2000/svg';
    const iconPaths = {
        station: 'M12 11v9M8 20h8M8.5 7.5a5 5 0 0 0 0 7M15.5 7.5a5 5 0 0 1 0 7M5.5 5a9 9 0 0 0 0 12M18.5 5a9 9 0 0 1 0 12',
        river_post: 'M3 9c2-2 4-2 6 0s4 2 6 0 4-2 6 0M3 15c2-2 4-2 6 0s4 2 6 0 4-2 6 0',
        coastal_post: 'M12 7v13M8 11h8M5 15a7 7 0 0 0 14 0M12 3.2a1.8 1.8 0 1 1 0 3.6 1.8 1.8 0 0 1 0-3.6z',
        weather_station: 'M7 18a4 4 0 0 1-.5-8A5.5 5.5 0 0 1 17 8.5a4.8 4.8 0 0 1 0 9.5z',
        village: 'M4 11l8-7 8 7M6 10v9h12v-9M10 19v-5h4v5',
        other: 'M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8z',
    };

    const buildIcon = (type) => {
        const svg = document.createElementNS(svgNS, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', '#fff');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        const path = document.createElementNS(svgNS, 'path');
        path.setAttribute('d', iconPaths[type] || iconPaths.other);
        svg.appendChild(path);
        return svg;
    };
    document.querySelectorAll('[data-map-legend]').forEach((element) => {
        const type = element.dataset.mapLegend;
        element.style.background = (styles[type] || styles.other || {}).color || '#6B7C85';
        element.appendChild(buildIcon(type));
    });

    const mercatorY = (lat) => {
        const sin = Math.sin((Math.max(-85.05, Math.min(85.05, lat)) * Math.PI) / 180);
        return 0.5 - Math.log((1 + sin) / (1 - sin)) / (4 * Math.PI);
    };
    const lngToX = (lng) => (lng + 180) / 360;
    const xToLng = (x) => x * 360 - 180;
    const yToLat = (y) => (Math.atan(Math.sinh(Math.PI * (1 - 2 * y))) * 180) / Math.PI;

    const state = { lat: -2.5, lng: 118, zoom: 5 };
    const tiles = new Map();
    const markers = new Map();
    let popup = null;
    let selectedId = null;

    const world = () => TILE * 2 ** state.zoom;
    const size = () => ({ w: locationMap.clientWidth, h: locationMap.clientHeight });
    const project = (lat, lng) => {
        const { w, h } = size();
        const scale = world();
        return {
            x: (lngToX(lng) - lngToX(state.lng)) * scale + w / 2,
            y: (mercatorY(lat) - mercatorY(state.lat)) * scale + h / 2,
        };
    };
    const unproject = (px, py) => {
        const { w, h } = size();
        const scale = world();
        return {
            lat: yToLat(mercatorY(state.lat) + (py - h / 2) / scale),
            lng: xToLng(lngToX(state.lng) + (px - w / 2) / scale),
        };
    };

    points.forEach((point) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'map-pin' + (point.active ? '' : ' inactive');
        button.title = point.name;
        button.setAttribute('aria-label', point.name);
        button.style.background = (styles[point.type] || styles.other).color;
        button.appendChild(buildIcon(point.type));
        const dot = document.createElement('i');
        dot.className = 'health-dot health-' + point.sensorHealth;
        button.appendChild(dot);
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            select(point.id);
        });
        button.addEventListener('pointerdown', (event) => event.stopPropagation());
        markerLayer.appendChild(button);
        markers.set(point.id, button);
    });

    const closePopup = () => {
        if (popup) popup.remove();
        popup = null;
        selectedId = null;
        markers.forEach((marker) => marker.classList.remove('selected'));
        document.querySelectorAll('[data-map-focus]').forEach((item) => item.classList.remove('active'));
    };

    const addLine = (parent, label, value) => {
        const row = document.createElement('p');
        const strong = document.createElement('b');
        strong.textContent = label + ' ';
        row.append(strong, document.createTextNode(value));
        parent.appendChild(row);
    };

    function select(id) {
        const point = points.find((item) => item.id === id);
        if (!point) return;
        closePopup();
        selectedId = id;
        markers.get(id).classList.add('selected');
        document.querySelectorAll('[data-map-focus]').forEach((item) => {
            item.classList.toggle('active', Number(item.dataset.mapFocus) === id);
        });
        popup = document.createElement('div');
        popup.className = 'map-popup';
        popup.addEventListener('pointerdown', (event) => event.stopPropagation());
        const title = document.createElement('strong');
        title.textContent = point.name;
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'map-popup-close';
        close.setAttribute('aria-label', 'Tutup');
        close.textContent = '×';
        close.addEventListener('click', closePopup);
        popup.append(title, close);
        addLine(popup, 'Kode', point.code);
        addLine(popup, 'Jenis alat', (styles[point.type] || styles.other).label + (point.active ? '' : ' (nonaktif)'));
        addLine(popup, 'Wilayah', point.region);
        addLine(popup, 'Koordinat', point.lat.toFixed(5) + ', ' + point.lng.toFixed(5));
        if (point.elevation !== null) {
            addLine(popup, 'Elevasi', point.elevation + ' m' + (point.datum ? ' (' + point.datum + ')' : ''));
        }
        if (point.hazards.length) addLine(popup, 'Bahaya', point.hazards.join(', '));
        const sensorTitle = document.createElement('p');
        sensorTitle.className = 'map-popup-sensors-title';
        sensorTitle.textContent = point.sensors.length ? 'Sensor (' + point.sensors.length + ')' : 'Belum ada sensor terpasang';
        popup.appendChild(sensorTitle);
        point.sensors.forEach((sensor) => {
            const row = document.createElement('div');
            row.className = 'map-popup-sensor';
            row.dataset.sensor = sensor.code;
            const mark = document.createElement('i');
            mark.className = 'health-dot health-' + sensor.health;
            const info = document.createElement('a');
            info.className = 'map-sensor-info';
            info.href = '/?page=sensor-detail&code=' + encodeURIComponent(sensor.code);
            info.target = '_blank';
            info.rel = 'noopener';
            const name = document.createElement('b');
            name.textContent = sensor.name;
            const meta = document.createElement('small');
            meta.dataset.meta = '';
            meta.textContent = sensor.parameter + ' · ' + sensor.label + ' (' + sensor.last + ')';
            info.append(name, meta);
            const value = document.createElement('strong');
            value.className = 'map-sensor-value';
            value.dataset.value = '';
            value.textContent = sensor.value || '—';
            row.append(mark, info, value);
            popup.appendChild(row);
        });
        if (point.sensors.length) {
            const live = document.createElement('p');
            live.className = 'map-popup-live';
            live.dataset.live = '';
            live.textContent = 'Data realtime · diperbarui tiap 15 detik';
            popup.appendChild(live);
        }
        locationMap.appendChild(popup);
        const { w, h } = size();
        const position = project(point.lat, point.lng);
        const dx = position.x < 40 ? 40 - position.x : (position.x > w - 40 ? w - 40 - position.x : 0);
        const dy = Math.max(0, 12 - (position.y - 26 - popup.offsetHeight));
        if (dx || dy) {
            const scale = world();
            state.lng = xToLng(lngToX(state.lng) - dx / scale);
            state.lat = yToLat(mercatorY(state.lat) - dy / scale);
        }
        render();
    }

    const refreshPopup = async () => {
        if (!popup || document.hidden) return;
        try {
            const response = await fetch('/?page=map-feed', { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) return;
            const data = (await response.json()).sensors || {};
            popup.querySelectorAll('[data-sensor]').forEach((row) => {
                const item = data[row.dataset.sensor];
                if (!item) return;
                row.querySelector('.health-dot').className = 'health-dot health-' + item.health;
                row.querySelector('[data-meta]').textContent = row.querySelector('[data-meta]').textContent.split(' · ')[0] + ' · ' + item.label + ' (' + item.last + ')';
                row.querySelector('[data-value]').textContent = item.value || '—';
            });
            const live = popup.querySelector('[data-live]');
            if (live) live.textContent = 'Data realtime · diperbarui ' + new Date().toLocaleTimeString('id-ID');
        } catch (error) {
            // jaringan terputus; coba lagi pada siklus berikutnya
        }
    };
    setInterval(refreshPopup, 15000);

    const render = () => {
        const { w, h } = size();
        if (!w || !h) return;
        const scale = world();
        const z = state.zoom;
        const count = 2 ** z;
        const originX = lngToX(state.lng) * scale - w / 2;
        const originY = mercatorY(state.lat) * scale - h / 2;
        const needed = new Set();
        const x0 = Math.floor(originX / TILE);
        const x1 = Math.floor((originX + w) / TILE);
        const y0 = Math.max(0, Math.floor(originY / TILE));
        const y1 = Math.min(count - 1, Math.floor((originY + h) / TILE));
        for (let tx = x0; tx <= x1; tx += 1) {
            for (let ty = y0; ty <= y1; ty += 1) {
                const wrapped = ((tx % count) + count) % count;
                const key = z + '/' + tx + '/' + ty;
                needed.add(key);
                let tile = tiles.get(key);
                if (!tile) {
                    tile = document.createElement('img');
                    tile.alt = '';
                    tile.draggable = false;
                    tile.referrerPolicy = 'origin-when-cross-origin';
                    tile.className = 'map-tile';
                    tile.addEventListener('error', () => tile.classList.add('failed'));
                    tile.src = 'https://tile.openstreetmap.org/' + z + '/' + wrapped + '/' + ty + '.png';
                    tileLayer.appendChild(tile);
                    tiles.set(key, tile);
                }
                tile.style.transform = 'translate(' + (tx * TILE - originX) + 'px,' + (ty * TILE - originY) + 'px)';
            }
        }
        tiles.forEach((tile, key) => {
            if (!needed.has(key)) {
                tile.remove();
                tiles.delete(key);
            }
        });
        points.forEach((point) => {
            const position = project(point.lat, point.lng);
            markers.get(point.id).style.transform = 'translate(' + position.x + 'px,' + position.y + 'px)';
        });
        if (popup && selectedId !== null) {
            const point = points.find((item) => item.id === selectedId);
            const position = project(point.lat, point.lng);
            const { w } = size();
            const left = Math.max(8, Math.min(w - popup.offsetWidth - 8, position.x - popup.offsetWidth / 2));
            popup.style.transform = 'translate(' + left + 'px,' + (position.y - 26 - popup.offsetHeight) + 'px)';
        }
    };

    const setZoom = (zoom, anchorX, anchorY) => {
        const next = Math.max(2, Math.min(18, zoom));
        if (next === state.zoom) return;
        const { w, h } = size();
        const ax = anchorX ?? w / 2;
        const ay = anchorY ?? h / 2;
        const anchor = unproject(ax, ay);
        tiles.forEach((tile) => tile.remove());
        tiles.clear();
        state.zoom = next;
        const scale = world();
        state.lng = xToLng(lngToX(anchor.lng) - (ax - w / 2) / scale);
        state.lat = yToLat(mercatorY(anchor.lat) - (ay - h / 2) / scale);
        render();
    };

    const fit = () => {
        const { w, h } = size();
        if (!points.length) {
            state.lat = -2.5;
            state.lng = 118;
            state.zoom = 5;
            return render();
        }
        const lats = points.map((point) => mercatorY(point.lat));
        const lngs = points.map((point) => lngToX(point.lng));
        const spanX = Math.max(...lngs) - Math.min(...lngs);
        const spanY = Math.max(...lats) - Math.min(...lats);
        let zoom = 16;
        while (zoom > 2 && (spanX * TILE * 2 ** zoom > w - 120 || spanY * TILE * 2 ** zoom > h - 120)) zoom -= 1;
        state.zoom = zoom;
        tiles.forEach((tile) => tile.remove());
        tiles.clear();
        state.lng = xToLng((Math.max(...lngs) + Math.min(...lngs)) / 2);
        state.lat = yToLat((Math.max(...lats) + Math.min(...lats)) / 2);
        render();
    };

    let drag = null;
    locationMap.addEventListener('pointerdown', (event) => {
        if (event.target.closest('.map-controls')) return;
        drag = { x: event.clientX, y: event.clientY, moved: false };
        locationMap.setPointerCapture(event.pointerId);
        locationMap.classList.add('dragging');
    });
    locationMap.addEventListener('pointermove', (event) => {
        const rect = locationMap.getBoundingClientRect();
        const cursor = unproject(event.clientX - rect.left, event.clientY - rect.top);
        coordsLabel.textContent = cursor.lat.toFixed(5) + ', ' + cursor.lng.toFixed(5);
        if (!drag) return;
        const dx = event.clientX - drag.x;
        const dy = event.clientY - drag.y;
        if (Math.abs(dx) + Math.abs(dy) > 2) drag.moved = true;
        drag.x = event.clientX;
        drag.y = event.clientY;
        const scale = world();
        state.lng = xToLng(lngToX(state.lng) - dx / scale);
        state.lat = yToLat(Math.max(0, Math.min(1, mercatorY(state.lat) - dy / scale)));
        render();
    });
    const endDrag = () => {
        if (drag && !drag.moved) closePopup();
        drag = null;
        locationMap.classList.remove('dragging');
    };
    locationMap.addEventListener('pointerup', endDrag);
    locationMap.addEventListener('pointercancel', endDrag);
    locationMap.addEventListener('wheel', (event) => {
        event.preventDefault();
        const rect = locationMap.getBoundingClientRect();
        setZoom(state.zoom + (event.deltaY < 0 ? 1 : -1), event.clientX - rect.left, event.clientY - rect.top);
    }, { passive: false });
    locationMap.addEventListener('keydown', (event) => {
        if (event.key === '+' || event.key === '=') setZoom(state.zoom + 1);
        if (event.key === '-') setZoom(state.zoom - 1);
    });
    locationMap.querySelectorAll('[data-map-zoom]').forEach((button) => {
        button.addEventListener('click', () => setZoom(state.zoom + Number(button.dataset.mapZoom)));
    });
    locationMap.querySelector('[data-map-fit]').addEventListener('click', fit);
    document.querySelectorAll('[data-map-focus]').forEach((item) => {
        item.addEventListener('click', () => {
            const point = points.find((entry) => entry.id === Number(item.dataset.mapFocus));
            if (!point) return;
            state.lat = point.lat;
            state.lng = point.lng;
            if (state.zoom < 13) {
                tiles.forEach((tile) => tile.remove());
                tiles.clear();
                state.zoom = 13;
            }
            select(point.id);
        });
    });
    window.addEventListener('resize', render);
    fit();
}

(() => {
    const page = document.querySelector('[data-weather-page]');
    if (!page) return;
    const levels = JSON.parse(page.dataset.levels || '{}');
    const order = Object.keys(levels);
    const fmt = (value, digits = 1) => Number(value).toLocaleString('id-ID', { minimumFractionDigits: digits, maximumFractionDigits: digits });
    const levelOf = (rain) => order.reduce((found, key) => (levels[key].min > 0 && rain >= levels[key].min ? key : found), 'none');
    const dirs = ['U', 'TL', 'T', 'TG', 'S', 'BD', 'B', 'BL'];
    const set = (card, field, text) => {
        const node = card.querySelector('[data-field="' + field + '"]');
        if (node) node.textContent = text;
    };
    const clock = (iso) => new Date(iso).toLocaleTimeString('id-ID', { hour12: false, timeZone: 'Asia/Jakarta' }).replace(/\./g, ':');
    const sourceNode = page.querySelector('[data-weather-source]');

    const refresh = async () => {
        try {
            const response = await fetch(page.dataset.feedUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('feed');
            const data = await response.json();
            const rains = [];
            data.stations.forEach((row) => {
                const card = page.querySelector('[data-weather-station="' + CSS.escape(row.code) + '"]');
                if (!card) return;
                const rain = Number(row.rain_mm_h);
                rains.push(rain);
                const level = levelOf(rain);
                set(card, 'rain', fmt(rain));
                set(card, 'temp', fmt(row.temperature_c));
                set(card, 'hum', String(Math.round(row.humidity_pct)));
                set(card, 'wind', fmt(row.wind_kmh));
                set(card, 'dir', dirs[Math.round(((row.wind_deg % 360) + 360) % 360 / 45) % 8]);
                set(card, 'pres', fmt(row.pressure_hpa));
                set(card, 'time', clock(row.observed_at));
                const badge = card.querySelector('[data-field="level"]');
                badge.textContent = levels[level].label;
                badge.className = 'weather-level level-' + level;
            });
            const stat = (name, value) => { const node = page.querySelector('[data-weather-' + name + ']'); if (node) node.textContent = value; };
            stat('raining', String(rains.filter((v) => v >= 0.1).length));
            stat('max', fmt(rains.length ? Math.max(...rains) : 0));
            stat('avg', fmt(rains.length ? rains.reduce((a, b) => a + b, 0) / rains.length : 0));
            stat('updated', clock(data.generated_at));
            sourceNode.className = 'weather-source ' + (data.source === 'live' ? 'live' : 'dummy');
            sourceNode.lastChild.textContent = data.source === 'live' ? 'Sumber: API sensor' : 'Sumber: data simulasi';
        } catch (error) {
            sourceNode.className = 'weather-source error';
            sourceNode.lastChild.textContent = 'Gagal memuat data';
        }
    };
    refresh();
    setInterval(refresh, 15000);
})();


(() => {
    const page = document.querySelector('[data-river-page]');
    if (!page) return;
    const source = page.querySelector('[data-river-source]');
    const num = v =>
        v === null || v === undefined || v === '' ||
        !Number.isFinite(Number(v))
            ? null : Number(v);
    const fmt = v =>
        num(v) === null
            ? '—'
            : Number(v).toLocaleString('id-ID', {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1
            });
    const trendLabels = {
        NAIK: '▲ Naik',
        TURUN: '▼ Turun',
        STABIL: '■ Stabil'
    };
    const labels = {
        normal: 'Normal',
        watch: 'Waspada',
        alert: 'Siaga',
        warning: 'Awas'
    };
    const set = (card, field, value) => {
        const el = card.querySelector(`[data-field="${field}"]`);
        if (el) el.textContent = value;
    };
    const stat = (name, value) => {
        const el = page.querySelector(`[data-river-${name}]`);
        if (el) el.textContent = value;
    };
    function rulesValid(rules) {
        const w = num(rules.watch?.value);
        const a = num(rules.alert?.value);
        const d = num(rules.warning?.value);
        return w !== null && a !== null && d !== null &&
            w < a && a < d;
    }
    function levelOf(cm, rules) {
        if (!rulesValid(rules)) return null;
        let level = 'normal';
        for (const key of ['watch', 'alert', 'warning']) {
            const t = rules[key];
            if (t.op === '>'
                ? cm > t.value
                : cm >= t.value
            ) {
                level = key;
            }
        }
        return level;
    }
    async function refresh() {
        try {
            const response = await fetch(page.dataset.feedUrl, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' }
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const feed = await response.json();
            if (!Array.isArray(feed.stations)) {
                throw new Error('stations harus array');
            }
            let alerting = 0;
            let rising = 0;
            let max = null;
            for (const row of feed.stations) {
                const card = [
                    ...page.querySelectorAll('[data-river-station]')
                ].find(
                    el => el.dataset.riverStation === row.code
                );
                if (!card) continue;
                const cm = num(row.water_level_cm);
                const time = Date.parse(row.observed_at || '');
                const age = Date.now() - time;
                const fresh =
                    Number.isFinite(age) &&
                    age >= -60000 &&
                    age <= 15 * 60000;
                const rules = feed.thresholds?.[row.code] || {};
                const level = cm !== null && fresh
                    ? levelOf(cm, rules)
                    : null;
                const label = cm === null
                    ? 'DATA TIDAK TERSEDIA'
                    : !fresh
                        ? 'DATA TERLAMBAT'
                        : level === null
                            ? 'AMBANG BELUM DIATUR'
                            : labels[level];
                if (level && level !== 'normal') alerting++;
                if (fresh && row.trend === 'NAIK') rising++;
                if (cm !== null) {
                    max = Math.max(max ?? cm, cm);
                }
                set(card, 'level_cm', fmt(cm));
                set(card, 'distance', fmt(row.sensor_distance_cm));
                set(card, 'change', fmt(row.change_1h_cm));
                set(card, 'trend', trendLabels[row.trend] || '—');
                set(
                    card,
                    'time',
                    row.observed_at
                        ? row.observed_at.slice(11, 19) + ' WIB'
                        : '—'
                );
                const badge = card.querySelector('[data-field="level"]');
                if (badge) {
                    badge.textContent = label;
                    badge.className = 'weather-level' +
                        (level ? ` river-${level}` : '');
                }
                const top = Math.max(
                    300,
                    ...Object.values(rules).map(
                        t => num(t.value) ?? 0
                    )
                ) * 1.25;
                const pct = cm === null
                    ? 0
                    : Math.max(
                        0,
                        Math.min(100, Math.round(cm / top * 20) * 5)
                    );
                const gauge = card.querySelector('[data-field="gauge"]');
                if (gauge) {
                    gauge.className = 'river-fill' +
                        (level ? ` river-${level}` : '') +
                        ` bar-w-${pct}`;
                }
            }
            stat('alerting', String(alerting));
            stat('rising', String(rising));
            stat('max', fmt(max));
            stat('updated', new Intl.DateTimeFormat('id-ID', {
                timeZone: 'Asia/Jakarta',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hourCycle: 'h23'
            }).format(new Date()));
            if (source) {
                source.className = 'weather-source live';
                source.textContent = 'Sumber: API sensor';
            }
        } catch (err) {
            console.error('[RIVER DASHBOARD]', err);
            if (source) {
                source.className = 'weather-source error';
                source.textContent = 'Gagal mengambil data API';
            }
            page.querySelectorAll('[data-river-station]').forEach(card => {
                const badge = card.querySelector('[data-field="level"]');
                if (badge) {
                    badge.textContent = 'KONEKSI BERMASALAH';
                    badge.className = 'weather-level';
                }
            });
        }
    }
    refresh();
    setInterval(refresh, 300000);
})();


(() => {
    const page = document.querySelector('[data-tide-page]');
    if (!page) return;
    const levels = JSON.parse(page.dataset.levels || '{}');
    const fmt = (value, digits = 1) => Number(value).toLocaleString('id-ID', { minimumFractionDigits: digits, maximumFractionDigits: digits });
    const clock = (iso) => new Date(iso).toLocaleTimeString('id-ID', { hour12: false, timeZone: 'Asia/Jakarta' }).replace(/\./g, ':');
    const levelOf = (m, thresholds) => ['watch', 'alert', 'warning'].reduce((found, key) => {
        const t = thresholds[key];
        return t && (t.op === '>' ? m > t.value : m >= t.value) ? key : found;
    }, 'normal');
    const trends = { rising: '▲ Naik', falling: '▼ Surut', steady: '■ Stabil' };
    const source = page.querySelector('[data-tide-source]');
    const set = (card, field, text) => { const node = card.querySelector('[data-field="' + field + '"]'); if (node) node.textContent = text; };
    const stat = (name, value) => { const node = page.querySelector('[data-tide-' + name + ']'); if (node) node.textContent = value; };

    const refresh = async () => {
        try {
            const response = await fetch(page.dataset.feedUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('feed');
            const data = await response.json();
            let alerting = 0; let rising = 0; let max = 0;
            data.stations.forEach((row) => {
                const card = page.querySelector('[data-tide-station="' + CSS.escape(row.code) + '"]');
                if (!card) return;
                const thresholds = (data.thresholds && data.thresholds[row.code]) || {};
                const m = Number(row.tide_level_m);
                const change = Number(row.change_1h_m);
                const level = levelOf(m, thresholds);
                const trend = change >= 0.05 ? 'rising' : (change <= -0.05 ? 'falling' : 'steady');
                max = Math.max(max, m);
                if (level !== 'normal') alerting += 1;
                if (trend === 'rising') rising += 1;
                set(card, 'level_cm', fmt(m, 2));
                set(card, 'change', (change > 0 ? '+' : '') + fmt(change, 2));
                set(card, 'wave', fmt(row.wave_height_m));
                set(card, 'wind', fmt(row.wind_kmh));
                set(card, 'time', clock(row.observed_at));
                set(card, 'trend', trends[trend]);
                card.querySelector('[data-field="trend"]').className = 'river-trend trend-' + trend;
                const badge = card.querySelector('[data-field="level"]');
                badge.textContent = levels[level];
                badge.className = 'weather-level river-' + level;
                const top = Math.max(2, ...Object.values(thresholds).map((t) => t.value * 1.25));
                const pct = Math.min(100, Math.round(m / top * 100 / 5) * 5);
                card.querySelector('[data-field="gauge"]').className = 'river-fill river-' + level + ' bar-w-' + pct;
            });
            stat('alerting', String(alerting));
            stat('rising', String(rising));
            stat('max', fmt(max, 2));
            stat('updated', clock(data.generated_at));
            source.className = 'weather-source ' + (data.source === 'live' ? 'live' : 'dummy');
            source.lastChild.textContent = data.source === 'live' ? 'Sumber: API sensor' : 'Sumber: data simulasi';
        } catch (error) {
            source.className = 'weather-source error';
            source.lastChild.textContent = 'Gagal memuat data';
        }
    };
    setInterval(refresh, 15000);
})();

(() => {
    const page = document.querySelector('[data-tornado-page]');
    if (!page) return;
    const levels = JSON.parse(page.dataset.levels || '{}');
    const fmt = (value, digits = 1) => Number(value).toLocaleString('id-ID', { minimumFractionDigits: digits, maximumFractionDigits: digits });
    const clock = (iso) => new Date(iso).toLocaleTimeString('id-ID', { hour12: false, timeZone: 'Asia/Jakarta' }).replace(/\./g, ':');
    const levelOf = (m, thresholds) => ['watch', 'alert', 'warning'].reduce((found, key) => {
        const t = thresholds[key];
        return t && (t.op === '>' ? m > t.value : m >= t.value) ? key : found;
    }, 'normal');
    const trends = { rising: '▲ Menguat', falling: '▼ Melemah', steady: '■ Stabil' };
    const source = page.querySelector('[data-tornado-source]');
    const set = (card, field, text) => { const node = card.querySelector('[data-field="' + field + '"]'); if (node) node.textContent = text; };
    const stat = (name, value) => { const node = page.querySelector('[data-tornado-' + name + ']'); if (node) node.textContent = value; };

    const refresh = async () => {
        try {
            const response = await fetch(page.dataset.feedUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('feed');
            const data = await response.json();
            let alerting = 0; let rising = 0; let max = 0;
            data.stations.forEach((row) => {
                const card = page.querySelector('[data-tornado-station="' + CSS.escape(row.code) + '"]');
                if (!card) return;
                const thresholds = (data.thresholds && data.thresholds[row.code]) || {};
                const m = Number(row.wind_kmh);
                const change = Number(row.change_1h_kmh);
                const level = levelOf(m, thresholds);
                const trend = change >= 3 ? 'rising' : (change <= -3 ? 'falling' : 'steady');
                max = Math.max(max, m);
                if (level !== 'normal') alerting += 1;
                if (trend === 'rising') rising += 1;
                set(card, 'level_cm', fmt(m, 1));
                set(card, 'change', (change > 0 ? '+' : '') + fmt(change, 1));
                set(card, 'wave', fmt(row.gust_kmh));
                set(card, 'wind', fmt(row.pressure_hpa));
                set(card, 'time', clock(row.observed_at));
                set(card, 'trend', trends[trend]);
                card.querySelector('[data-field="trend"]').className = 'river-trend trend-' + trend;
                const badge = card.querySelector('[data-field="level"]');
                badge.textContent = levels[level];
                badge.className = 'weather-level river-' + level;
                const top = Math.max(60, ...Object.values(thresholds).map((t) => t.value * 1.25));
                const pct = Math.min(100, Math.round(m / top * 100 / 5) * 5);
                card.querySelector('[data-field="gauge"]').className = 'river-fill river-' + level + ' bar-w-' + pct;
            });
            stat('alerting', String(alerting));
            stat('rising', String(rising));
            stat('max', fmt(max, 1));
            stat('updated', clock(data.generated_at));
            source.className = 'weather-source ' + (data.source === 'live' ? 'live' : 'dummy');
            source.lastChild.textContent = data.source === 'live' ? 'Sumber: API sensor' : 'Sumber: data simulasi';
        } catch (error) {
            source.className = 'weather-source error';
            source.lastChild.textContent = 'Gagal memuat data';
        }
    };
    setInterval(refresh, 15000);
})();

// cuaca
(() => {
    const page = document.querySelector('[data-weather-monitor]');
    if (!page) return;
    const levels = JSON.parse(page.dataset.levels || '{}');
    const fmt = (value, digits = 1) => Number(value).toLocaleString('id-ID', { minimumFractionDigits: digits, maximumFractionDigits: digits });
    const clock = (iso) => new Date(iso).toLocaleTimeString('id-ID', { hour12: false, timeZone: 'Asia/Jakarta' }).replace(/\./g, ':');
    const dirs = ['U', 'TL', 'T', 'TG', 'S', 'BD', 'B', 'BL'];
    const keys = ['rain_mm_h', 'temperature_c', 'humidity_pct', 'wind_kmh', 'pressure_hpa'];
    const MAX_POINTS = 120;
    let series = [];
    const source = page.querySelector('[data-monitor-source]');

    const draw = (canvas) => {
        const key = canvas.dataset.chart;
        const digits = Number(canvas.dataset.digits);
        const color = canvas.dataset.color;
        const ratio = window.devicePixelRatio || 1;
        const width = canvas.clientWidth;
        const height = canvas.clientHeight;
        if (!width || !height) return;
        canvas.width = width * ratio;
        canvas.height = height * ratio;
        const ctx = canvas.getContext('2d');
        ctx.scale(ratio, ratio);
        ctx.clearRect(0, 0, width, height);
        const pad = { l: 46, r: 12, t: 10, b: 22 };
        const values = series.map((row) => Number(row[key]));
        if (values.length < 2) return;
        let min = Math.min(...values);
        let max = Math.max(...values);
        if (key === 'rain_mm_h') min = 0;
        if (max - min < 1e-6) { max += 1; min -= key === 'rain_mm_h' ? 0 : 1; }
        const span = max - min;
        min -= key === 'rain_mm_h' ? 0 : span * 0.1;
        max += span * 0.1;
        const x = (i) => pad.l + (width - pad.l - pad.r) * i / (values.length - 1);
        const y = (v) => pad.t + (height - pad.t - pad.b) * (1 - (v - min) / (max - min));
        ctx.font = '10px system-ui, sans-serif';
        ctx.fillStyle = '#75858a';
        ctx.strokeStyle = '#e4ebe8';
        ctx.lineWidth = 1;
        for (let g = 0; g <= 4; g += 1) {
            const v = min + (max - min) * g / 4;
            ctx.beginPath(); ctx.moveTo(pad.l, y(v)); ctx.lineTo(width - pad.r, y(v)); ctx.stroke();
            ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
            ctx.fillText(fmt(v, digits), pad.l - 6, y(v));
        }
        ctx.textBaseline = 'alphabetic';
        [0, Math.floor((values.length - 1) / 2), values.length - 1].forEach((i, n) => {
            ctx.textAlign = n === 0 ? 'left' : (n === 2 ? 'right' : 'center');
            ctx.fillText(clock(series[i].observed_at).slice(0, 5), x(i), height - 6);
        });
        ctx.beginPath();
        values.forEach((v, i) => (i ? ctx.lineTo(x(i), y(v)) : ctx.moveTo(x(i), y(v))));
        ctx.strokeStyle = color; ctx.lineWidth = 2; ctx.lineJoin = 'round'; ctx.stroke();
        ctx.lineTo(x(values.length - 1), y(min)); ctx.lineTo(x(0), y(min)); ctx.closePath();
        ctx.globalAlpha = 0.12; ctx.fillStyle = color; ctx.fill(); ctx.globalAlpha = 1;
        ctx.beginPath(); ctx.arc(x(values.length - 1), y(values[values.length - 1]), 3.5, 0, Math.PI * 2);
        ctx.fillStyle = color; ctx.fill();
    };

    const render = () => {
        page.querySelectorAll('canvas[data-chart]').forEach(draw);
        const last = series[series.length - 1];
        if (!last) return;
        const now = (name, text) => { const node = page.querySelector('[data-now="' + name + '"]'); if (node) node.textContent = text; };
        now('rain_mm_h', fmt(last.rain_mm_h));
        now('temperature_c', fmt(last.temperature_c));
        now('humidity_pct', String(Math.round(last.humidity_pct)));
        now('wind_kmh', fmt(last.wind_kmh));
        now('dir', dirs[Math.round(((last.wind_deg % 360) + 360) % 360 / 45) % 8]);
        now('pressure_hpa', fmt(last.pressure_hpa));
        now('observed_at', clock(last.observed_at));
        const level = Object.keys(levels).reduce((found, k) => (levels[k].min > 0 && last.rain_mm_h >= levels[k].min ? k : found), 'none');
        const badge = page.querySelector('[data-monitor-level]');
        badge.textContent = levels[level].label;
        badge.className = 'weather-level level-' + level;
        const body = page.querySelector('[data-monitor-rows]');
        body.replaceChildren(...series.slice(-12).reverse().map((row) => {
            const tr = document.createElement('tr');
            [clock(row.observed_at), fmt(row.rain_mm_h), fmt(row.temperature_c), String(Math.round(row.humidity_pct)), fmt(row.wind_kmh), fmt(row.pressure_hpa)].forEach((text) => {
                const td = document.createElement('td'); td.textContent = text; tr.append(td);
            });
            return tr;
        }));
    };

    let first = true;
    const refresh = async () => {
        try {
            const response = await fetch(first ? page.dataset.feedUrl : page.dataset.feedUrl.replace('&history=1', ''), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('feed');
            const data = await response.json();
            if (first && Array.isArray(data.history)) series = data.history.slice();
            first = false;
            const row = data.stations[0];
            if (row && !series.some((item) => item.observed_at === row.observed_at)) series.push(row);
            series = series.slice(-MAX_POINTS);
            page.querySelector('[data-monitor-clock]').textContent = clock(data.generated_at);
            source.className = 'weather-source ' + (data.source === 'live' ? 'live' : 'dummy');
            source.lastChild.textContent = data.source === 'live' ? 'Sumber: API sensor' : 'Sumber: data simulasi';
            render();
        } catch (error) {
            source.className = 'weather-source error';
            source.lastChild.textContent = 'Gagal memuat data';
        }
    };
    window.addEventListener('resize', render);
    refresh();
    setInterval(refresh, 5000);
})();

// Sungai

(() => {
    const page = document.querySelector('[data-river-monitor]');
    if (!page) return;

    const feedUrl = page.dataset.feedUrl;

    if (!feedUrl) {
        console.error('[RIVER] data-feed-url belum ada');
        return;
    }

    const source = page.querySelector('[data-monitor-source]');
    const badge = page.querySelector('[data-monitor-level]');
    const tbody = page.querySelector('[data-monitor-rows]');
    const count = page.querySelector('[data-monitor-count]');
    const dateLabel = page.querySelector('[data-monitor-date]');
    const pageInfo = page.querySelector('[data-page-info]');
    const numbers = page.querySelector('[data-page-numbers]');
    const prev = page.querySelector('[data-page-prev]');
    const next = page.querySelector('[data-page-next]');
    const clock = page.querySelector('[data-monitor-clock]');

    const canvases = [
        ...page.querySelectorAll('canvas[data-chart]')
    ];

    const hover = new WeakMap();
    const PAGE_SIZE = 10;

    const colors = {
        watch: '#d9822b',
        alert: '#c8541a',
        warning: '#b53a31'
    };

    const labels = {
        normal: 'NORMAL',
        watch: 'WASPADA',
        alert: 'SIAGA',
        warning: 'AWAS'
    };

    const trendLabels = {
        NAIK: '▲ Naik',
        TURUN: '▼ Turun',
        STABIL: '■ Stabil'
    };

    let rows = [];
    let thresholds = {};
    let activePage = 1;
    let activeDate = '';
    let loading = false;
    let fresh = false;
    let raf = null;
    let lastFrame = 0;

    const reducedMotion =
        window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
        ?? false;

    function num(v) {
        if (v === null || v === undefined || v === '') {
            return null;
        }

        const n = Number(v);
        return Number.isFinite(n) ? n : null;
    }

    function fmt(v, digits = 1) {
        return num(v) === null
            ? '—'
            : Number(v).toLocaleString('id-ID', {
                minimumFractionDigits: digits,
                maximumFractionDigits: digits
            });
    }

    function seconds(s) {
        const m = /^(\d{2}):(\d{2}):(\d{2})$/.exec(s || '');
        if (!m) return null;

        const [h, min, sec] = m.slice(1).map(Number);

        return h < 24 && min < 60 && sec < 60
            ? h * 3600 + min * 60 + sec
            : null;
    }

    function setNow(key, text) {
        const el = page.querySelector(`[data-now="${key}"]`);
        if (el) el.textContent = text;
    }

    function setSource(text, kind = 'dummy') {
        if (!source) return;

        source.className = `weather-source ${kind}`;
        source.textContent = text;
    }

    // Normalisasi data sungai ke sentimeter.
    function normalize(r) {
        const height =
            num(r.water_level_cm) ??
            (num(r.tinggi_air) === null
                ? null : num(r.tinggi_air) / 10) ??
            (num(r.tide_level_m) === null
                ? null : num(r.tide_level_m) * 100);

        const distance =
            num(r.sensor_distance_cm) ??
            (num(r.water_level) === null
                ? null : num(r.water_level) / 10) ??
            (num(r.sensor_distance_m) === null
                ? null : num(r.sensor_distance_m) * 100);

        const change =
            num(r.change_1h_cm) ??
            (num(r.change_1h_m) === null
                ? null : num(r.change_1h_m) * 100);

        const waktu = r.waktu ||
            (r.observed_at || '').slice(11, 19);

        return {
            id: r.id ?? null,
            tanggal: r.tanggal,
            waktu,

            observed_at: r.observed_at ||
                `${r.tanggal}T${waktu}+07:00`,

            water_level_cm: height,
            sensor_distance_cm: distance,
            change_1h_cm: change,

            trend: r.trend || 'TIDAK_ADA_DATA'
        };
    }

    // Ambang sungai sudah menggunakan cm.
    function getRules(feed) {
        const code = new URL(
            feedUrl,
            location.href
        ).searchParams.get('code');

        const raw = feed.thresholds?.[code] || {};
        const result = {};

        for (const k of ['watch', 'alert', 'warning']) {
            const value = num(raw[k]?.value);

            if (value !== null) {
                result[k] = {
                    value,
                    op: raw[k].op === '>' ? '>' : '>='
                };
            }
        }

        return result;
    }

    function levelOf(height) {
        if (height === null) return null;

        const w = thresholds.watch?.value;
        const a = thresholds.alert?.value;
        const d = thresholds.warning?.value;

        if (
            ![w, a, d].every(Number.isFinite) ||
            !(w < a && a < d)
        ) {
            return 'unconfigured';
        }

        let level = 'normal';

        for (const k of ['watch', 'alert', 'warning']) {
            const t = thresholds[k];

            if (
                t.op === '>'
                    ? height > t.value
                    : height >= t.value
            ) {
                level = k;
            }
        }

        return level;
    }

    // Menggambar grafik berdasarkan waktu WIB dalam sehari.
    function draw(canvas, tick = performance.now()) {
        const key = canvas.dataset.chart;
        const width = canvas.clientWidth;
        const height = canvas.clientHeight || 260;

        if (!width || !height) return;

        const ratio = Math.min(
            window.devicePixelRatio || 1,
            2
        );

        const pixelW = Math.round(width * ratio);
        const pixelH = Math.round(height * ratio);

        if (
            canvas.width !== pixelW ||
            canvas.height !== pixelH
        ) {
            canvas.width = pixelW;
            canvas.height = pixelH;
        }

        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.clearRect(0, 0, width, height);

        const left = 52;
        const right = 16;
        const top = 18;
        const bottom = 32;

        const plotW = width - left - right;
        const plotH = height - top - bottom;

        if (plotW <= 0 || plotH <= 0) return;

        const points = rows
            .map(r => ({
                t: seconds(r.waktu),
                v: num(r[key])
            }))
            .filter(p => p.t !== null && p.v !== null);

        const lines = key === 'water_level_cm'
            ? Object.entries(thresholds)
            : [];

        const values = [
            ...points.map(p => p.v),
            ...lines.map(([, t]) => t.value)
        ];

        if (!values.length) return;

        let min = Math.min(...values);
        let max = Math.max(...values);

        if (max - min < 0.01) {
            min -= 5;
            max += 5;
        }

        const margin = (max - min) * 0.1;

        min -= margin;
        max += margin;

        const x = t => left + t / 86400 * plotW;

        const y = v =>
            top + (1 - (v - min) / (max - min)) * plotH;

        ctx.font = '11px system-ui, sans-serif';

        // Garis bantu horizontal dan nilai cm.
        for (let i = 0; i <= 4; i++) {
            const v = min + (max - min) * i / 4;

            ctx.strokeStyle = '#e4ebe8';
            ctx.lineWidth = 1;

            ctx.beginPath();
            ctx.moveTo(left, y(v));
            ctx.lineTo(width - right, y(v));
            ctx.stroke();

            ctx.textAlign = 'right';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = '#75858a';

            ctx.fillText(fmt(v), left - 6, y(v));
        }

        // Sumbu waktu 24 jam.
        [
            [0, '00:00'],
            [21600, '06:00'],
            [43200, '12:00'],
            [64800, '18:00'],
            [86400, '24:00']
        ].forEach(([t, label]) => {
            ctx.textBaseline = 'alphabetic';

            ctx.textAlign = t === 0
                ? 'left'
                : t === 86400
                    ? 'right'
                    : 'center';

            ctx.fillText(label, x(t), height - 8);
        });

        // Garis ambang jika tersedia.
        for (const [sev, rule] of lines) {
            ctx.save();
            ctx.setLineDash([5, 4]);

            ctx.strokeStyle = colors[sev] || '#999';

            ctx.beginPath();
            ctx.moveTo(left, y(rule.value));
            ctx.lineTo(width - right, y(rule.value));
            ctx.stroke();

            ctx.restore();
        }

        if (!points.length) return;

        const color = canvas.dataset.color || '#2f80c9';

        // Garis data. Putus jika jeda lebih dari 15 menit.
        ctx.beginPath();

        points.forEach((p, i) => {
            if (
                i === 0 ||
                p.t - points[i - 1].t > 900
            ) {
                ctx.moveTo(x(p.t), y(p.v));
            } else {
                ctx.lineTo(x(p.t), y(p.v));
            }
        });

        ctx.strokeStyle = color;
        ctx.lineWidth = 2;
        ctx.lineJoin = 'round';
        ctx.stroke();

        // Titik pengukuran terbaru.
        const last = points[points.length - 1];

        const lx = x(last.t);
        const ly = y(last.v);

        // Ripple lembut: 5 detik per putaran.
        // Tidak dianimasikan untuk data yang sudah terlambat.
        if (fresh && !reducedMotion) {
            const phase = (tick % 5000) / 5000;

            [phase, (phase + 0.5) % 1].forEach((p, i) => {
                ctx.save();

                ctx.globalAlpha =
                    (i === 0 ? 0.55 : 0.40) * (1 - p);

                ctx.strokeStyle = color;
                ctx.lineWidth = i === 0 ? 3.5 : 3;

                ctx.beginPath();
                ctx.arc(
                    lx, ly,
                    5 + 18 * p,
                    0, Math.PI * 2
                );
                ctx.stroke();

                ctx.restore();
            });
        }

        ctx.beginPath();
        ctx.arc(lx, ly, 5, 0, Math.PI * 2);
        ctx.fillStyle = color;
        ctx.fill();

        ctx.beginPath();
        ctx.arc(lx, ly, 1.7, 0, Math.PI * 2);
        ctx.fillStyle = '#fff';
        ctx.fill();

        // Tooltip ketika mouse berada dekat garis.
        const cursor = hover.get(canvas);
        if (!cursor) return;

        let nearest = null;
        let minDist = 28 * 28;

        for (const p of points) {
            const dist =
                (x(p.t) - cursor.x) ** 2 +
                (y(p.v) - cursor.y) ** 2;

            if (dist < minDist) {
                nearest = p;
                minDist = dist;
            }
        }

        if (!nearest) return;

        const px = x(nearest.t);
        const py = y(nearest.v);

        const hh = String(
            Math.floor(nearest.t / 3600)
        ).padStart(2, '0');

        const mm = String(
            Math.floor(nearest.t % 3600 / 60)
        ).padStart(2, '0');

        const ss = String(
            nearest.t % 60
        ).padStart(2, '0');

        const title = ({
            water_level_cm: 'Tinggi muka air',
            sensor_distance_cm: 'Jarak radar',
            change_1h_cm: 'Perubahan 1 jam'
        })[key] || 'Pengukuran';

        const timeText = `${hh}:${mm}:${ss} WIB`;
        const valueText = `${fmt(nearest.v)} cm`;

        ctx.save();

        // Garis vertikal menuju waktu pengukuran.
        ctx.setLineDash([4, 4]);
        ctx.strokeStyle = '#84969e';
        ctx.lineWidth = 1;

        ctx.beginPath();
        ctx.moveTo(px, top);
        ctx.lineTo(px, height - bottom);
        ctx.stroke();

        ctx.setLineDash([]);

        // Lingkaran titik yang sedang dipilih.
        ctx.beginPath();
        ctx.arc(px, py, 6, 0, Math.PI * 2);
        ctx.fillStyle = '#fff';
        ctx.fill();

        ctx.lineWidth = 2.5;
        ctx.strokeStyle = color;
        ctx.stroke();

        // Kotak tooltip.
        ctx.font = '12px system-ui, sans-serif';

        const boxW = Math.min(
            width - 16,
            Math.max(
                160,
                ctx.measureText(title).width + 24
            )
        );

        const boxH = 82;

        const bx = Math.max(
            8,
            Math.min(px + 14, width - boxW - 8)
        );

        const by = Math.max(
            8,
            Math.min(
                py - boxH - 12 < 8
                    ? py + 12
                    : py - boxH - 12,
                height - boxH - 8
            )
        );

        ctx.fillStyle = 'rgba(20,43,56,.96)';
        ctx.beginPath();
        ctx.roundRect(bx, by, boxW, boxH, 8);
        ctx.fill();

        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#fff';

        ctx.font = 'bold 12px system-ui, sans-serif';
        ctx.fillText(title, bx + 12, by + 18);

        ctx.font = '12px system-ui, sans-serif';
        ctx.fillText(timeText, bx + 12, by + 42);

        ctx.font = 'bold 13px system-ui, sans-serif';
        ctx.fillText(valueText, bx + 12, by + 64);

        ctx.restore();
    }

    function drawAll(t) {
        canvases.forEach(c => draw(c, t));
    }

    // Animasi maksimal sekitar 20 FPS untuk meringankan CPU.
    function animate(t) {
        if (!document.hidden && t - lastFrame >= 50) {
            lastFrame = t;
            drawAll(t);
        }

        raf = requestAnimationFrame(animate);
    }

    function startAnimation() {
        if (raf === null && !reducedMotion && !document.hidden) {
            raf = requestAnimationFrame(animate);
        } else if (reducedMotion) {
            drawAll();
        }
    }

    function stopAnimation() {
        if (raf !== null) {
            cancelAnimationFrame(raf);
        }

        raf = null;
    }

    // Pagination 10 baris.
    function renderTable() {
        if (!tbody) return;

        const pages = Math.max(
            1,
            Math.ceil(rows.length / PAGE_SIZE)
        );

        activePage = Math.max(
            1,
            Math.min(activePage, pages)
        );

        if (count) {
            count.textContent = String(rows.length);
        }

        if (pageInfo) {
            pageInfo.textContent =
                `Halaman ${activePage} dari ${pages}`;
        }

        if (prev) prev.disabled = activePage <= 1;
        if (next) next.disabled = activePage >= pages;

        if (numbers) {
            numbers.replaceChildren();

            const from = Math.max(
                1,
                Math.min(activePage - 2, pages - 4)
            );

            for (
                let p = from;
                p <= Math.min(pages, from + 4);
                p++
            ) {
                const b = document.createElement('button');

                b.type = 'button';
                b.className = 'button secondary';
                b.textContent = String(p);
                b.disabled = p === activePage;
                b.setAttribute(
                    'aria-label',
                    `Halaman ${p}`
                );

                b.addEventListener('click', () => {
                    activePage = p;
                    renderTable();
                });

                numbers.append(b);
            }
        }

        tbody.replaceChildren();

        const visible = rows.slice().reverse().slice(
            (activePage - 1) * PAGE_SIZE,
            activePage * PAGE_SIZE
        );

        if (!visible.length) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');

            td.colSpan = 5;
            td.textContent = 'Belum ada data hari ini';

            tr.append(td);
            tbody.append(tr);
            return;
        }

        for (const r of visible) {
            const tr = document.createElement('tr');

            [
                r.waktu,
                fmt(r.water_level_cm),
                fmt(r.sensor_distance_cm),
                fmt(r.change_1h_cm),
                trendLabels[r.trend] || '—'
            ].forEach(value => {
                const td = document.createElement('td');
                td.textContent = String(value);
                tr.append(td);
            });

            tbody.append(tr);
        }
    }

    function render() {
        drawAll();
        renderTable();

        const last = rows[rows.length - 1];

        setNow('water_level_cm', fmt(last?.water_level_cm));
        setNow('sensor_distance_cm', fmt(last?.sensor_distance_cm));
        setNow('change_1h_cm', fmt(last?.change_1h_cm));

        setNow(
            'trend',
            trendLabels[last?.trend] || '—'
        );

        setNow(
            'observed_at',
            last?.waktu ? `${last.waktu} WIB` : '—'
        );

        if (!last) {
            fresh = false;

            if (badge) {
                badge.textContent = 'BELUM ADA DATA';
            }

            setSource('Belum ada data hari ini');
            return;
        }

        const age = Date.now()
            - Date.parse(last.observed_at || '');
        fresh =
            Number.isFinite(age) &&
            age >= -60000 &&
            age <= 15 * 60000;
        const level = levelOf(last.water_level_cm);
        if (badge) {
            badge.textContent =
                last.water_level_cm === null
                    ? 'DATA SENSOR TIDAK VALID'
                    : !fresh
                        ? 'DATA TERLAMBAT'
                        : level === 'unconfigured'
                            ? 'AMBANG BELUM DIATUR'
                            : labels[level] || '—';
            badge.className = `weather-level${
                fresh && labels[level]
                    ? ' river-' + level
                    : ''
            }`;
        }
        if (last.water_level_cm === null) {
            setSource('Data sensor tidak valid', 'error');
        } else if (!fresh) {
            setSource('Data pengukuran terlambat', 'dummy');
        } else {
            setSource('Data API tersedia', 'live');
        }
    }
    async function refresh() {
        if (loading) return;
        loading = true;
        try {
            const response = await fetch(feedUrl, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json'
                }
            });
            const raw = await response.text();
            if (!response.ok) {
                throw new Error(
                    `HTTP ${response.status}: ${raw.slice(0, 120)}`
                );
            }
            let feed;
            try {
                feed = JSON.parse(raw);
            } catch {
                throw new Error(
                    `Respons bukan JSON: ${raw.slice(0, 160)}`
                );
            }
            const data = Array.isArray(feed.history) ? feed.history : feed.data;
            if (!Array.isArray(data)) {
                throw new Error('history/data bukan array');
            }
            if (feed.date !== activeDate) {
                activeDate = feed.date;
                activePage = 1;
            }
            rows = data
                .filter(r =>
                    r && r.tanggal === feed.date
                )
                .map(normalize)
                .filter(r =>
                    seconds(r.waktu) !== null
                )
                .sort((a, b) =>
                    seconds(a.waktu) - seconds(b.waktu) ||
                    Number(a.id || 0) - Number(b.id || 0)
                );
            thresholds = getRules(feed);
            if (dateLabel) {
                dateLabel.textContent = feed.date || '—';
            }
            render();
        } catch (err) {
            console.error('[RIVER FEED]', err);
            fresh = false;
            setSource('Gagal mengambil data API', 'error');
            if (badge) {
                badge.textContent = 'KONEKSI BERMASALAH';
                badge.className = 'weather-level';
            }
        } finally {
            loading = false;
        }
    }
    prev?.addEventListener('click', () => {
        activePage--;
        renderTable();
    });
    next?.addEventListener('click', () => {
        activePage++;
        renderTable();
    });
    canvases.forEach(canvas => {
        canvas.style.cursor = 'crosshair';
        const update = event => {
            const rect = canvas.getBoundingClientRect();
            if (!rect.width || !rect.height) return;
            hover.set(canvas, {
                x: (event.clientX - rect.left) *
                    canvas.clientWidth / rect.width,
                y: (event.clientY - rect.top) *
                    canvas.clientHeight / rect.height
            });
            if (raf === null) {
                draw(canvas);
            }
        };
        canvas.addEventListener('pointermove', event => {
            if (event.pointerType !== 'touch') {
                update(event);
            }
        });
        canvas.addEventListener('pointerdown', update);
        canvas.addEventListener('pointerleave', () => {
            hover.delete(canvas);
            draw(canvas);
        });
    });
    const updateClock = () => {
        if (clock) {
            clock.textContent = new Intl.DateTimeFormat('id-ID', {
                timeZone: 'Asia/Jakarta',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hourCycle: 'h23'
            }).format(new Date());
        }
    };
    updateClock();
    setInterval(updateClock, 1000);
    window.addEventListener('resize', () => {
        drawAll();
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopAnimation();
        } else {
            refresh();
            startAnimation();
        }
    });
    refresh();
    startAnimation();
    setInterval(refresh, 300000);
})();


// Tornado
(() => {
    const page = document.querySelector('[data-tornado-monitor]');
    if (!page) return;
    const levels = JSON.parse(page.dataset.levels || '{}');
    const fmt = (value, digits = 1) => Number(value).toLocaleString('id-ID', { minimumFractionDigits: digits, maximumFractionDigits: digits });
    const clock = (iso) => new Date(iso).toLocaleTimeString('id-ID', { hour12: false, timeZone: 'Asia/Jakarta' }).replace(/\./g, ':');
    const levelColors = { watch: '#d9822b', alert: '#c8541a', warning: '#b53a31' };
    const trends = { rising: '▲ Menguat', falling: '▼ Melemah', steady: '■ Stabil' };
    const MAX_POINTS = 120;
    let series = [];
    let thresholds = {};
    const source = page.querySelector('[data-monitor-source]');
    const levelOf = (cm) => ['watch', 'alert', 'warning'].reduce((found, key) => {
        const t = thresholds[key];
        return t && (t.op === '>' ? cm > t.value : cm >= t.value) ? key : found;
    }, 'normal');

    const draw = (canvas) => {
        const key = canvas.dataset.chart;
        const digits = Number(canvas.dataset.digits);
        const color = canvas.dataset.color;
        const lines = canvas.dataset.thresholds ? Object.entries(thresholds) : [];
        const ratio = window.devicePixelRatio || 1;
        const width = canvas.clientWidth;
        const height = canvas.clientHeight;
        if (!width || !height) return;
        canvas.width = width * ratio;
        canvas.height = height * ratio;
        const ctx = canvas.getContext('2d');
        ctx.scale(ratio, ratio);
        ctx.clearRect(0, 0, width, height);
        const pad = { l: 46, r: 12, t: 10, b: 22 };
        const values = series.map((row) => Number(row[key]));
        if (values.length < 2) return;
        let min = Math.min(...values, ...lines.map(([, t]) => t.value));
        let max = Math.max(...values, ...lines.map(([, t]) => t.value));
        if (max - min < 1e-6) { max += 1; min -= 1; }
        const span = max - min;
        min -= span * 0.08;
        max += span * 0.08;
        const x = (i) => pad.l + (width - pad.l - pad.r) * i / (values.length - 1);
        const y = (v) => pad.t + (height - pad.t - pad.b) * (1 - (v - min) / (max - min));
        ctx.font = '10px system-ui, sans-serif';
        ctx.fillStyle = '#75858a';
        ctx.strokeStyle = '#e4ebe8';
        ctx.lineWidth = 1;
        for (let g = 0; g <= 4; g += 1) {
            const v = min + (max - min) * g / 4;
            ctx.beginPath(); ctx.moveTo(pad.l, y(v)); ctx.lineTo(width - pad.r, y(v)); ctx.stroke();
            ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
            ctx.fillText(fmt(v, digits), pad.l - 6, y(v));
        }
        ctx.textBaseline = 'alphabetic';
        [0, Math.floor((values.length - 1) / 2), values.length - 1].forEach((i, n) => {
            ctx.textAlign = n === 0 ? 'left' : (n === 2 ? 'right' : 'center');
            ctx.fillText(clock(series[i].observed_at).slice(0, 5), x(i), height - 6);
        });
        lines.forEach(([sev, t]) => {
            ctx.save();
            ctx.setLineDash([5, 4]); ctx.strokeStyle = levelColors[sev]; ctx.lineWidth = 1.2;
            ctx.beginPath(); ctx.moveTo(pad.l, y(t.value)); ctx.lineTo(width - pad.r, y(t.value)); ctx.stroke();
            ctx.setLineDash([]); ctx.fillStyle = levelColors[sev]; ctx.textAlign = 'right';
            ctx.fillText(levels[sev] + ' ' + fmt(t.value, 0), width - pad.r - 2, y(t.value) - 3);
            ctx.restore();
        });
        ctx.beginPath();
        values.forEach((v, i) => (i ? ctx.lineTo(x(i), y(v)) : ctx.moveTo(x(i), y(v))));
        ctx.strokeStyle = color; ctx.lineWidth = 2; ctx.lineJoin = 'round'; ctx.stroke();
        ctx.beginPath(); ctx.arc(x(values.length - 1), y(values[values.length - 1]), 3.5, 0, Math.PI * 2);
        ctx.fillStyle = color; ctx.fill();
    };

    const render = () => {
        page.querySelectorAll('canvas[data-chart]').forEach(draw);
        const last = series[series.length - 1];
        if (!last) return;
        const now = (name, text) => { const node = page.querySelector('[data-now="' + name + '"]'); if (node) node.textContent = text; };
        now('wind_kmh', fmt(last.wind_kmh));
        now('change_1h_kmh', (last.change_1h_kmh > 0 ? '+' : '') + fmt(last.change_1h_kmh));
        now('gust_kmh', fmt(last.gust_kmh));
        now('pressure_hpa', fmt(last.pressure_hpa));
        now('observed_at', clock(last.observed_at));
        const change = Number(last.change_1h_kmh);
        const trend = change >= 3 ? 'rising' : (change <= -3 ? 'falling' : 'steady');
        now('trend', trends[trend]);
        page.querySelector('[data-now="trend"]').className = 'river-trend trend-' + trend;
        const level = levelOf(Number(last.wind_kmh));
        const badge = page.querySelector('[data-monitor-level]');
        badge.textContent = levels[level];
        badge.className = 'weather-level river-' + level;
        const body = page.querySelector('[data-monitor-rows]');
        body.replaceChildren(...series.slice(-12).reverse().map((row) => {
            const tr = document.createElement('tr');
            [clock(row.observed_at), fmt(row.wind_kmh), (row.change_1h_kmh > 0 ? '+' : '') + fmt(row.change_1h_kmh), fmt(row.gust_kmh), fmt(row.pressure_hpa)].forEach((text) => {
                const td = document.createElement('td'); td.textContent = text; tr.append(td);
            });
            return tr;
        }));
    };

    let first = true;
    const refresh = async () => {
        try {
            const response = await fetch(first ? page.dataset.feedUrl : page.dataset.feedUrl.replace('&history=1', ''), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('feed');
            const data = await response.json();
            if (first && Array.isArray(data.history)) series = data.history.slice();
            first = false;
            const row = data.stations[0];
            if (row) {
                thresholds = (data.thresholds && data.thresholds[row.code]) || {};
                if (!series.some((item) => item.observed_at === row.observed_at)) series.push(row);
            }
            series = series.slice(-MAX_POINTS);
            page.querySelector('[data-monitor-clock]').textContent = clock(data.generated_at);
            source.className = 'weather-source ' + (data.source === 'live' ? 'live' : 'dummy');
            source.lastChild.textContent = data.source === 'live' ? 'Sumber: API sensor' : 'Sumber: data simulasi';
            render();
        } catch (error) {
            source.className = 'weather-source error';
            source.lastChild.textContent = 'Gagal memuat data';
        }
    };
    window.addEventListener('resize', render);
    refresh();
    setInterval(refresh, 5000);
})();


document.addEventListener('submit', (event) => {
    const message = event.target.getAttribute && event.target.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});

(function () {
    const blocks = document.querySelectorAll('[data-qr-path]');
    if (blocks.length && typeof qrcode === 'function') {
        blocks.forEach((block) => {
            const qr = qrcode(0, 'M');
            qr.addData(window.location.origin + block.getAttribute('data-qr-path'));
            qr.make();
            block.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 0, scalable: true });
        });
    }
    if (document.body.hasAttribute('data-autoprint')) {
        window.addEventListener('load', () => setTimeout(() => window.print(), 300));
    }
    document.querySelectorAll('[data-print]').forEach((button) => {
        button.addEventListener('click', () => window.print());
    });
})();

(() => {
    const canvases = document.querySelectorAll('canvas[data-history]');
    const fmt = (ts) => new Date(ts * 1000).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
    const draw = (canvas) => {
        const points = JSON.parse(canvas.dataset.history || '[]');
        if (points.length === 0) return;
        const ratio = window.devicePixelRatio || 1;
        const width = canvas.clientWidth;
        const height = 260;
        canvas.width = width * ratio;
        canvas.height = height * ratio;
        const ctx = canvas.getContext('2d');
        ctx.scale(ratio, ratio);
        const pad = { l: 48, r: 12, t: 12, b: 28 };
        const xs = points.map((p) => p[0]);
        const ys = points.map((p) => p[1]);
        const x0 = Math.min(...xs), x1 = Math.max(...xs) || x0 + 1;
        let y0 = Math.min(...ys), y1 = Math.max(...ys);
        if (y0 === y1) { y0 -= 1; y1 += 1; }
        const px = (x) => pad.l + ((x - x0) / (x1 - x0 || 1)) * (width - pad.l - pad.r);
        const py = (y) => pad.t + (1 - (y - y0) / (y1 - y0)) * (height - pad.t - pad.b);
        ctx.clearRect(0, 0, width, height);
        ctx.font = '11px sans-serif';
        ctx.fillStyle = '#5b6b60';
        ctx.strokeStyle = '#e1e8e2';
        for (let i = 0; i <= 4; i++) {
            const v = y0 + ((y1 - y0) * i) / 4;
            const y = py(v);
            ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(width - pad.r, y); ctx.stroke();
            ctx.fillText(v.toFixed(1), 4, y + 4);
        }
        ctx.fillText(fmt(x0), pad.l, height - 8);
        ctx.textAlign = 'right';
        ctx.fillText(fmt(x1), width - pad.r, height - 8);
        ctx.textAlign = 'left';
        ctx.strokeStyle = '#1f7a4d';
        ctx.lineWidth = 2;
        ctx.beginPath();
        points.forEach((p, i) => (i === 0 ? ctx.moveTo(px(p[0]), py(p[1])) : ctx.lineTo(px(p[0]), py(p[1]))));
        ctx.stroke();
        if (points.length === 1) { ctx.fillStyle = '#1f7a4d'; ctx.fillRect(px(points[0][0]) - 2, py(points[0][1]) - 2, 4, 4); }
    };
    canvases.forEach(draw);
    window.addEventListener('resize', () => canvases.forEach(draw));
})();


// Pasang Surut Air Laut
(() => {
    const page = document.querySelector('[data-tide-live-monitor]');
    if (!page) return;
    const feedUrl = page.dataset.feedUrl;
    const source = page.querySelector('[data-monitor-source]');
    const badge = page.querySelector('[data-monitor-level]');
    const tbody = page.querySelector('[data-monitor-rows]');
    const count = page.querySelector('[data-monitor-count]');
    const dateLabel = page.querySelector('[data-monitor-date]');
    const pageInfo = page.querySelector('[data-page-info]');
    const pageNumbers = page.querySelector('[data-page-numbers]');
    const prevButton = page.querySelector('[data-page-prev]');
    const nextButton = page.querySelector('[data-page-next]');
    const clock = page.querySelector('[data-monitor-clock]');
    const PAGE_SIZE = 10;
    let rows = [];
    let thresholds = {};
    let activePage = 1;
    let activeDate = '';
    let loading = false;
    let animationFrameId = null;
    let pulsePhase = 0;
    const hoverPositions = new WeakMap();
    const num = value => {
        if (value === null || value === undefined || value === '') {
            return null;
        }
        const n = Number(value);
        return Number.isFinite(n) ? n : null;
    };
    const fmt = (value, digits = 1) =>
        typeof value === 'number' && Number.isFinite(value)
            ? value.toLocaleString('id-ID', {
                minimumFractionDigits: digits,
                maximumFractionDigits: digits
            })
            : '—';
    const timeSeconds = waktu => {
        const m = /^(\d{2}):(\d{2}):(\d{2})$/.exec(waktu || '');
        if (!m) return null;
        const [h, min, s] = m.slice(1).map(Number);
        return h < 24 && min < 60 && s < 60
            ? h * 3600 + min * 60 + s
            : null;
    };
    const setNow = (key, value) => {
        const el = page.querySelector(`[data-now="${key}"]`);
        if (el) el.textContent = value;
    };
    const setSource = (text, state = 'dummy') => {
        if (!source) return;
        source.className = 'weather-source ' + state;
        source.textContent = text;
    };
    function normalize(item) {
        const heightM = num(item.tide_level_m);
        const distanceM = num(item.sensor_distance_m);
        const changeM = num(item.change_1h_m);
        const heightMm = num(item.tinggi_air);
        const distanceMm = num(item.water_level);
        return {
            id: item.id ?? null,
            tanggal: item.tanggal,
            waktu: item.waktu || item.observed_at?.slice(11, 19) || '',
            observed_at: item.observed_at,
            tide_level_cm: heightM !== null
                ? heightM * 100
                : heightMm === null ? null : heightMm / 10,
            sensor_distance_cm: distanceM !== null
                ? distanceM * 100
                : distanceMm === null ? null : distanceMm / 10,
            change_1h_cm: changeM === null
                ? null : changeM * 100,
            trend: item.trend || 'TIDAK_ADA_DATA'
        };
    }
    function readThresholds(feed) {
        const code = new URL(
            feedUrl,
            location.href
        ).searchParams.get('code');
        const data = feed.thresholds?.[code] || {};
        const result = {};
        for (const key of ['watch', 'alert', 'warning']) {
            const value = num(data[key]?.value);
            if (value !== null) {
                result[key] = {
                    value: value * 100,
                    op: data[key].op === '>' ? '>' : '>='
                };
            }
        }
        return result;
    }
    function waterStatus(height) {
        if (height === null) return 'INVALID';
        if (!Object.keys(thresholds).length) {
            return 'UNCONFIGURED';
        }
        let status = 'normal';
        for (const key of ['watch', 'alert', 'warning']) {
            const rule = thresholds[key];
            if (
                rule &&
                (rule.op === '>'
                    ? height > rule.value
                    : height >= rule.value)
            ) {
                status = key;
            }
        }
        return status;
    }
    function draw(canvas) {
        const key = canvas.dataset.chart;
        const width = canvas.clientWidth;
        const height = canvas.clientHeight || 260;
        if (!width || !height) return;
        const ratio = Math.min(window.devicePixelRatio || 1, 3);
        canvas.width = Math.round(width * ratio);
        canvas.height = Math.round(height * ratio);
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.clearRect(0, 0, width, height);
        const left = 52;
        const right = 16;
        const top = 18;
        const bottom = 32;
        const plotW = width - left - right;
        const plotH = height - top - bottom;
        if (plotW <= 0 || plotH <= 0) return;
        const points = rows
            .map(r => ({
                t: timeSeconds(r.waktu),
                v: num(r[key])
            }))
            .filter(p => p.t !== null && p.v !== null);
        const lines = key === 'tide_level_cm'
            ? Object.entries(thresholds)
            : [];
        const values = [
            ...points.map(p => p.v),
            ...lines.map(([, rule]) => rule.value)
        ];
        if (!values.length) return;
        let min = Math.min(...values);
        let max = Math.max(...values);
        if (max - min < 0.01) {
            min -= 5;
            max += 5;
        }
        const margin = (max - min) * 0.1;
        min -= margin;
        max += margin;
        const x = t => left + (t / 86400) * plotW;
        const y = v =>
            top + (1 - (v - min) / (max - min)) * plotH;
        ctx.font = '11px system-ui, sans-serif';
        ctx.fillStyle = '#75858a';
        ctx.strokeStyle = '#e4ebe8';
        ctx.lineWidth = 1;
        for (let i = 0; i <= 4; i++) {
            const v = min + (max - min) * i / 4;
            ctx.beginPath();
            ctx.moveTo(left, y(v));
            ctx.lineTo(width - right, y(v));
            ctx.stroke();
            ctx.textAlign = 'right';
            ctx.textBaseline = 'middle';
            ctx.fillText(fmt(v), left - 6, y(v));
        }
        const labels = [
            [0, '00:00'],
            [21600, '06:00'],
            [43200, '12:00'],
            [64800, '18:00'],
            [86400, '24:00']
        ];
        labels.forEach(([t, label]) => {
            ctx.textBaseline = 'alphabetic';
            ctx.textAlign = t === 0
                ? 'left'
                : t === 86400 ? 'right' : 'center';
            ctx.fillText(label, x(t), height - 8);
        });
        const colors = {
            watch: '#d9822b',
            alert: '#c8541a',
            warning: '#b53a31'
        };
        for (const [status, rule] of lines) {
            ctx.save();
            ctx.setLineDash([5, 4]);
            ctx.strokeStyle = colors[status];
            ctx.beginPath();
            ctx.moveTo(left, y(rule.value));
            ctx.lineTo(width - right, y(rule.value));
            ctx.stroke();
            ctx.restore();
        }
        if (!points.length) return;
        ctx.beginPath();
        points.forEach((p, i) => {
            if (
                i === 0 ||
                p.t - points[i - 1].t > 900
            ) {
                ctx.moveTo(x(p.t), y(p.v));
            } else {
                ctx.lineTo(x(p.t), y(p.v));
            }
        });
        ctx.strokeStyle = canvas.dataset.color || '#2f80c9';
        ctx.lineWidth = 2;
        ctx.lineJoin = 'round';
        ctx.stroke();

        const last = points[points.length - 1];
        const lastX = x(last.t);
        const lastY = y(last.v);
        const dotColor = canvas.dataset.color || '#2f80c9';

        const ripple1 = (pulsePhase % 1);
        const radius1 = 4 + ripple1 * 14;
        const alpha1 = 0.35 * (1 - ripple1);

        ctx.beginPath();
        ctx.arc(lastX, lastY, radius1, 0, Math.PI * 2);
        ctx.strokeStyle = `rgba(47, 128, 201, ${alpha1})`;
        ctx.lineWidth = 2;
        ctx.stroke();

        const ripple2 = ((pulsePhase + 0.5) % 1);
        const radius2 = 4 + ripple2 * 14;
        const alpha2 = 0.25 * (1 - ripple2);

        ctx.beginPath();
        ctx.arc(lastX, lastY, radius2, 0, Math.PI * 2);
        ctx.strokeStyle = `rgba(47, 128, 201, ${alpha2})`;
        ctx.lineWidth = 2;
        ctx.stroke();

        ctx.beginPath();
        ctx.arc(lastX, lastY, 4.5, 0, Math.PI * 2);
        ctx.fillStyle = dotColor;
        ctx.fill();

        ctx.beginPath();
        ctx.arc(lastX, lastY, 1.6, 0, Math.PI * 2);
        ctx.fillStyle = '#ffffff';
        ctx.fill();
        const hover = hoverPositions.get(canvas);
        if (!hover) return;
        let nearest = null;
        let nearestDistance = 28 * 28;
        for (const point of points) {
            const dx = x(point.t) - hover.x;
            const dy = y(point.v) - hover.y;
            const distance = dx * dx + dy * dy;

            if (distance < nearestDistance) {
                nearestDistance = distance;
                nearest = point;
            }
        }
        if (!nearest) return;
        const px = x(nearest.t);
        const py = y(nearest.v);
        const hours = String(Math.floor(nearest.t / 3600)).padStart(2, '0');
        const minutes = String(Math.floor((nearest.t % 3600) / 60)).padStart(2, '0');
        const seconds = String(
            nearest.t % 60
        ).padStart(2, '0');
        const timeText = `${hours}:${minutes}:${seconds} WIB`;
        const titles = {
            tide_level_cm: 'Tinggi pasang',
            sensor_distance_cm: 'Jarak radar ke air',
            change_1h_cm: 'Perubahan 1 jam'
        };
        const title = titles[key] || 'Pengukuran';
        const valueText = `${fmt(nearest.v)} cm`;
        ctx.save();
        ctx.beginPath();
        ctx.setLineDash([4, 4]);
        ctx.strokeStyle = '#84969e';
        ctx.lineWidth = 1;
        ctx.moveTo(px, top);
        ctx.lineTo(px, height - bottom);
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.beginPath();
        ctx.arc(px, py, 6, 0, Math.PI * 2);
        ctx.fillStyle = '#ffffff';
        ctx.fill();
        ctx.lineWidth = 2.5;
        ctx.strokeStyle = canvas.dataset.color || '#2f80c9';
        ctx.stroke();
        ctx.font = '12px system-ui, sans-serif';
        const textWidth = Math.max(
            ctx.measureText(title).width,
            ctx.measureText(timeText).width,
            ctx.measureText(valueText).width
        );
        const boxWidth = Math.min(width - 16, Math.max(145, textWidth + 24));
        const boxHeight = 82;
        const boxX = Math.max(8, Math.min(px + 14, width - boxWidth - 8));
        let boxY = py - boxHeight - 12;
        if (boxY < 8) {
            boxY = py + 12;
        }
        boxY = Math.max(8, Math.min(boxY, height - boxHeight - 8));
        ctx.fillStyle = 'rgba(20, 43, 56, 0.96)';
        ctx.beginPath();
        ctx.roundRect(boxX, boxY, boxWidth, boxHeight, 8);
        ctx.fill();
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 12px system-ui, sans-serif';
        ctx.fillText(title, boxX + 12, boxY + 18);
        ctx.font = '12px system-ui, sans-serif';
        ctx.fillText(timeText, boxX + 12, boxY + 42);
        ctx.font = 'bold 13px system-ui, sans-serif';
        ctx.fillText(valueText, boxX + 12, boxY + 64);
        ctx.restore();
    }
    function animateCharts() {
        pulsePhase += 0.008;
        page.querySelectorAll('canvas[data-chart]').forEach(draw);
        animationFrameId = requestAnimationFrame(animateCharts);
    }
    function renderTable() {
        if (!tbody) return;
        const totalPages = Math.max(
            1,
            Math.ceil(rows.length / PAGE_SIZE)
        );
        activePage = Math.min(
            Math.max(1, activePage),
            totalPages
        );
        if (pageInfo) {
            pageInfo.textContent =
                `Halaman ${activePage} dari ${totalPages}`;
        }
        if (count) {
            count.textContent = String(rows.length);
        }
        if (prevButton) {
            prevButton.disabled = activePage === 1;
        }
        if (nextButton) {
            nextButton.disabled = activePage === totalPages;
        }
        if (pageNumbers) {
            pageNumbers.replaceChildren();
            const from = Math.max(
                1,
                Math.min(activePage - 2, totalPages - 4)
            );
            const to = Math.min(totalPages, from + 4);
            for (let n = from; n <= to; n++) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'button secondary';
                button.textContent = String(n);
                button.disabled = n === activePage;
                button.setAttribute('aria-label', `Halaman ${n}`);
                button.addEventListener('click', () => {
                    activePage = n;
                    renderTable();
                });
                pageNumbers.append(button);
            }
        }
        tbody.replaceChildren();
        const visible = [...rows].reverse().slice(
            (activePage - 1) * PAGE_SIZE,
            activePage * PAGE_SIZE
        );
        if (!visible.length) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 6;
            td.textContent = 'Belum ada data hari ini';
            tr.append(td);
            tbody.append(tr);
            return;
        }
        const trends = {
            NAIK: '▲ Naik',
            TURUN: '▼ Surut',
            STABIL: '■ Stabil'
        };
        for (const row of visible) {
            const tr = document.createElement('tr');
            const values = [
                row.id ?? '—',
                row.waktu,
                fmt(row.sensor_distance_cm),
                fmt(row.tide_level_cm),
                fmt(row.change_1h_cm),
                trends[row.trend] || '—'
            ];
            for (const value of values) {
                const td = document.createElement('td');
                td.textContent = String(value);
                tr.append(td);
            }
            tbody.append(tr);
        }
    }
    function renderCurrent() {
        page.querySelectorAll('canvas[data-chart]').forEach(draw);
        renderTable();
        const last = rows[rows.length - 1];
        for (const field of [
            'tide_level_cm',
            'sensor_distance_cm',
            'change_1h_cm'
        ]) {
            setNow(field, last ? fmt(last[field]) : '—');
        }
        const trends = {
            NAIK: '▲ Naik',
            TURUN: '▼ Surut',
            STABIL: '■ Stabil'
        };
        setNow(
            'trend',
            last ? (trends[last.trend] || '—') : '—'
        );
        setNow(
            'observed_at',
            last?.waktu ? last.waktu + ' WIB' : '—'
        );
        if (!last) {
            if (badge) {
                badge.textContent = 'BELUM ADA DATA';
                badge.className = 'weather-level';
            }
            setSource('Belum ada data hari ini');
            return;
        }
        const timestamp = Date.parse(last.observed_at || '');
        const ageMs = Date.now() - timestamp;
        const fresh =
            Number.isFinite(ageMs) &&
            ageMs >= -60000 &&
            ageMs <= 15 * 60000;
        const status = waterStatus(last.tide_level_cm);
        const statusText = {
            normal: 'NORMAL',
            watch: 'WASPADA',
            alert: 'SIAGA',
            warning: 'BAHAYA',
            UNCONFIGURED: 'AMBANG BELUM DIATUR',
            INVALID: 'DATA SENSOR TIDAK VALID'
        };
        if (badge) {
            badge.textContent = !fresh
                ? 'DATA TERLAMBAT'
                : (statusText[status] || '—');
            const validStatus = [
                'normal', 'watch', 'alert', 'warning'
            ].includes(status);
            badge.className = 'weather-level' +
                (fresh && validStatus ? ` river-${status}` : '');
        }
        if (!fresh) {
            setSource('Data pengukuran terlambat', 'dummy');
        } else if (status === 'INVALID') {
            setSource('Data sensor tidak valid', 'error');
        } else {
            setSource('Data API tersedia', 'live');
        }
    }
    async function refresh() {
        if (loading || !feedUrl) return;
        loading = true;
        try {
            const response = await fetch(feedUrl, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json'
                }
            });
            const raw = await response.text();
            if (!response.ok) {
                throw new Error(
                    `HTTP ${response.status}: ${raw.slice(0, 120)}`
                );
            }
            let feed;
            try {
                feed = JSON.parse(raw);
            } catch {
                throw new Error(
                    `Respons bukan JSON: ${raw.slice(0, 160)}`
                );
            }
            if (!feed || typeof feed !== 'object') {
                throw new Error('Format feed tidak valid');
            }
            const data = Array.isArray(feed.history)
                ? feed.history
                : feed.data;
            if (!Array.isArray(data)) {
                throw new Error('history/data bukan array');
            }
            const today = feed.date;
            if (today !== activeDate) {
                activeDate = today;
                activePage = 1;
            }
            rows = data
                .filter(r => r && r.tanggal === today)
                .map(normalize)
                .filter(r => timeSeconds(r.waktu) !== null)
                .sort((a, b) =>
                    timeSeconds(a.waktu) - timeSeconds(b.waktu) ||
                    Number(a.id || 0) - Number(b.id || 0)
                );
            thresholds = readThresholds(feed);
            if (dateLabel) {
                dateLabel.textContent = today || '—';
            }
            renderCurrent();
        } catch (error) {
            console.error('[TIDE FEED]', error);
            setSource('Gagal mengambil data API', 'error');
            if (badge) {
                badge.textContent = 'KONEKSI BERMASALAH';
                badge.className = 'weather-level';
            }
        } finally {
            loading = false;
        }
    }
    if (prevButton) {
        prevButton.addEventListener('click', () => {
            activePage--;
            renderTable();
        });
    }
    if (nextButton) {
        nextButton.addEventListener('click', () => {
            activePage++;
            renderTable();
        });
    }
    if (clock) {
        const updateClock = () => {
            clock.textContent = new Intl.DateTimeFormat('id-ID', {
                timeZone: 'Asia/Jakarta',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hourCycle: 'h23'
            }).format(new Date());
        };
        updateClock();
        setInterval(updateClock, 1000);
    }
    window.addEventListener('resize', () => {
        page.querySelectorAll('canvas[data-chart]').forEach(draw);
    });

    page.querySelectorAll('canvas[data-chart]').forEach(canvas => {
        canvas.style.cursor = 'crosshair';
        function updateHover(event) {
            const rect = canvas.getBoundingClientRect();
            hoverPositions.set(canvas, {
                x: (event.clientX - rect.left) *
                    canvas.clientWidth / rect.width,
                y: (event.clientY - rect.top) *
                    canvas.clientHeight / rect.height
            });
            draw(canvas);
        }
        canvas.addEventListener('pointermove', event => {
            if (event.pointerType !== 'touch') {
                updateHover(event);
            }
        });
        canvas.addEventListener('pointerdown', updateHover);
        canvas.addEventListener('pointerleave', event => {
            if (event.pointerType === 'touch') return;
            hoverPositions.delete(canvas);
            draw(canvas);
        });
    });

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            if (animationFrameId) {
                cancelAnimationFrame(animationFrameId);
                animationFrameId = null;
            }
        } else {
            refresh();
            if (!animationFrameId) {
                animateCharts();
            }
        }
    });
    refresh();
    if (animationFrameId) { cancelAnimationFrame(animationFrameId); }
    animateCharts();
    setInterval(refresh, 300000);
})();

