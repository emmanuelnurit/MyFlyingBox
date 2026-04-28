/**
 * MyFlyingBox - Back Office pickup-point map widget.
 *
 * Vanilla-JS controller (Stimulus-style API) since Thelia 2.6 back office
 * does not ship Stimulus. The class auto-discovers root elements with
 * `data-controller="mfb-pickup-map"` and exposes the following data attrs:
 *
 *   data-mfb-pickup-map-endpoint-value   (required) AJAX endpoint URL
 *   data-mfb-pickup-map-option-value     (optional) initial option code
 *   data-mfb-pickup-map-token-value      (optional) CSRF token (sent as header)
 *   data-mfb-pickup-map-postal-code-value
 *   data-mfb-pickup-map-city-value
 *   data-mfb-pickup-map-street-value
 *   data-mfb-pickup-map-country-value
 *   data-mfb-pickup-map-selected-code-value
 *
 * Targets (children with `data-mfb-pickup-map-target="..."`):
 *   map         div hosting Leaflet
 *   list        ul/div for the clickable list
 *   loader      element shown while fetching
 *   error       element shown on error (hidden otherwise)
 *   empty       element shown when no points returned (hidden otherwise)
 *   postalCode  input for postcode search
 *   country     input/select for country (optional)
 *   search      button triggering search
 *   selectedCode hidden input receiving the chosen code
 *   selectedSummary element rendered with the chosen point summary
 *
 * Public API:
 *   widget.setOption(optionCode)   change carrier option and reload
 *   widget.load()                  trigger fetch with current state
 *   widget.getSelected()           returns the currently-selected point or null
 *
 * Events dispatched on the root element:
 *   mfb:pickup-point-selected      detail = relay payload
 *   mfb:pickup-points-loaded       detail = { count }
 *   mfb:pickup-points-error        detail = { message }
 */
