class TP_MapMarker extends google.maps.Marker
{
    /**
     *
     * @type {TP_Mappable[]}
     */
    items = [];

    color = "#000";

    geoStr = "";

    constructor(options) {
        if (!options.hasOwnProperty('icon')) {
            options.icon = {
                path: "M172.268 501.67C26.97 291.031 0 269.413 0 192 0 85.961 85.961 0 192 0s192 85.961 192 192c0 77.413-26.97 99.031-172.268 309.67-9.535 13.774-29.93 13.773-39.464 0z", // from FontAwesome
                fillColor: options.color ?? "#000",
                fillOpacity: .85,
                anchor: new google.maps.Point(172.268, 501.67),
                strokeWeight: 1,
                scale: 0.04,
                labelOrigin: new google.maps.Point(190, 198)
            }
        }
        super(options)
        super.addListener("click", this.handleClick);
    }

    get visibleItems() {
        return this.items.filter((i) => i._visible);
    }

    get visible() {
        return this.visibleItems.length > 0
    }

    get inBounds() {
        return this.getMap().getBounds().contains(this.getPosition());
    }

    get useIcon() {
        let icon = this.visibleItems.find((i) => i.useIcon !== false)
        if (icon === undefined) {
            return false;
        }
        return icon.useIcon;
    }

    updateLabel() {
        let icon = super.getIcon();

        // Update icon color
        this.color = tpvm._utils.averageColor(this.visibleItems.map((i) => i.color))
        if (icon !== undefined && icon.hasOwnProperty("fillColor")) {
            icon.fillColor = this.color;
            super.setIcon(icon);
        }

        // Update visibility
        super.setVisible(this.visibleItems.length > 0);

        // Update title
        super.setTitle(tpvm._utils.stringArrayToListString(this.visibleItems.map((i) => i.name)))

        // Update label proper
        let label = null;
        if (this.visibleItems.length > 1) {
            label = {
                text: this.visibleItems.length.toString(),
                color: "#000000",
                fontSize: "100%"
            }
        } else if (this.useIcon !== false) { // icon for secure partners
            label = this.useIcon;
        }
        super.setLabel(label);
    }

    handleClick() {
        const mp = this.getMap();
        TP_MapMarker.smoothZoom(mp)
        mp.panTo(this.getPosition());
    }

    /**
     * Smoothly zoom in (or out) on the given map.  By default, zooms in to the max level allowed.
     *
     * @param {google.maps.Map} map The Google Maps map
     * @param {number, undefined} zoomTo Google Maps zoom level, or undefined for maxZoom.
     */
    static async smoothZoom(map, zoomTo = undefined) {
        if (zoomTo === undefined || zoomTo > map.maxZoom) {
            zoomTo = map.maxZoom;
        }
        if (map.getZoom() !== zoomTo) {
            let z = google.maps.event.addListener(map, 'zoom_changed', () => {
                google.maps.event.removeListener(z);
                this.smoothZoom(map, zoomTo);
            });
            if (map.getZoom() < zoomTo) { // zoom in
                setTimeout(() => map.setZoom(map.getZoom() + 1), 200);
            } else { // zoom out
                setTimeout(() => map.setZoom(map.getZoom() - 1), 200);
            }
        }
    }
}

class TP_Mappable {
    name = "";
    post_id = 0;

    geo = {};

    color = "#f00";

    static items = [];

    _visible = true;

    /**
     * All markers on all maps.
     *
     * @type {TP_MapMarker[]}
     */
    static markers = [];

    /**
     * Markers for this specific object.
     *
     * @type {TP_MapMarker[]}
     */
    markers = [];

    constructor(obj) {
        this.geo = [obj.geo] ?? [];

        this.name = obj.name.replace("&amp;", "&");
        this.post_id = obj.post_id;

        if (obj.post_id === undefined) {
            this.post_id = 0;
        }

        for (const ei in this.connectedElements) {
            if (!this.connectedElements.hasOwnProperty(ei)) continue;

            let mappable = this;
            this.connectedElements[ei].addEventListener('mouseenter', function(e){e.stopPropagation(); mappable.toggleHighlighted(true);});
            this.connectedElements[ei].addEventListener('mouseleave', function(e){e.stopPropagation(); mappable.toggleHighlighted(false);});

            let actionBtns = this.connectedElements[ei].querySelectorAll('[data-tp-action]')
            for (const ai in actionBtns) {
                if (!actionBtns.hasOwnProperty(ai)) continue;
                const action = actionBtns[ai].getAttribute('data-tp-action');
                if (typeof mappable[action + "Action"] === "function") {
                    tpvm._utils.registerAction(action, mappable, mappable.post_id)
                    actionBtns[ai].addEventListener('click', function (e) {
                        e.stopPropagation();
                        mappable[action + "Action"]();
                    });
                }
            }
        }

        TP_Mappable.items.push(this);
    }

