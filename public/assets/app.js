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
            const mark = document.createElement('i');
            mark.className = 'health-dot health-' + sensor.health;
            const text = document.createElement('span');
            text.textContent = sensor.name + ' · ' + sensor.parameter + ' — ' + sensor.label + ' (' + sensor.last + ')';
            row.append(mark, text);
            popup.appendChild(row);
        });
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
    const levels = JSON.parse(page.dataset.levels || '{}');
    const fmt = (value) => Number(value).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    const clock = (iso) => new Date(iso).toLocaleTimeString('id-ID', { hour12: false, timeZone: 'Asia/Jakarta' }).replace(/\./g, ':');
    const levelOf = (cm, thresholds) => ['watch', 'alert', 'warning'].reduce((found, key) => {
        const t = thresholds[key];
        return t && (t.op === '>' ? cm > t.value : cm >= t.value) ? key : found;
    }, 'normal');
    const trends = { rising: ['▲ Naik', 'rising'], falling: ['▼ Turun', 'falling'], steady: ['■ Stabil', 'steady'] };
    const source = page.querySelector('[data-river-source]');
    const set = (card, field, text) => { const node = card.querySelector('[data-field="' + field + '"]'); if (node) node.textContent = text; };
    const stat = (name, value) => { const node = page.querySelector('[data-river-' + name + ']'); if (node) node.textContent = value; };

    const refresh = async () => {
        try {
            const response = await fetch(page.dataset.feedUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('feed');
            const data = await response.json();
            let alerting = 0; let rising = 0; let max = 0;
            data.stations.forEach((row) => {
                const card = page.querySelector('[data-river-station="' + CSS.escape(row.code) + '"]');
                if (!card) return;
                const thresholds = (data.thresholds && data.thresholds[row.code]) || {};
                const cm = Number(row.water_level_cm);
                const change = Number(row.change_1h_cm);
                const level = levelOf(cm, thresholds);
                const trend = change >= 2 ? 'rising' : (change <= -2 ? 'falling' : 'steady');
                max = Math.max(max, cm);
                if (level !== 'normal') alerting += 1;
                if (trend === 'rising') rising += 1;
                set(card, 'level_cm', fmt(cm));
                set(card, 'change', (change > 0 ? '+' : '') + fmt(change));
                set(card, 'rain', fmt(row.rain_upstream_mm_h));
                set(card, 'flow', fmt(row.flow_m3s));
                set(card, 'time', clock(row.observed_at));
                set(card, 'trend', trends[trend][0]);
                card.querySelector('[data-field="trend"]').className = 'river-trend trend-' + trend;
                const badge = card.querySelector('[data-field="level"]');
                badge.textContent = levels[level];
                badge.className = 'weather-level river-' + level;
                const top = Math.max(300, ...Object.values(thresholds).map((t) => t.value * 1.25));
                const pct = Math.min(100, Math.round(cm / top * 100 / 5) * 5);
                card.querySelector('[data-field="gauge"]').className = 'river-fill river-' + level + ' bar-w-' + pct;
            });
            stat('alerting', String(alerting));
            stat('rising', String(rising));
            stat('max', fmt(max));
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

(() => {
    const page = document.querySelector('[data-river-monitor]');
    if (!page) return;
    const levels = JSON.parse(page.dataset.levels || '{}');
    const fmt = (value, digits = 1) => Number(value).toLocaleString('id-ID', { minimumFractionDigits: digits, maximumFractionDigits: digits });
    const clock = (iso) => new Date(iso).toLocaleTimeString('id-ID', { hour12: false, timeZone: 'Asia/Jakarta' }).replace(/\./g, ':');
    const levelColors = { watch: '#d9822b', alert: '#c8541a', warning: '#b53a31' };
    const trends = { rising: '▲ Naik', falling: '▼ Turun', steady: '■ Stabil' };
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
        now('water_level_cm', fmt(last.water_level_cm));
        now('change_1h_cm', (last.change_1h_cm > 0 ? '+' : '') + fmt(last.change_1h_cm));
        now('rain_upstream_mm_h', fmt(last.rain_upstream_mm_h));
        now('flow_m3s', fmt(last.flow_m3s));
        now('observed_at', clock(last.observed_at));
        const change = Number(last.change_1h_cm);
        const trend = change >= 2 ? 'rising' : (change <= -2 ? 'falling' : 'steady');
        now('trend', trends[trend]);
        page.querySelector('[data-now="trend"]').className = 'river-trend trend-' + trend;
        const level = levelOf(Number(last.water_level_cm));
        const badge = page.querySelector('[data-monitor-level]');
        badge.textContent = levels[level];
        badge.className = 'weather-level river-' + level;
        const body = page.querySelector('[data-monitor-rows]');
        body.replaceChildren(...series.slice(-12).reverse().map((row) => {
            const tr = document.createElement('tr');
            [clock(row.observed_at), fmt(row.water_level_cm), (row.change_1h_cm > 0 ? '+' : '') + fmt(row.change_1h_cm), fmt(row.rain_upstream_mm_h), fmt(row.flow_m3s)].forEach((text) => {
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

(() => {
    const page = document.querySelector('[data-tide-monitor]');
    if (!page) return;
    const levels = JSON.parse(page.dataset.levels || '{}');
    const fmt = (value, digits = 1) => Number(value).toLocaleString('id-ID', { minimumFractionDigits: digits, maximumFractionDigits: digits });
    const clock = (iso) => new Date(iso).toLocaleTimeString('id-ID', { hour12: false, timeZone: 'Asia/Jakarta' }).replace(/\./g, ':');
    const levelColors = { watch: '#d9822b', alert: '#c8541a', warning: '#b53a31' };
    const trends = { rising: '▲ Naik', falling: '▼ Surut', steady: '■ Stabil' };
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
            ctx.fillText(levels[sev] + ' ' + fmt(t.value, 2), width - pad.r - 2, y(t.value) - 3);
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
        now('tide_level_m', fmt(last.tide_level_m, 2));
        now('change_1h_m', (last.change_1h_m > 0 ? '+' : '') + fmt(last.change_1h_m, 2));
        now('wave_height_m', fmt(last.wave_height_m));
        now('wind_kmh', fmt(last.wind_kmh));
        now('observed_at', clock(last.observed_at));
        const change = Number(last.change_1h_m);
        const trend = change >= 0.05 ? 'rising' : (change <= -0.05 ? 'falling' : 'steady');
        now('trend', trends[trend]);
        page.querySelector('[data-now="trend"]').className = 'river-trend trend-' + trend;
        const level = levelOf(Number(last.tide_level_m));
        const badge = page.querySelector('[data-monitor-level]');
        badge.textContent = levels[level];
        badge.className = 'weather-level river-' + level;
        const body = page.querySelector('[data-monitor-rows]');
        body.replaceChildren(...series.slice(-12).reverse().map((row) => {
            const tr = document.createElement('tr');
            [clock(row.observed_at), fmt(row.tide_level_m, 2), (row.change_1h_m > 0 ? '+' : '') + fmt(row.change_1h_m, 2), fmt(row.wave_height_m), fmt(row.wind_kmh)].forEach((text) => {
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