(function (global) {
    'use strict';

    var LEAFLET_CSS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    var LEAFLET_JS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    var TILE_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
    var TILE_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';

    var leafletPromise = null;

    function loadLeaflet() {
        if (global.L) {
            return Promise.resolve(global.L);
        }
        if (leafletPromise) {
            return leafletPromise;
        }
        leafletPromise = new Promise(function (resolve, reject) {
            if (!document.querySelector('link[data-mfb-leaflet]')) {
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = LEAFLET_CSS;
                link.setAttribute('data-mfb-leaflet', '1');
                document.head.appendChild(link);
            }
            var existing = document.querySelector('script[data-mfb-leaflet]');
            if (existing) {
                existing.addEventListener('load', function () { resolve(global.L); });
                existing.addEventListener('error', reject);
                return;
            }
            var script = document.createElement('script');
            script.src = LEAFLET_JS;
            script.async = true;
            script.setAttribute('data-mfb-leaflet', '1');
            script.onload = function () { resolve(global.L); };
            script.onerror = reject;
            document.head.appendChild(script);
        });
        return leafletPromise;
    }

    function readData(root, key, fallback) {
        var v = root.getAttribute('data-mfb-pickup-map-' + key + '-value');
        return (v === null || v === '') ? (fallback === undefined ? null : fallback) : v;
    }

    function findTargets(root) {
        var targets = {};
        var nodes = root.querySelectorAll('[data-mfb-pickup-map-target]');
        for (var i = 0; i < nodes.length; i++) {
            var name = nodes[i].getAttribute('data-mfb-pickup-map-target');
            if (!targets[name]) {
                targets[name] = nodes[i];
            }
        }
        return targets;
    }

    function show(el) { if (el) { el.style.display = ''; } }
    function hide(el) { if (el) { el.style.display = 'none'; } }
    function setText(el, text) { if (el) { el.textContent = text == null ? '' : text; } }

    function escapeHtml(value) {
        if (value == null) { return ''; }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatOpeningHours(hours) {
        if (!hours || typeof hours !== 'object') { return ''; }
        try {
            var parts = [];
            Object.keys(hours).forEach(function (day) {
                parts.push(escapeHtml(day) + ': ' + escapeHtml(hours[day]));
            });
            return parts.join('<br>');
        } catch (e) {
            return '';
        }
    }

    function MfbPickupMap(root) {
        this.root = root;
        this.targets = findTargets(root);
        this.endpoint = readData(root, 'endpoint');
        this.option = readData(root, 'option');
        this.token = readData(root, 'token');
        this.country = readData(root, 'country', 'FR');
        this.points = [];
        this.markers = [];
        this.map = null;
        this.markerLayer = null;
        this.selected = null;
        this.activeRequest = null;
        this.initialPostalCode = readData(root, 'postal-code');
        this.initialCity = readData(root, 'city');
        this.initialStreet = readData(root, 'street');
        this.preselectedCode = readData(root, 'selected-code');
        this.bindEvents();
    }

    MfbPickupMap.prototype.bindEvents = function () {
        var self = this;
        if (this.targets.search) {
            this.targets.search.addEventListener('click', function (e) {
                e.preventDefault();
                self.load();
            });
        }
        if (this.targets.postalCode) {
            this.targets.postalCode.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    self.load();
                }
            });
        }
    };

    MfbPickupMap.prototype.setOption = function (optionCode) {
        this.option = optionCode || null;
        this.root.setAttribute('data-mfb-pickup-map-option-value', this.option || '');
        this.load();
    };

    MfbPickupMap.prototype.getSelected = function () {
        return this.selected;
    };

    MfbPickupMap.prototype.buildQuery = function () {
        var pc = this.targets.postalCode && this.targets.postalCode.value
            ? this.targets.postalCode.value
            : (this.initialPostalCode || '');
        var country = this.targets.country && this.targets.country.value
            ? this.targets.country.value
            : (this.country || 'FR');
        var params = [];
        if (this.option) { params.push('option=' + encodeURIComponent(this.option)); }
        if (pc) { params.push('postal_code=' + encodeURIComponent(pc)); }
        if (country) { params.push('country=' + encodeURIComponent(country)); }
        if (this.initialCity) { params.push('city=' + encodeURIComponent(this.initialCity)); }
        if (this.initialStreet) { params.push('street=' + encodeURIComponent(this.initialStreet)); }
        return params.length ? '?' + params.join('&') : '';
    };

    MfbPickupMap.prototype.load = function () {
        var self = this;
        if (!this.endpoint) {
            this.showError('Endpoint missing');
            return Promise.resolve();
        }
        if (!this.option) {
            this.clear();
            return Promise.resolve();
        }
        var url = this.endpoint + this.buildQuery();

        // Cancel previous request
        if (this.activeRequest && this.activeRequest.abort) {
            this.activeRequest.abort();
        }
        var controller = (typeof AbortController === 'function') ? new AbortController() : null;
        this.activeRequest = controller;

        this.showLoader();
        this.hideError();

        var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (this.token) { headers['X-CSRF-Token'] = this.token; }

        return fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: headers,
            signal: controller ? controller.signal : undefined
        }).then(function (resp) {
            if (!resp.ok) { throw new Error('HTTP ' + resp.status); }
            return resp.json();
        }).then(function (payload) {
            self.activeRequest = null;
            var points = self.extractPoints(payload);
            self.renderPoints(points);
            self.dispatch('mfb:pickup-points-loaded', { count: points.length });
        }).catch(function (err) {
            self.activeRequest = null;
            if (err && err.name === 'AbortError') { return; }
            self.showError(err && err.message ? err.message : 'Network error');
            self.dispatch('mfb:pickup-points-error', { message: String(err && err.message || err) });
        }).then(function () {
            self.hideLoader();
        });
    };

    MfbPickupMap.prototype.extractPoints = function (payload) {
        if (!payload) { return []; }
        if (Array.isArray(payload)) { return payload; }
        if (payload.success === false) {
            throw new Error(payload.error || payload.message || 'API error');
        }
        if (Array.isArray(payload.relays)) { return payload.relays; }
        if (payload.data) {
            if (Array.isArray(payload.data)) { return payload.data; }
            if (Array.isArray(payload.data.relays)) { return payload.data.relays; }
            if (Array.isArray(payload.data.points)) { return payload.data.points; }
        }
        if (Array.isArray(payload.points)) { return payload.points; }
        return [];
    };

    MfbPickupMap.prototype.renderPoints = function (points) {
        var self = this;
        this.points = points || [];
        if (this.targets.empty) {
            this.points.length === 0 ? show(this.targets.empty) : hide(this.targets.empty);
        }
        this.renderList();
        loadLeaflet().then(function (L) {
            self.renderMap(L);
            // Re-apply preselected code on first load
            if (self.preselectedCode && !self.selected) {
                var match = self.points.filter(function (p) { return p.code === self.preselectedCode; })[0];
                if (match) { self.selectPoint(match, false); }
            }
        }).catch(function () {
            self.showError('Map library failed to load');
        });
    };

    MfbPickupMap.prototype.renderList = function () {
        var list = this.targets.list;
        if (!list) { return; }
        list.innerHTML = '';
        var self = this;
        this.points.forEach(function (point, idx) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'mfb-pickup-item list-group-item';
            item.setAttribute('data-mfb-code', point.code || '');
            var distance = (point.distance != null) ? '<small class="text-muted pull-right">' + escapeHtml(point.distance) + ' km</small>' : '';
            item.innerHTML =
                '<strong>' + escapeHtml(point.name || point.code || ('#' + (idx + 1))) + '</strong>' + distance +
                '<br><small>' + escapeHtml([point.street, point.postal_code, point.city].filter(Boolean).join(', ')) + '</small>';
            item.addEventListener('click', function () {
                self.selectPoint(point, true);
            });
            list.appendChild(item);
        });
    };

    MfbPickupMap.prototype.renderMap = function (L) {
        var mapEl = this.targets.map;
        if (!mapEl) { return; }
        if (!this.map) {
            this.map = L.map(mapEl, { scrollWheelZoom: false });
            L.tileLayer(TILE_URL, { attribution: TILE_ATTRIBUTION, maxZoom: 19 }).addTo(this.map);
        }
        if (this.markerLayer) {
            this.markerLayer.clearLayers();
        } else {
            this.markerLayer = L.layerGroup().addTo(this.map);
        }
        this.markers = [];
        var bounds = [];
        var self = this;
        this.points.forEach(function (point) {
            if (point.latitude == null || point.longitude == null) { return; }
            var lat = parseFloat(point.latitude);
            var lng = parseFloat(point.longitude);
            if (isNaN(lat) || isNaN(lng)) { return; }
            var marker = L.marker([lat, lng]).addTo(self.markerLayer);
            marker.bindPopup(
                '<strong>' + escapeHtml(point.name || point.code) + '</strong><br>' +
                escapeHtml([point.street, point.postal_code, point.city].filter(Boolean).join(', ')) +
                (point.opening_hours ? '<hr><small>' + formatOpeningHours(point.opening_hours) + '</small>' : '')
            );
            marker.on('click', function () { self.selectPoint(point, true); });
            self.markers.push({ marker: marker, code: point.code });
            bounds.push([lat, lng]);
        });
        if (bounds.length === 1) {
            this.map.setView(bounds[0], 15);
        } else if (bounds.length > 1) {
            this.map.fitBounds(bounds, { padding: [20, 20] });
        }
        // Force size recompute (panel may have been hidden)
        setTimeout(function () { if (self.map) { self.map.invalidateSize(); } }, 100);
    };

    MfbPickupMap.prototype.selectPoint = function (point, focus) {
        if (!point) { return; }
        this.selected = point;
        if (this.targets.selectedCode) {
            this.targets.selectedCode.value = point.code || '';
        }
        if (this.targets.selectedSummary) {
            this.targets.selectedSummary.innerHTML =
                '<strong>' + escapeHtml(point.name || point.code) + '</strong>' +
                '<br><small>' + escapeHtml([point.street, point.postal_code, point.city, point.country].filter(Boolean).join(', ')) + '</small>';
        }
        // Highlight in list
        if (this.targets.list) {
            var items = this.targets.list.querySelectorAll('[data-mfb-code]');
            for (var i = 0; i < items.length; i++) {
                if (items[i].getAttribute('data-mfb-code') === point.code) {
                    items[i].classList.add('active');
                } else {
                    items[i].classList.remove('active');
                }
            }
        }
        // Open popup on map
        if (focus && this.map && this.markers.length) {
            for (var j = 0; j < this.markers.length; j++) {
                if (this.markers[j].code === point.code) {
                    this.markers[j].marker.openPopup();
                    this.map.panTo(this.markers[j].marker.getLatLng());
                    break;
                }
            }
        }
        this.dispatch('mfb:pickup-point-selected', point);
    };

    MfbPickupMap.prototype.clear = function () {
        this.points = [];
        this.selected = null;
        if (this.targets.list) { this.targets.list.innerHTML = ''; }
        if (this.markerLayer) { this.markerLayer.clearLayers(); }
        if (this.targets.empty) { hide(this.targets.empty); }
        this.hideError();
    };

    MfbPickupMap.prototype.showLoader = function () { show(this.targets.loader); };
    MfbPickupMap.prototype.hideLoader = function () { hide(this.targets.loader); };
    MfbPickupMap.prototype.showError = function (msg) {
        if (!this.targets.error) { return; }
        setText(this.targets.error, msg);
        show(this.targets.error);
    };
    MfbPickupMap.prototype.hideError = function () { hide(this.targets.error); };

    MfbPickupMap.prototype.dispatch = function (name, detail) {
        var event;
        try {
            event = new CustomEvent(name, { detail: detail, bubbles: true });
        } catch (e) {
            event = document.createEvent('CustomEvent');
            event.initCustomEvent(name, true, false, detail);
        }
        this.root.dispatchEvent(event);
    };

    function autoInit() {
        var roots = document.querySelectorAll('[data-controller~="mfb-pickup-map"]');
        for (var i = 0; i < roots.length; i++) {
            if (!roots[i].__mfbPickupMap) {
                roots[i].__mfbPickupMap = new MfbPickupMap(roots[i]);
                if (readData(roots[i], 'autoload') === '1') {
                    roots[i].__mfbPickupMap.load();
                }
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoInit);
    } else {
        autoInit();
    }

    global.MfbPickupMap = MfbPickupMap;
    global.mfbPickupMapAutoInit = autoInit;
})(window);
