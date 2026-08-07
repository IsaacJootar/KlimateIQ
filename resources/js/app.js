import './bootstrap';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const riskColor = {
    green: '#059669',
    amber: '#D97706',
    red: '#DC2626',
    none: '#94A3B8',
};

/**
 * @param {string} containerId
 * @param {{ name: string, latitude: number, longitude: number, score: number|null, riskBand: string, url: string }[]} regions
 */
window.initRegionMap = function initRegionMap(containerId, regions) {
    const el = document.getElementById(containerId);
    if (!el || el.dataset.mapInitialized) return;
    el.dataset.mapInitialized = 'true';

    const map = L.map(containerId).setView([9.0, 8.0], 6);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 18,
    }).addTo(map);

    regions.forEach((region) => {
        if (region.latitude === null || region.longitude === null) return;

        const marker = L.circleMarker([region.latitude, region.longitude], {
            radius: 12,
            fillColor: riskColor[region.riskBand] ?? riskColor.none,
            color: '#ffffff',
            weight: 2,
            fillOpacity: 0.9,
        }).addTo(map);

        const scoreLabel = region.score !== null ? Number(region.score).toFixed(1) : 'no data';
        marker.bindPopup(
            `<strong>${region.name}</strong><br>Score: ${scoreLabel}<br><a href="${region.url}">View details &rarr;</a>`
        );
    });
};
