/**
 * MyFlyingBox - Delivery Options & Relay Point Selection
 *
 * External JS module that reads configuration and i18n strings from
 * a <script type="application/json" id="mfb-config"> block injected
 * by the Smarty template.
 *
 * @version 2.0.0
 */
(function () {
    "use strict";

    // ─── Configuration ───────────────────────────────────────────
    const configEl = document.getElementById("mfb-config");
    if (!configEl) {
        console.warn("MFB: missing #mfb-config element");
        return;
    }

    let cfg;
    try {
        cfg = JSON.parse(configEl.textContent);
    } catch (e) {
        console.error("MFB: invalid config JSON", e);
        return;
    }

    const googleMapsApiKey = cfg.googleMapsApiKey || "";
    const cartId = parseInt(cfg.cartId, 10) || 0;
    const addressId = cfg.addressId || "";
    const i18n = cfg.i18n || {};
    let selectedOfferId = null;
    let hasRelayOffers = false;
    let offersLoaded = false;

    // ─── DOM references (set in initMFB) ─────────────────────────
    let relayContainer = null;
    let searchContainer = null;
    let selectedRelayDiv = null;
    let relayInfoDiv = null;
    let relayCodeInput = null;
    let relayList = null;
    let mapContainer = null;
    let map = null;
    let markers = [];
    let currentOfferUuid = null;

    // ─── Utilities ───────────────────────────────────────────────

    function escapeHtml(text) {
        if (!text) return "";
        const div = document.createElement("div");
        div.textContent = text;
        return div.innerHTML;
    }

    function announceToScreenReader(message) {
        const srStatus = document.getElementById("mfb-sr-status");
        if (srStatus) {
            srStatus.textContent = "";
            setTimeout(function () {
                srStatus.textContent = message;
            }, 100);
        }
    }

    function debounce(func, delay) {
        let timeoutId;
        return function () {
            const context = this;
            const args = arguments;
            clearTimeout(timeoutId);
            timeoutId = setTimeout(function () {
                func.apply(context, args);
            }, delay);
        };
    }

    function t(key, fallback) {
        return i18n[key] || fallback || key;
    }

    // ─── Skeleton / Content Transition ───────────────────────────

    function transitionSkeletonToContent() {
        const skeleton = document.getElementById("mfb-skeleton");
        const content = document.getElementById("myflyingbox-options");
        if (!skeleton || !content) return;

        skeleton.style.animation = "mfb-fade-out 0.3s ease-out forwards";
        setTimeout(function () {
            skeleton.classList.add("mfb-hidden");
            content.classList.remove("mfb-content-hidden");
            content.classList.add("mfb-content-visible");
        }, 300);
    }

    function hideDeliveryBlock() {
        const row = document.getElementById("myflyingbox-options-row");
        if (row) row.style.display = "none";
    }

    function showErrorMessage(message) {
        const container = document.getElementById("mfb-offers-container");
        if (container) {
            container.innerHTML =
                '<div class="mfb-error-message" style="padding: 24px; background: #fef3c7; border: 2px solid #fbbf24; border-radius: 12px; text-align: center; color: #92400e; font-size: 1rem;">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: block; margin: 0 auto 12px;">' +
                '<circle cx="12" cy="12" r="10"></circle>' +
                '<line x1="12" y1="8" x2="12" y2="12"></line>' +
                '<line x1="12" y1="16" x2="12.01" y2="16"></line>' +
                "</svg>" +
                escapeHtml(message) +
                "</div>";
        }
        transitionSkeletonToContent();
    }

    // ─── Submit Button State ─────────────────────────────────────

    function updateSubmitButtonState() {
        const form = document.getElementById("form-cart-delivery");
        if (!form) return;

        const submitBtn = form.querySelector('button[type="submit"]');
        if (!submitBtn) return;

        const warningId = "mfb-relay-submit-warning";
        let warning = document.getElementById(warningId);

        const selectedRadio = document.querySelector(".mfb-offer-radio:checked");
        if (!selectedRadio) {
            if (warning) warning.style.display = "none";
            submitBtn.disabled = false;
            submitBtn.style.opacity = "";
            submitBtn.style.cursor = "";
            return;
        }

        const isRelay = selectedRadio.getAttribute("data-relay-delivery") === "1";
        const relayCode = document.getElementById("mfb-relay-code");
        const hasRelayCode = relayCode && relayCode.value.trim() !== "";

        if (isRelay && !hasRelayCode) {
            submitBtn.disabled = true;
            submitBtn.style.opacity = "0.5";
            submitBtn.style.cursor = "not-allowed";

            if (!warning) {
                warning = document.createElement("div");
                warning.id = warningId;
                warning.style.cssText =
                    "margin-top: 12px; padding: 12px 16px; background: #fef3c7; border: 2px solid #fbbf24; border-radius: 10px; color: #92400e; font-size: 0.95rem; font-weight: 500; display: flex; align-items: center; gap: 10px;";
                warning.innerHTML =
                    '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>' +
                    "<span>" +
                    escapeHtml(t("relayRequired")) +
                    "</span>";
                const relaySection = document.getElementById(
                    "myflyingbox-relay-container"
                );
                if (relaySection) {
                    relaySection.parentNode.insertBefore(
                        warning,
                        relaySection.nextSibling
                    );
                }
            }
            warning.style.display = "flex";
        } else {
            submitBtn.disabled = false;
            submitBtn.style.opacity = "";
            submitBtn.style.cursor = "";
            if (warning) warning.style.display = "none";
        }
    }

    // ─── Offer Rendering ─────────────────────────────────────────

    function renderOfferCards(offers) {
        const container = document.getElementById("mfb-offers-container");
        if (!container) return;

        let html = "";
        for (let i = 0; i < offers.length; i++) {
            const offer = offers[i];
            const relayClass = offer.relay_delivery ? " is-relay" : "";

            const serviceName = offer.service_name || "";
            const isServiceNameUseful =
                serviceName.length > 3 && !/^[\d\.\s]+$/.test(serviceName);

            html +=
                '<label class="mfb-offer-card' +
                relayClass +
                '" data-offer-id="' +
                offer.id +
                '">' +
                '<input type="radio" name="mfb_offer_id" value="' +
                offer.id +
                '"' +
                ' data-service-id="' +
                (offer.service_id || "") +
                '"' +
                ' data-relay-delivery="' +
                (offer.relay_delivery ? "1" : "0") +
                '"' +
                ' data-price="' +
                offer.price +
                '"' +
                ' data-api-uuid="' +
                (offer.api_offer_uuid || "") +
                '"' +
                ' class="mfb-offer-radio">' +
                '<div class="mfb-check-indicator">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">' +
                '<polyline points="20 6 9 17 4 12"></polyline>' +
                "</svg></div>" +
                '<div class="mfb-carrier-badge" data-carrier="' +
                (offer.carrier_code || "").toLowerCase() +
                '">' +
                '<img src="' +
                (offer.carrier_logo || offer.carrier_logo_fallback || "") +
                '"' +
                ' alt="' +
                escapeHtml((offer.carrier_code || "").toUpperCase()) +
                '"' +
                ' class="mfb-carrier-logo"' +
                ' data-fallback="' +
                (offer.carrier_logo_fallback || "") +
                '"' +
                ' onerror="this.onerror=null; this.src=this.dataset.fallback;"></div>' +
                '<div class="mfb-service-info">';

            if (isServiceNameUseful) {
                html +=
                    '<span class="mfb-service-name">' +
                    escapeHtml(serviceName) +
                    "</span>";
            }

            if (offer.relay_delivery) {
                html +=
                    '<span class="mfb-badge-relay">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor">' +
                    '<path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>' +
                    "</svg> " +
                    escapeHtml(t("relayPoint")) +
                    "</span>";
            }

            html += "</div>";

            if (offer.delivery_days) {
                html +=
                    '<div class="mfb-delivery-time">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                    '<circle cx="12" cy="12" r="10"></circle>' +
                    '<polyline points="12 6 12 12 16 14"></polyline>' +
                    "</svg><span>" +
                    escapeHtml(offer.delivery_days) +
                    "h</span></div>";
            }

            html +=
                '<div class="mfb-price">' +
                '<span class="mfb-price-value">' +
                escapeHtml(offer.price_formatted || offer.price + " \u20ac") +
                "</span></div>" +
                "</label>";
        }

        container.innerHTML = html;
    }

    // ─── Offer Selection ─────────────────────────────────────────

    function selectOfferById(offerId) {
        const radio = document.querySelector(
            '.mfb-offer-radio[value="' + offerId + '"]'
        );
        if (radio) selectOffer(radio);
    }

    function setupOfferHandlers() {
        const offerCards = document.querySelectorAll(".mfb-offer-card");
        for (let j = 0; j < offerCards.length; j++) {
            offerCards[j].addEventListener("click", function () {
                const radio = this.querySelector(".mfb-offer-radio");
                selectOffer(radio);
            });
        }

        const radios = document.querySelectorAll(".mfb-offer-radio");
        for (let k = 0; k < radios.length; k++) {
            radios[k].addEventListener("change", function () {
                selectOffer(this);
            });
        }
    }

    function selectOffer(radio) {
        if (!radio) return;

        radio.checked = true;

        const allCards = document.querySelectorAll(".mfb-offer-card");
        for (let i = 0; i < allCards.length; i++) {
            const item = allCards[i];
            item.classList.remove("selected");
            item.style.borderColor = "";
            item.style.background = "";
            item.style.boxShadow = "";
            const chk = item.querySelector(".mfb-check-indicator");
            if (chk) {
                chk.style.background = "";
                chk.style.color = "";
            }
        }

        const card = radio.closest(".mfb-offer-card");
        card.classList.add("selected");
        card.style.borderColor = "#22c55e";
        card.style.background =
            "linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%)";
        card.style.boxShadow = "0 8px 25px rgba(34, 197, 94, 0.25)";

        const checkIndicator = card.querySelector(".mfb-check-indicator");
        if (checkIndicator) {
            checkIndicator.style.background = "#22c55e";
            checkIndicator.style.color = "white";
        }

        saveOfferSelection(radio.value);

        const isRelay = radio.getAttribute("data-relay-delivery") === "1";
        currentOfferUuid = radio.getAttribute("data-api-uuid");

        const offerChanged =
            selectedOfferId && selectedOfferId != radio.value;

        if (relayContainer) {
            if (isRelay) {
                relayContainer.style.display = "block";

                if (offerChanged && relayCodeInput) {
                    relayCodeInput.value = "";
                    if (selectedRelayDiv) selectedRelayDiv.style.display = "none";
                    if (searchContainer) searchContainer.style.display = "block";
                }

                if (relayCodeInput && !relayCodeInput.value) {
                    if (selectedRelayDiv)
                        selectedRelayDiv.style.display = "none";
                    if (searchContainer) searchContainer.style.display = "block";

                    const searchInput = document.getElementById(
                        "mfb-search-location"
                    );
                    if (searchInput && searchInput.value.trim().length >= 2) {
                        setTimeout(function () {
                            searchRelayPoints(searchInput.value.trim());
                        }, 0);
                    }
                }
            } else {
                relayContainer.style.display = "none";
                if (relayCodeInput) relayCodeInput.value = "";
            }
        }

        selectedOfferId = radio.value;
        updateSubmitButtonState();
    }

    function saveOfferSelection(offerId) {
        fetch("/myflyingbox/offer/save", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ cart_id: cartId, offer_id: offerId }),
        })
            .then(function (response) {
                return response.json();
            })
            .catch(function (error) {
                console.error("MFB: Error saving offer", error);
            });
    }

    // ─── Relay Points ────────────────────────────────────────────

    function displaySelectedRelay(relay) {
        const selectedDiv = document.getElementById("mfb-selected-relay");
        const searchDiv = document.getElementById("mfb-relay-search");
        const infoDiv = document.getElementById("mfb-relay-info");
        const codeInput = document.getElementById("mfb-relay-code");

        if (selectedDiv && infoDiv && relay) {
            infoDiv.innerHTML =
                "<strong>" +
                escapeHtml(relay.name) +
                "</strong><br>" +
                escapeHtml(relay.street) +
                "<br>" +
                escapeHtml(relay.postal_code) +
                " " +
                escapeHtml(relay.city);
            selectedDiv.style.display = "flex";
            if (searchDiv) searchDiv.style.display = "none";
            if (codeInput) codeInput.value = relay.code;
        }
    }

    function searchRelayPoints(query) {
        relayList.innerHTML =
            '<div class="mfb-loading">' +
            escapeHtml(t("searching")) +
            "</div>";
        announceToScreenReader(t("searching"));

        let relaySearchUrl =
            "/myflyingbox/relay-points?query=" +
            encodeURIComponent(query) +
            "&cart_id=" +
            cartId;
        const selectedRadio = document.querySelector(
            ".mfb-offer-radio:checked"
        );
        if (selectedRadio && selectedRadio.value) {
            relaySearchUrl +=
                "&offer_id=" + encodeURIComponent(selectedRadio.value);
        }

        fetch(relaySearchUrl)
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.success && data.relays && data.relays.length > 0) {
                    displayRelayPoints(data.relays);
                } else {
                    showRelayFallbackMessage();
                }
            })
            .catch(function () {
                showRelayFallbackMessage();
            });
    }

    function showRelayFallbackMessage() {
        relayList.innerHTML =
            '<div style="padding: 24px; text-align: center; color: #64748b; background: #f8fafc; border-radius: 12px;">' +
            '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin: 0 auto 12px; display: block; color: #94a3b8;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>' +
            '<p style="margin: 0 0 12px; font-weight: 600; color: #475569;">' +
            escapeHtml(t("relaySearchUnavailable")) +
            "</p>" +
            '<p style="margin: 0; font-size: 0.9rem; color: #64748b;">' +
            escapeHtml(t("relayFallback")) +
            "</p>" +
            "</div>";
        if (mapContainer) mapContainer.style.display = "none";
    }

    function displayRelayPoints(relays) {
        relayList.innerHTML = "";
        announceToScreenReader(
            relays.length + " " + t("relayPointsFound")
        );

        relays.forEach(function (relay, index) {
            const item = document.createElement("div");
            item.className = "mfb-relay-item";
            item.dataset.code = relay.code;
            item.setAttribute("tabindex", "0");
            item.setAttribute("role", "option");
            item.setAttribute(
                "aria-label",
                escapeHtml(relay.name) +
                    ", " +
                    escapeHtml(relay.street) +
                    ", " +
                    escapeHtml(relay.postal_code) +
                    " " +
                    escapeHtml(relay.city) +
                    (relay.distance ? " (" + relay.distance + " km)" : "")
            );

            let html =
                '<div class="relay-name">' +
                escapeHtml(relay.name) +
                "</div>";
            html +=
                '<div class="relay-address">' +
                escapeHtml(relay.street) +
                "<br>" +
                escapeHtml(relay.postal_code) +
                " " +
                escapeHtml(relay.city) +
                "</div>";
            if (relay.distance) {
                html +=
                    '<div class="relay-distance"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg> ' +
                    relay.distance +
                    " km</div>";
            }
            item.innerHTML = html;

            item.addEventListener("click", function () {
                selectRelay(relay);
            });

            item.addEventListener("keydown", function (e) {
                if (e.key === "Enter" || e.key === " ") {
                    e.preventDefault();
                    selectRelay(relay);
                } else if (
                    e.key === "ArrowDown" ||
                    e.key === "ArrowRight"
                ) {
                    e.preventDefault();
                    const next = this.nextElementSibling;
                    if (next && next.classList.contains("mfb-relay-item"))
                        next.focus();
                } else if (
                    e.key === "ArrowUp" ||
                    e.key === "ArrowLeft"
                ) {
                    e.preventDefault();
                    const prev = this.previousElementSibling;
                    if (prev && prev.classList.contains("mfb-relay-item"))
                        prev.focus();
                } else if (e.key === "Escape") {
                    e.preventDefault();
                    const escSearchInput = document.getElementById(
                        "mfb-search-location"
                    );
                    if (escSearchInput) escSearchInput.focus();
                }
            });

            relayList.appendChild(item);
        });

        if (googleMapsApiKey && relays.length > 0) {
            initMap(relays);
        }
    }

    function selectRelay(relay) {
        fetch("/myflyingbox/relay/save", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                cart_id: cartId,
                relay_code: relay.code,
                relay_name: relay.name,
                relay_street: relay.street,
                relay_city: relay.city,
                relay_postal_code: relay.postal_code,
                relay_country: relay.country || "FR",
            }),
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.success) {
                    relayCodeInput.value = relay.code;
                    relayInfoDiv.innerHTML =
                        "<strong>" +
                        escapeHtml(relay.name) +
                        "</strong><br>" +
                        escapeHtml(relay.street) +
                        "<br>" +
                        escapeHtml(relay.postal_code) +
                        " " +
                        escapeHtml(relay.city);
                    selectedRelayDiv.style.display = "flex";
                    searchContainer.style.display = "none";
                    updateSubmitButtonState();
                    announceToScreenReader(
                        t("relaySelected") + ": " + relay.name
                    );
                } else {
                    alert(data.message || t("selectionError"));
                }
            })
            .catch(function () {
                alert(t("selectionError"));
            });
    }

    function highlightRelayItem(code) {
        document
            .querySelectorAll(".mfb-relay-item")
            .forEach(function (item) {
                item.classList.remove("selected");
                item.setAttribute("aria-selected", "false");
                if (item.dataset.code === code) {
                    item.classList.add("selected");
                    item.setAttribute("aria-selected", "true");
                    item.scrollIntoView({
                        behavior: "smooth",
                        block: "nearest",
                    });
                }
            });
    }

    // ─── Google Maps ─────────────────────────────────────────────

    function initMap(relays) {
        if (!window.google || !window.google.maps) {
            const script = document.createElement("script");
            script.src =
                "https://maps.googleapis.com/maps/api/js?key=" +
                googleMapsApiKey +
                "&callback=mfbInitMapCallback";
            script.async = true;
            window.mfbInitMapCallback = function () {
                createMap(relays);
            };
            window.mfbRelays = relays;
            document.head.appendChild(script);
        } else {
            createMap(relays);
        }
    }

    function createMap(relays) {
        if (!mapContainer) return;
        mapContainer.style.display = "block";

        const bounds = new google.maps.LatLngBounds();
        map = new google.maps.Map(document.getElementById("mfb-map"), {
            zoom: 12,
            mapTypeControl: false,
            streetViewControl: false,
            styles: [
                { featureType: "poi", stylers: [{ visibility: "off" }] },
            ],
        });

        markers.forEach(function (m) {
            m.setMap(null);
        });
        markers = [];

        relays.forEach(function (relay, index) {
            if (relay.latitude && relay.longitude) {
                const position = new google.maps.LatLng(
                    parseFloat(relay.latitude),
                    parseFloat(relay.longitude)
                );
                bounds.extend(position);

                const marker = new google.maps.Marker({
                    position: position,
                    map: map,
                    title: relay.name,
                    label: {
                        text: String(index + 1),
                        color: "white",
                        fontWeight: "bold",
                    },
                    icon: {
                        path: google.maps.SymbolPath.CIRCLE,
                        scale: 14,
                        fillColor: "#f59e0b",
                        fillOpacity: 1,
                        strokeColor: "#ffffff",
                        strokeWeight: 3,
                    },
                });

                marker.addListener("click", function () {
                    highlightRelayItem(relay.code);
                    selectRelay(relay);
                });

                markers.push(marker);
            }
        });

        if (markers.length > 0) {
            map.fitBounds(bounds);
        }

        setupMapKeyboardNav();
    }

    function setupMapKeyboardNav() {
        const mapEl = document.getElementById("mfb-map");
        if (!mapEl) return;

        mapEl.setAttribute("tabindex", "0");
        mapEl.setAttribute("role", "application");
        mapEl.setAttribute("aria-label", t("mapLabel"));

        mapEl.addEventListener("keydown", function (e) {
            if (e.key === "Escape") {
                e.preventDefault();
                const firstRelay = relayList
                    ? relayList.querySelector(".mfb-relay-item")
                    : null;
                if (firstRelay) {
                    firstRelay.focus();
                } else {
                    const mapSearchInput = document.getElementById(
                        "mfb-search-location"
                    );
                    if (mapSearchInput) mapSearchInput.focus();
                }
            }
        });
    }

    // ─── Load Offers ─────────────────────────────────────────────

    function loadOffers() {
        if (!cartId) {
            hideDeliveryBlock();
            return;
        }

        let url = "/myflyingbox/offers?cart_id=" + cartId + "&create_quote=1";
        if (addressId) {
            url += "&address_id=" + addressId;
        }

        const abortController = new AbortController();
        const timeoutId = setTimeout(function () {
            abortController.abort();
        }, 15000);

        fetch(url, { signal: abortController.signal })
            .then(function (response) {
                clearTimeout(timeoutId);
                return response.json();
            })
            .then(function (data) {
                if (
                    data.success &&
                    data.offers &&
                    data.offers.length > 0
                ) {
                    selectedOfferId = data.selected_offer_id;
                    hasRelayOffers = data.has_relay_offers;
                    offersLoaded = true;

                    const countEl = document.getElementById("mfb-offers-count");
                    if (countEl) countEl.textContent = data.offers.length;
                    announceToScreenReader(
                        data.offers.length + " " + t("optionsAvailable")
                    );

                    renderOfferCards(data.offers);
                    transitionSkeletonToContent();

                    setTimeout(function () {
                        setupOfferHandlers();

                        if (data.selected_relay) {
                            displaySelectedRelay(data.selected_relay);
                        }

                        const deliveryPostalCode =
                            data.delivery_postal_code || "";
                        const deliveryCountry =
                            data.delivery_country || "FR";
                        const searchInput = document.getElementById(
                            "mfb-search-location"
                        );

                        if (searchInput && deliveryPostalCode) {
                            searchInput.value = deliveryPostalCode;
                            searchInput.dataset.country = deliveryCountry;

                            if (
                                data.selected_offer_id &&
                                data.has_relay_offers &&
                                !data.selected_relay
                            ) {
                                setTimeout(function () {
                                    const searchBtn = document.getElementById(
                                        "mfb-search-btn"
                                    );
                                    if (searchBtn) searchBtn.click();
                                }, 800);
                            }
                        }

                        if (selectedOfferId) {
                            selectOfferById(selectedOfferId);
                        } else if (data.offers.length > 0) {
                            selectOfferById(data.offers[0].id);
                        }

                        setTimeout(updateSubmitButtonState, 100);
                    }, 500);
                } else {
                    showErrorMessage(t("noDeliveryOptions"));
                }
            })
            .catch(function (error) {
                clearTimeout(timeoutId);
                if (error.name === "AbortError") {
                    showErrorMessage(t("loadTimeout"));
                } else {
                    showErrorMessage(t("loadError"));
                }
            });
    }

    // ─── Init ────────────────────────────────────────────────────

    function initMFB() {
        loadOffers();

        const optionsContainer = document.getElementById("myflyingbox-options");
        if (!optionsContainer) return;

        relayContainer = document.getElementById(
            "myflyingbox-relay-container"
        );
        searchContainer = document.getElementById("mfb-relay-search");
        selectedRelayDiv = document.getElementById("mfb-selected-relay");
        relayInfoDiv = document.getElementById("mfb-relay-info");
        relayCodeInput = document.getElementById("mfb-relay-code");

        const searchInput = document.getElementById("mfb-search-location");
        const searchBtn = document.getElementById("mfb-search-btn");
        const changeBtn = document.getElementById("mfb-change-relay");
        relayList = document.getElementById("mfb-relay-list");
        mapContainer = document.getElementById("mfb-map-container");

        if (searchBtn) {
            searchBtn.addEventListener("click", function () {
                const query = searchInput.value.trim();
                if (query.length < 2) {
                    alert(t("minChars"));
                    return;
                }
                searchRelayPoints(query);
            });
        }

        if (searchInput) {
            searchInput.addEventListener("keypress", function (e) {
                if (e.key === "Enter") {
                    e.preventDefault();
                    searchBtn.click();
                }
            });

            const debouncedSearch = debounce(function () {
                const query = searchInput.value.trim();
                if (query.length < 3) return;

                const country = searchInput.dataset.country || "FR";
                const postalCodeMatch = query.match(/\d{5}/);
                const postalCode = postalCodeMatch ? postalCodeMatch[0] : "";

                if (
                    country === "FR" &&
                    postalCode &&
                    postalCode.length === 5
                ) {
                    searchRelayPoints(query);
                } else if (query.length >= 5) {
                    searchRelayPoints(query);
                }
            }, 500);

            searchInput.addEventListener("input", debouncedSearch);
        }

        if (changeBtn) {
            changeBtn.addEventListener("click", function () {
                selectedRelayDiv.style.display = "none";
                searchContainer.style.display = "block";
                if (relayCodeInput) relayCodeInput.value = "";
                updateSubmitButtonState();
            });
        }
    }

    // ─── Bootstrap ───────────────────────────────────────────────

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initMFB);
    } else {
        initMFB();
    }
})();

// Hide default module row
(function () {
    const optionsRow = document.getElementById("myflyingbox-options-row");
    if (optionsRow) {
        let prevRow = optionsRow.previousElementSibling;
        while (prevRow && prevRow.tagName !== "TR") {
            prevRow = prevRow.previousElementSibling;
        }
        if (
            prevRow &&
            prevRow.tagName === "TR" &&
            prevRow.id &&
            prevRow.id.startsWith("delivery-module-")
        ) {
            const moduleRadio = prevRow.querySelector('input[type="radio"]');
            if (moduleRadio) moduleRadio.checked = true;
            prevRow.style.display = "none";
        }
    }
})();
