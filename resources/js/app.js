import './bootstrap';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.heat';

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

    // A pulsing halo drawn *underneath* a red-risk region's marker — the same color signal a
    // legend would otherwise be needed to explain, made to draw the eye on its own.
    const radarPingIcon = L.divIcon({
        className: '',
        html: '<span class="radar-ping-ring"></span>',
        iconSize: [26, 26],
        iconAnchor: [13, 13],
    });

    const heatPoints = [];

    regions.forEach((region) => {
        if (region.latitude === null || region.longitude === null) return;

        if (region.riskBand === 'red') {
            L.marker([region.latitude, region.longitude], {
                icon: radarPingIcon,
                interactive: false,
                keyboard: false,
            }).addTo(map);
        }

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

        // leaflet.heat weights 0–1; a null score contributes nothing to the heatmap rather than
        // being coerced to 0 (which would misleadingly read as "confirmed low risk").
        if (region.score !== null) {
            heatPoints.push([region.latitude, region.longitude, Number(region.score) / 100]);
        }
    });

    const heatLayer = L.heatLayer(heatPoints, { radius: 35, blur: 25, maxZoom: 10 });

    const HeatmapToggle = L.Control.extend({
        options: { position: 'topright' },
        onAdd() {
            const container = L.DomUtil.create('div', 'leaflet-bar');
            const button = L.DomUtil.create('a', '', container);
            button.href = '#';
            button.title = 'Toggle risk heatmap';
            button.style.width = 'auto';
            button.style.padding = '0 10px';
            button.style.fontSize = '12px';
            button.style.fontWeight = '600';
            button.textContent = 'Show heatmap';

            let visible = false;
            L.DomEvent.on(button, 'click', (e) => {
                L.DomEvent.preventDefault(e);
                visible = !visible;
                if (visible) {
                    heatLayer.addTo(map);
                    button.textContent = 'Hide heatmap';
                } else {
                    map.removeLayer(heatLayer);
                    button.textContent = 'Show heatmap';
                }
            });

            return container;
        },
    });

    map.addControl(new HeatmapToggle());
};
