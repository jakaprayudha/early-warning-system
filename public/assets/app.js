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
            popup.style.transform = 'translate(' + position.x + 'px,' + (position.y - 30) + 'px)';
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