    static initMap(containerElt, mapOptions, list) {
        google.maps.visualRefresh = true;
        const map = new google.maps.Map(containerElt, mapOptions);

        for (const ii in list) {
            if (!list.hasOwnProperty(ii)) continue;

            // skip items that aren't locatable.
            for (const gi in list[ii].geo) {
                if (list[ii].geo[gi] === null || list[ii].geo[gi].lat === null || list[ii].geo[gi].lng === null)
                    continue;

                const item = list[ii],
                    geoStr = "" + item.geo[gi].lat + "," + item.geo[gi].lng;
                let mkr = this.markers.find((m) => m.getMap() === map && m.geoStr === geoStr);

                // If there isn't already a marker for the item on the right map, create one.
                if (mkr === undefined) {
                    mkr = new TP_MapMarker({
                        position: item.geo[gi],
                        color: item.color,
                        map: map,
                    });
                    mkr.geoStr = geoStr;

                    // Add to collection of all markers
                    this.markers.push(mkr);
                }

                // If the marker doesn't already have a reference to this item, add one.
                if (!mkr.items.includes(item)) {
                    mkr.items.push(item);
                }

                // If the item doesn't already have a reference to this marker, add one.
                if (!item.markers.includes(mkr)) {
                    item.markers.push(mkr);
                }

                mkr.updateLabel();
            }
        }

        map.addListener('bounds_changed', this.handleZoom);
    }

    /**
     * Currently, this will apply visibility to ALL mappable items, even if they're on a different map.
     */
    static handleZoom() {
        for (const ii in TP_Mappable.items) {
            TP_Mappable.items[ii].applyVisibilityToConnectedElements();
        }
    }

    updateMarkerLabels() {
        for (const mi in this.markers) {
            this.markers[mi].updateLabel();
        }
    }

    showOnMapAction() {
        if (this.markers.length === 1) {
            let mp = this.markers[0].getMap();
            TP_MapMarker.smoothZoom(mp);
            mp.panTo(this.markers[0].getPosition());
        } else {
            // Set visibility on all markers
            for (const mi in TP_Mappable.markers) {
                for (const ii in TP_Mappable.markers[mi].items) {
                    TP_Mappable.markers[mi].items[ii].toggleVisibility(TP_Mappable.markers[mi].items[ii] === this);
                }
            }
        }
    }

    get visible() {
        return this._visible && this.markers.some((m) => m.visible);
    }

    get inBounds() {
        return this.markers.some((m) => m.inBounds);
    }

    get useIcon() {
        return false;
    }

    get highlightable() {
        return true;
    }

    toggleVisibility(vis = null) {
        if (vis === null) {
            this._visible = !this._visible
        } else {
            this._visible = !!vis;
        }

        this._visible = vis;
        this.updateMarkerLabels();

        this.applyVisibilityToConnectedElements();

        return this._visible;
    }

    get connectedElements() {
        const clsName = this.constructor.name.toLowerCase().replace("_", "-");
        const sPath = '[data-' + clsName + '="' + this.post_id + '"]'
        return document.querySelectorAll(sPath);
    }

    applyVisibilityToConnectedElements() {
        let elts = this.connectedElements;
        for (const ei in elts) {
            if (!elts.hasOwnProperty(ei))
                continue;
            elts[ei].style.display = (this.visible && this.inBounds) ? "" : "none";
        }
    }

    toggleHighlighted(hl) {
        this.highlighted = !!hl;

        if (!this.highlightable)
            this.highlighted = false;

        if (this.highlighted) {
            let item = this;
            for (let mi in this.markers) {
                const mk = item.markers[mi];
                if (mk.getAnimation() !== google.maps.Animation.BOUNCE) {
                    mk.setAnimation(google.maps.Animation.BOUNCE);
                }
            }
        } else {
            for (const mi in this.markers) {
                let mk = this.markers[mi];
                if (mk.getAnimation() !== null) {
                    mk.setAnimation(null)
                }
            }
        }
    }
}

class TP_Partner extends TP_Mappable {
    attributes = {};

    static currentFilters = {};

    static actions = [];

    static _secureCount = 0;

    constructor(obj) {
        super(obj);

        this.attributes = obj.attributes ?? null;

        tpvm.partners[this.post_id] = this;
    }

