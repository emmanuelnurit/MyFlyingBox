# MyFlyingBox BO assets

## `mfb-pickup-map.js` — pickup-point map widget

Vanilla-JS controller (Stimulus-style data-attribute API) for the back-office
shipment tab. Handles search, list rendering, Leaflet map and selection
callback for the relay-point picker.

### Include in template (THE-549)

```smarty
<link rel="stylesheet" href="{$asset_url}/css/mfb-pickup-map.css"/>
<script src="{$asset_url}/js/mfb-pickup-map.js" defer></script>
```

### Markup contract

```html
<div data-controller="mfb-pickup-map"
     data-mfb-pickup-map-endpoint-value="/admin/module/MyFlyingBox/order/{$order_id}/shipment/pickup-points"
     data-mfb-pickup-map-option-value="{$selected_option_code}"
     data-mfb-pickup-map-token-value="{$csrf_token}"
     data-mfb-pickup-map-postal-code-value="{$recipient_postal_code}"
     data-mfb-pickup-map-country-value="{$recipient_country}"
     data-mfb-pickup-map-selected-code-value="{$current_relay_code}"
     data-mfb-pickup-map-autoload="1"
     class="mfb-pickup-map-widget">

    <div class="mfb-pickup-map-toolbar">
        <input type="text"
               class="form-control"
               placeholder="Code postal"
               value="{$recipient_postal_code}"
               data-mfb-pickup-map-target="postalCode"/>
        <input type="text"
               class="form-control"
               placeholder="Pays (FR)"
               value="{$recipient_country}"
               data-mfb-pickup-map-target="country"/>
        <button type="button" class="btn btn-default"
                data-mfb-pickup-map-target="search">
            Rechercher
        </button>
    </div>

    <div class="mfb-pickup-loader" data-mfb-pickup-map-target="loader" style="display:none;">
        <span class="glyphicon glyphicon-refresh mfb-spin"></span> Chargement…
    </div>
    <div class="mfb-pickup-error" data-mfb-pickup-map-target="error" style="display:none;"></div>
    <div class="mfb-pickup-empty" data-mfb-pickup-map-target="empty" style="display:none;">
        Aucun point relais trouvé.
    </div>

    <div class="mfb-pickup-map-body">
        <div class="mfb-pickup-map-list list-group" data-mfb-pickup-map-target="list"></div>
        <div class="mfb-pickup-map-canvas" data-mfb-pickup-map-target="map"></div>
    </div>

    <input type="hidden" name="relay_code"
           data-mfb-pickup-map-target="selectedCode"
           value="{$current_relay_code}"/>
    <div class="mfb-pickup-selected" data-mfb-pickup-map-target="selectedSummary"></div>
</div>
```

### JS API

```js
// Auto-discovered on DOMContentLoaded.
const root = document.querySelector('[data-controller~="mfb-pickup-map"]');
const widget = root.__mfbPickupMap;        // class instance
widget.setOption('CHRONO_RELAIS');         // change carrier option, triggers reload
widget.load();                             // reload with current state
widget.getSelected();                      // returns the selected relay payload

// React to selection from the parent shipment-tab controller:
root.addEventListener('mfb:pickup-point-selected', (e) => {
    console.log(e.detail.code);            // pass into save endpoint payload
});
root.addEventListener('mfb:pickup-points-loaded', (e) => console.log(e.detail.count));
root.addEventListener('mfb:pickup-points-error', (e) => console.warn(e.detail.message));
```

### Endpoint contract (THE-547)

Accepts:
- `option` (carrier option code; required)
- `postal_code`, `country`, `city`, `street` (search hints)

Returns one of:
- `{ success: true, relays: [...] }`
- `{ success: true, data: { relays: [...] } }`
- `{ success: true, data: [...] }`
- raw `[ ... ]`

Each relay item should look like:

```json
{
    "code": "FR-XYZ-001",
    "name": "Tabac du Centre",
    "street": "12 rue de la Paix",
    "postal_code": "75002",
    "city": "Paris",
    "country": "FR",
    "latitude": 48.8694,
    "longitude": 2.3315,
    "distance": 0.4,
    "opening_hours": { "Mon": "09:00-18:00" }
}
```

Errors are surfaced when `success === false` (`error` / `message` field) or
on HTTP non-2xx. The widget never breaks the UI: it shows the error region
and keeps the list/map empty.

### Notes

- Leaflet 1.9.4 is loaded lazily from unpkg the first time a widget mounts.
  No bundling required.
- Stimulus is not part of Thelia 2.6 BO; the widget mimics Stimulus
  `data-controller` / `data-*-target` / `data-*-value` conventions so the
  template stays declarative and the upgrade path to Stimulus 3.x in a
  future BO modernisation is mechanical.
- Mobile-friendly: list and map stack under 768px.
