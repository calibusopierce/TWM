<?php
/**
 * customer_qr.php
 * TWM/CUSTOMERS — Customer QR Map
 * RBAC module key: customer_qr
 *
 * Map view of customers that have an existing QR code. Each pin renders the
 * customer's own QR image (square, not the teardrop pins used for GPS/vehicle
 * markers — a rotated QR wouldn't scan/read visually). Clicking a pin opens a
 * modal with the customer's full details. Visual language (page header, stat
 * cards, toolbar, map-wrap + basemap toggle, modal, lightbox) mirrors
 * gps_location_map.php / vehicle_status.php, with a cq- class prefix since
 * this is a separate file.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';

auth_check();
rbac_gate($pdo, 'customer_qr');

$topbar_page = 'customer_qr';

function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= base_url('assets/img/logo.png') ?>">
    <link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
    <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <title>Customer QR Map</title>

<style>
*, *::before, *::after { box-sizing: border-box; }

body {
    font-family: 'IBM Plex Sans', sans-serif;
    background: #f4f5f7;
    color: #1a1d23;
    font-size: 14px;
    line-height: 1.5;
}

.cq-page { max-width: 1400px; margin: 0 auto; padding: 24px 20px 48px; }

/* ── Page Header ──────────────────────────────── */
.cq-header {
    display: flex; align-items: flex-end; justify-content: space-between;
    flex-wrap: wrap; gap: 12px; margin-bottom: 28px; padding-bottom: 20px;
    border-bottom: 2px solid #e2e5ea;
}
.cq-dept-label {
    font-size: 12px; font-weight: 600; text-transform: uppercase;
    letter-spacing: 0.08em; color: #6b7280; margin-bottom: 4px;
}
.cq-page-title { font-size: 26px; font-weight: 600; color: #111827; line-height: 1.2; }
.cq-page-title span { color: #0f766e; }
.cq-subtitle { color: #6b7280; font-size: 13px; margin: 4px 0 0; }

/* ── Stat Cards ───────────────────────────────── */
.cq-stats { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 24px; }
.cq-stat-card {
    background: #fff; border: 1.5px solid #e2e5ea; border-radius: 14px;
    padding: 16px 22px; min-width: 150px; box-shadow: 0 1px 4px rgba(0,0,0,.04);
    display: flex; flex-direction: column;
}
.cq-stat-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.06em; color: #6b7280; margin-bottom: 4px;
}
.cq-stat-value { font-size: 22px; font-weight: 700; color: #111827; }
.cq-stat-value.cq-accent { color: #0f766e; }

/* ── Toolbar ─────────────────────── */
.cq-toolbar {
    background: #fff; border: 1.5px solid #e2e5ea; border-radius: 14px;
    padding: 18px 24px; margin-bottom: 20px; display: flex; align-items: center;
    gap: 18px; flex-wrap: wrap; box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.cq-search {
    flex: 1; min-width: 260px; max-width: 420px; height: 42px; padding: 0 14px;
    border: 1.5px solid #d1d5db; border-radius: 9px; font-family: 'IBM Plex Mono', monospace;
    font-size: 13px; color: #111827; background: #f9fafb; outline: none;
    transition: border-color .15s, box-shadow .15s;
}
.cq-search:focus { border-color: #0f766e; background: #fff; box-shadow: 0 0 0 3px rgba(15,118,110,.12); }

.cq-map-wrap { position: relative; z-index: 0; }
.cq-view-toggle { display: flex; gap: 4px; background: #f3f4f6; padding: 4px; border-radius: 9px; }
.cq-view-toggle button {
    border: none; background: transparent; padding: 7px 16px; border-radius: 7px;
    font-family: 'IBM Plex Sans', sans-serif; font-size: 12px; font-weight: 600;
    color: #6b7280; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
    transition: background .15s, color .15s;
}
.cq-view-toggle button.active { background: #fff; color: #0f766e; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.cq-view-toggle button:hover:not(.active) { color: #374151; }
.cq-map-controls { position: absolute; top: 12px; right: 12px; z-index: 1000; }
#cq-map {
    width: 100%; height: 640px; border-radius: 14px; border: 1.5px solid #e2e5ea;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
}

/* ── QR pin (square — a rotated teardrop would distort the QR) ── */
.cq-pin-wrap { text-align: center; }
.cq-pin {
    position: relative; background: #fff; border: 2.5px solid #ff0000; border-radius: 8px;
    padding: 3px; box-shadow: 0 3px 8px rgba(0,0,0,.35); cursor: pointer;
    transition: transform .12s ease, box-shadow .12s ease;
}
.cq-pin:hover { transform: scale(1.12); box-shadow: 0 5px 14px rgba(15,118,110,.55); }
.cq-pin img { display: block; width: 32px; height: 32px; image-rendering: pixelated; border-radius: 3px; }
.cq-pin-badge {
    position: absolute; bottom: -6px; right: -6px; width: 18px; height: 18px; border-radius: 50%;
    background: #0f766e; border: 2px solid #fff; display: flex; align-items: center; justify-content: center;
    box-shadow: 0 1px 3px rgba(0,0,0,.4);
}
.cq-pin-badge i { color: #fff; font-size: 9px; }
.cq-pin-tail {
    width: 0; height: 0; margin: 0 auto;
    border-left: 6px solid transparent; border-right: 6px solid transparent; border-top: 8px solid #0f766e;
}

/* ── Cluster bubbles, same visual language as gps_location_map's gm-cluster ── */
.cq-cluster {
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%; color: #fff; font-weight: 700; font-size: 13px;
    background: rgba(220, 38, 38, .9); border: 3px solid #fff;
    box-shadow: 0 2px 6px rgba(0,0,0,.4);
}

.cq-popup { font-family: 'IBM Plex Sans', sans-serif; font-size: 12px; min-width: 180px; }
.cq-popup-title { font-weight: 700; margin-bottom: 4px; color: #111827; }

/* ── Details modal (same skeleton as gps_location_map.php's pin modal) ── */
.cq-modal-backdrop {
    display: none; position: fixed; inset: 0; background: rgba(17,24,39,.5);
    z-index: 3000; align-items: center; justify-content: center; padding: 20px;
}
.cq-modal-backdrop.active { display: flex; }
.cq-modal {
    background: #fff; border-radius: 16px; max-width: 480px; width: 100%;
    max-height: 85vh; overflow-y: auto; position: relative;
    box-shadow: 0 20px 50px rgba(0,0,0,.25); padding: 28px 26px 22px;
}
.cq-modal-close {
    position: absolute; top: 14px; right: 14px; border: none; background: #f3f4f6;
    width: 30px; height: 30px; border-radius: 50%; font-size: 18px; line-height: 1;
    color: #6b7280; cursor: pointer;
}
.cq-modal-close:hover { background: #e5e7eb; color: #111827; }
.cq-modal-head { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; }
.cq-modal-avatar {
    width: 56px; height: 56px; border-radius: 10px; object-fit: cover;
    border: 2px solid #e2e5ea; flex-shrink: 0; cursor: zoom-in;
}
.cq-modal-title { font-size: 18px; font-weight: 700; color: #111827; }
.cq-modal-subtitle { font-size: 12px; color: #6b7280; margin-top: 2px; }
.cq-modal-qr-highlight {
    background: #f0fdfa; border: 1.5px solid #99f6e4; border-radius: 10px;
    padding: 4px 12px; margin-bottom: 10px;
}
.cq-modal-qr-highlight .cq-modal-row { border-bottom: none; }
.cq-modal-qr-highlight .cq-modal-row span:first-child { color: #0f766e; font-weight: 700; }
.cq-modal-qr-highlight .cq-modal-row span:last-child { color: #0f766e; font-weight: 700; font-size: 14px; }
.cq-modal-grid { display: flex; flex-direction: column; gap: 2px; }
.cq-modal-row {
    display: flex; justify-content: space-between; gap: 14px; padding: 7px 0;
    border-bottom: 1px solid #f1f3f7; font-size: 13px;
}
.cq-modal-row:last-child { border-bottom: none; }
.cq-modal-row span:first-child { color: #6b7280; }
.cq-modal-row span:last-child { color: #111827; font-family: 'IBM Plex Mono', monospace; text-align: right; }

/* ── Image lightbox — full-size QR preview on avatar click ── */
.cq-lightbox-backdrop {
    display: none; position: fixed; inset: 0; background: rgba(0,0,0,.85);
    z-index: 4000; align-items: center; justify-content: center; padding: 30px;
    cursor: zoom-out;
}
.cq-lightbox-backdrop.active { display: flex; }
.cq-lightbox-backdrop img {
    max-width: 90vw; max-height: 90vh; border-radius: 10px; background: #fff; padding: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,.5);
}
.cq-lightbox-close {
    position: absolute; top: 20px; right: 24px; border: none; background: rgba(255,255,255,.15);
    width: 38px; height: 38px; border-radius: 50%; font-size: 22px; line-height: 1;
    color: #fff; cursor: pointer;
}
.cq-lightbox-close:hover { background: rgba(255,255,255,.3); }
</style>
</head>
<body>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="content">
<div class="cq-page">

    <div class="cq-header">
        <div>
            <div class="cq-dept-label">Sales &nbsp;· Customer Directory</div>
            <h1 class="cq-page-title"><i class="bi bi-qr-code"></i> Customer <span>QR Map</span></h1>
            <p class="cq-subtitle">Live positions from View_Customer_With_QRCODE</p>
        </div>
    </div>

    <div class="cq-stats">
        <div class="cq-stat-card">
            <div class="cq-stat-label">Customers with QR</div>
            <div class="cq-stat-value cq-accent" id="cq-total">0</div>
        </div>
    </div>

    <div class="cq-toolbar">
        <input type="text" id="cq-search" class="cq-search" placeholder="Search customer, code, area, contact person...">
    </div>

    <div class="cq-map-wrap">
        <div class="cq-map-controls cq-view-toggle">
            <button type="button" id="cq-btn-street" class="active" onclick="cqSwitchBasemap('street')">
                <i class="bi bi-map"></i> Street
            </button>
            <button type="button" id="cq-btn-satellite" onclick="cqSwitchBasemap('satellite')">
                <i class="bi bi-globe-americas"></i> Satellite
            </button>
        </div>
        <div id="cq-map"></div>
    </div>

</div>
</div>

<div id="cq-modal-backdrop" class="cq-modal-backdrop" onclick="if(event.target===this) cqCloseModal()">
    <div class="cq-modal">
        <button type="button" class="cq-modal-close" onclick="cqCloseModal()">&times;</button>
        <div id="cq-modal-body"></div>
    </div>
</div>

<div id="cq-lightbox-backdrop" class="cq-lightbox-backdrop" onclick="cqCloseLightbox()">
    <button type="button" class="cq-lightbox-close" onclick="cqCloseLightbox(); event.stopPropagation();">&times;</button>
    <img id="cq-lightbox-img" src="" alt="">
</div>

<script>
let cqMap = null;
let cqStreetLayer = null;
let cqSatelliteLayer = null;

function cqClusterIconCreate(cluster) {
    const count = cluster.getChildCount();
    const size = count < 10 ? 34 : (count < 50 ? 42 : 50);
    return L.divIcon({
        html: `<div class="cq-cluster" style="width:${size}px;height:${size}px;">${count}</div>`,
        className: '',
        iconSize: [size, size]
    });
}
const cqCluster = () => L.markerClusterGroup({
    maxClusterRadius: 50,
    iconCreateFunction: cqClusterIconCreate,
    spiderfyOnMaxZoom: true,
    showCoverageOnHover: false
});
let cqClusterGroup = null;
let cqAllCustomers = [];
let cqMarkers = []; // { marker, name, code, area, contactPerson }

function cqInitMap() {
    cqMap = L.map('cq-map').setView([13.9350, 121.6140], 13);

    cqStreetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
    });
    cqSatelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19, attribution: 'Tiles &copy; Esri'
    });
    cqStreetLayer.addTo(cqMap);

    cqClusterGroup = cqCluster();
    cqMap.addLayer(cqClusterGroup);

    fetch('customer_qr_ajax.php?action=list')
        .then(r => r.json())
        .then(res => {
            if (!res.success) { console.error(res.message); return; }
            cqAllCustomers = res.data;
            cqRenderMarkers(cqAllCustomers);
            if (cqAllCustomers.length) {
                const bounds = cqMarkers.map(m => m.marker.getLatLng());
                cqMap.fitBounds(bounds, { padding: [30, 30] });
            }
        })
        .catch(err => console.error('Failed to load customer QR data', err));
}

function cqSwitchBasemap(which) {
    const btnStreet = document.getElementById('cq-btn-street');
    const btnSat = document.getElementById('cq-btn-satellite');
    if (which === 'satellite') {
        if (cqMap.hasLayer(cqStreetLayer)) cqMap.removeLayer(cqStreetLayer);
        cqSatelliteLayer.addTo(cqMap);
        btnStreet.classList.remove('active');
        btnSat.classList.add('active');
    } else {
        if (cqMap.hasLayer(cqSatelliteLayer)) cqMap.removeLayer(cqSatelliteLayer);
        cqStreetLayer.addTo(cqMap);
        btnSat.classList.remove('active');
        btnStreet.classList.add('active');
    }
}

function cqBuildIcon(c) {
    return L.divIcon({
        className: '',
        html: `<div class="cq-pin-wrap">
                 <div class="cq-pin">
                   <img src="${c.QRPictureDataUri}" alt="QR">
                   <div class="cq-pin-badge"><i class="bi bi-hand-index-thumb-fill"></i></div>
                 </div>
                 <div class="cq-pin-tail"></div>
               </div>`,
        iconSize: [40, 48],
        iconAnchor: [20, 48],
        popupAnchor: [0, -44]
    });
}

function cqRenderMarkers(list) {
    cqClusterGroup.clearLayers();
    cqMarkers = [];
    list.forEach(c => {
        const lat = parseFloat(c.Latitude), lng = parseFloat(c.Longitude);
        if (isNaN(lat) || isNaN(lng)) return;
        const marker = L.marker([lat, lng], { icon: cqBuildIcon(c) });
        marker.on('click', () => cqOpenModal(c));
        marker.bindTooltip(c.CustomerName || c.Code || '', { direction: 'top', offset: [0, -36] });
        cqClusterGroup.addLayer(marker);
        cqMarkers.push({
            marker,
            name: (c.CustomerName || '').toLowerCase(),
            code: (c.Code || '').toLowerCase(),
            area: (c.Area || '').toLowerCase(),
            contactPerson: (c.ContactPerson || '').toLowerCase()
        });
    });
    document.getElementById('cq-total').textContent = list.length;
}

function cqModalRow(label, value) {
    if (value === null || value === undefined || value === '') value = '—';
    return `<div class="cq-modal-row"><span>${label}</span><span>${value}</span></div>`;
}

function cqOpenModal(c) {
    const body = document.getElementById('cq-modal-body');
    let html = `<div class="cq-modal-head">
        <img src="${c.QRPictureDataUri}" class="cq-modal-avatar" alt="QR"
             onclick="cqOpenLightbox(this.src); event.stopPropagation();">
        <div>
            <div class="cq-modal-title">${c.CustomerName || '(no name)'}</div>
            <div class="cq-modal-subtitle">${c.CustomerType || ''}</div>
        </div>
    </div>`;
    html += `<div class="cq-modal-qr-highlight">${cqModalRow('QR Code', c.QRCODE)}</div>`;
    html += '<div class="cq-modal-grid">';
    html += cqModalRow('Address', c.Address);
    html += cqModalRow('Area', c.Area);
    html += cqModalRow('Contact No.', c.ContactNo);
    html += cqModalRow('Contact Person', c.ContactPerson);
    html += cqModalRow('TIN', c.TIN);
    html += cqModalRow('Coordinates', `${c.Latitude}, ${c.Longitude}`);
    html += cqModalRow('Code', c.Code);
    html += '</div>';
    body.innerHTML = html;
    document.getElementById('cq-modal-backdrop').classList.add('active');
}

function cqCloseModal() {
    document.getElementById('cq-modal-backdrop').classList.remove('active');
}

function cqOpenLightbox(src) {
    if (!src) return;
    document.getElementById('cq-lightbox-img').src = src;
    document.getElementById('cq-lightbox-backdrop').classList.add('active');
}

function cqCloseLightbox() {
    document.getElementById('cq-lightbox-backdrop').classList.remove('active');
    document.getElementById('cq-lightbox-img').src = '';
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { cqCloseLightbox(); cqCloseModal(); }
});

document.addEventListener('DOMContentLoaded', () => {
    cqInitMap();
    document.getElementById('cq-search').addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        if (!q) { cqRenderMarkers(cqAllCustomers); return; }
        const filtered = cqAllCustomers.filter(c =>
            (c.CustomerName || '').toLowerCase().includes(q) ||
            (c.Code || '').toLowerCase().includes(q) ||
            (c.Area || '').toLowerCase().includes(q) ||
            (c.ContactPerson || '').toLowerCase().includes(q)
        );
        cqRenderMarkers(filtered);
    });
});
</script>
</body>
</html>