    // noinspection JSUnusedGlobalSymbols  Used via dynamic instantiation.
    static fromObjArray(pArr) {
        let ret = [];
        for (const p in pArr) {
            if (!pArr.hasOwnProperty(p)) continue;

            let pid = 0;
            if (typeof pArr[p].post_id !== "undefined") {
                pid = pArr[p].post_id;
            }

            if (typeof tpvm.partners[pid] === "undefined") {
                ret.push(new this(pArr[p]));
            } else {
                // Partner exists, and probably needs something added to it. (This should only happen for secure partners.)
                if (pArr[p].geo !== undefined) {
                    tpvm.partners[pid].geo.push(pArr[p].geo)
                }
                ret.push(tpvm.partners[pid]);
            }
        }
        tpvm.trigger("Partner_fromObjArray");
        return ret;
    };

    get useIcon() {
        if (this.post_id === 0) {
            return {
                text: "\uf023",
                fontFamily: "FontAwesome",
                color: "#00000088",
                fontSize: "90%"
            }
        }
        return false;
    }

    get highlightable() {
        return this.post_id !== 0;
    }

    static initFilters() {
        const filtOptions = document.querySelectorAll("[data-partner-filter]");
        for (const ei in filtOptions) {
            if (!filtOptions.hasOwnProperty(ei)) continue;
            filtOptions[ei].addEventListener('change', this.applyFilters.bind(this, "Partner"))
        }
    }

    // static applyFilters(ev = null) {
    //     if (ev !== null) {
    //         let attr = ev.target.getAttribute("data-partner-filter"),
    //             val = ev.target.value;
    //         if (attr !== null) {
    //             if (val === "") {
    //                 delete this.currentFilters[attr];
    //             } else {
    //                 this.currentFilters[attr] = val;
    //             }
    //         }
    //     }
    //
    //     groupLoop:
    //         for (const ii in tpvm.partners) {
    //             if (!tpvm.partners.hasOwnProperty(ii)) continue;
    //             const group = tpvm.partners[ii];
    //             for (const ai in this.currentFilters) {
    //                 if (!this.currentFilters.hasOwnProperty(ai)) continue;
    //
    //                 if (!group.attributes.hasOwnProperty(ai) ||
    //                     group.attributes[ai] === null ||
    //                     (   !Array.isArray(group.attributes[ai]) &&
    //                         group.attributes[ai].slug !== this.currentFilters[ai] &&
    //                         group.attributes[ai] !== this.currentFilters[ai]
    //                     ) || (
    //                         Array.isArray(group.attributes[ai]) &&
    //                         group.attributes[ai].find(a => a.slug === this.currentFilters[ai]) === undefined
    //                     )
    //                 ) {
    //                     group.toggleVisibility(false)
    //                     continue groupLoop;
    //                 }
    //             }
    //             group.toggleVisibility(true)
    //         }
    // }

    get visibility() {
        return this._visible;
    }

    static init() {
        tpvm.trigger('Partner_class_loaded');
    }

    static initMap(mapDivId) {
        let mapOptions = {
            mapTypeId: google.maps.MapTypeId.HYBRID,
            linksControl: false,
            maxZoom: 10,
            panControl: false,
            addressControl: false,
            enableCloseButton: false,
            mapTypeControl: false,
            zoomControl: false,
            gestureHandling: 'greedy',
            styles: [
                {
                    featureType: "poi", //points of interest
                    stylers: [
                        {visibility: 'off'}
                    ]
                },
                {
                    featureType: "road",
                    stylers: [
                        {visibility: 'off'}
                    ]
                },
                {
                    featureType: "transit",
                    stylers: [
                        {visibility: 'off'}
                    ]
                }
            ],
            zoom: 2,
            center: {lat: 0, lng: 0},
            streetViewControl: false,
            fullscreenControl: false,
            disableDefaultUI: true
        };

        super.initMap(document.getElementById(mapDivId), mapOptions, tpvm.partners, TP_Partner)
    }

}
TP_Partner.prototype.classShort = "gp";
TP_Partner.init();

function FromOld() { // TODO remove

    resetMap = function(e) {
        infowindow.close();
        if (e) {
            e.preventDefault();
        }
        map.fitBounds(bounds);
        map.panToBounds(bounds);
    }

    applyFilters = function() {
        bounds = new google.maps.LatLngBounds()

        for (const mi in goMapMarkers) {
            let selElt = document.getElementById('types-' + goMapMarkers[mi].partnerType);
            if (selElt !== null) {
                if (document.getElementById('types-' + goMapMarkers[mi].partnerType).checked) {
                    goMapMarkers[mi].setVisible(true);
                    //markerClusterer.addMarker(goMapMarkers[mi]);
                    bounds.extend(goMapMarkers[mi].position);
                } else {
                    goMapMarkers[mi].setVisible(false);
                    //markerClusterer.removeMarker(goMapMarkers[mi]);
                }
            }

        }

        // MC = markerClusterer;
        // markerClusterer.repaint();

    }
    applyFilters();
    resetMap();
}
// google.maps.event.addDomListener(window, 'load', initialize);