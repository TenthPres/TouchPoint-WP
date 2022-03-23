class TP_Partner {
    name = "";
    post_id = "";
    _visible = true;

    attributes = {};

    mapMarker = null;
    geo = {};

    static currentFilters = {};

    static actions = [];

    static mapMarkers = {};

    constructor(obj) {
        this.name = obj.name;
        this.post_id = obj.post_id;

        this.attributes = obj.attributes ?? null;

        for (const ei in this.connectedElements) {
            if (!this.connectedElements.hasOwnProperty(ei)) continue;

            let prtnr = this;
            this.connectedElements[ei].addEventListener('mouseenter', function(e){e.stopPropagation(); prtnr.toggleHighlighted(true);});
            this.connectedElements[ei].addEventListener('mouseleave', function(e){e.stopPropagation(); prtnr.toggleHighlighted(false);});

            // let actionBtns = this.connectedElements[ei].querySelectorAll('[data-tp-action]')
            // for (const ai in actionBtns) {
            //     if (!actionBtns.hasOwnProperty(ai)) continue;
            //     const action = actionBtns[ai].getAttribute('data-tp-action');
            //     if (TP_Involvement.actions.includes(action)) {
            //         tpvm._utils.registerAction(action, prtnr, prtnr.post_id)
            //         actionBtns[ai].addEventListener('click', function (e) {
            //             e.stopPropagation();
            //             inv[action + "Action"]();
            //         });
            //     }
            // }
        }

        this.geo = obj.geo ?? null;

        tpvm.partners[this.post_id] = this;
    }

    // noinspection JSUnusedGlobalSymbols  Used via dynamic instantiation.
    static fromObjArray(pArr) {
        let ret = [];
        for (const p in pArr) {
            if (!pArr.hasOwnProperty(p)) continue;

            if (typeof pArr[p].post_id === "undefined") {
                continue;
            }

            if (typeof tpvm.partners[pArr[p].post_id] === "undefined") {
                ret.push(new this(pArr[p]));
            } else {
                ret.push(tpvm.partners[pArr[p].post_id]);
            }
        }
        tpvm.trigger("Partner_fromObjArray");
        return ret;
    };

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

    get connectedElements() {
        const sPath = '[data-tp-partner="' + this.post_id + '"]'
        return document.querySelectorAll(sPath);
    }

    get visibility() {
        return this._visible;
    }

    static setElementVisibility(elt, visibility)
    {
        elt.style.display = !!visibility ? "" : "none";
    }

    toggleHighlighted(hl)
    {
        this.highlighted = !!hl;

        if (this.highlighted) {
            if (this.mapMarker !== null &&
                this.mapMarker.getAnimation() !== google.maps.Animation.BOUNCE &&
                tpvm.partners.length > 1) {
                this.mapMarker.setAnimation(google.maps.Animation.BOUNCE)
            }
        } else {
            if (this.mapMarker !== null &&
                this.mapMarker.getAnimation() !== null) {
                this.mapMarker.setAnimation(null)
            }
        }
    }

    toggleVisibility(vis = null) {
        if (vis === null) {
            this._visible = !this._visible
        } else {
            this._visible = !!vis;
        }

        for (const ei in this.connectedElements) {
            if (!this.connectedElements.hasOwnProperty(ei)) continue;

            TP_Partner.setElementVisibility(this.connectedElements[ei], this._visible);
        }

        if (this.mapMarker === null)
            return this._visible;

        let shouldBeVisible = false;

        for (const ii in this.mapMarker.partners) {
            if (!this.mapMarker.partners.hasOwnProperty(ii)) continue;

            if (this.mapMarker.partners[ii].visibility) {
                shouldBeVisible = true;

                TP_Partner.updateMarkerLabels(this.mapMarker);
            }
        }
        this.mapMarker.setVisible(shouldBeVisible);

        return this._visible;
    }

    static init() {
        tpvm.trigger('Partner_class_loaded');
    }

    static initMap(mapDivId) {
        const bounds = new google.maps.LatLngBounds();

        let mapOptions = {
            zoom: 0,
            mapTypeId: google.maps.MapTypeId.ROADMAP,
            center: {lat: 0, lng: 0},
            bounds: bounds,
            maxZoom: 12,
            streetViewControl: false,
            fullscreenControl: false,
            disableDefaultUI: true
        };
        const m = new google.maps.Map(document.getElementById(mapDivId), mapOptions);

        for (const sgi in tpvm.partners) {
            if (!tpvm.partners.hasOwnProperty(sgi)) continue;

            // skip partners that aren't locatable.
            if (tpvm.partners[sgi].geo === null || tpvm.partners[sgi].geo.lat === null) continue;

            let mkr,
                geoStr = "" + tpvm.partners[sgi].geo.lat + "," + tpvm.partners[sgi].geo.lng;

            if (TP_Partner.mapMarkers.hasOwnProperty(geoStr)) {
                mkr = TP_Partner.mapMarkers[geoStr];
            } else {
                mkr = new google.maps.Marker({
                    position: tpvm.partners[sgi].geo,
                    map: m,
                });
                mkr.partners = [];
                bounds.extend(tpvm.partners[sgi].geo); // only needed for a new marker.

                TP_Partner.mapMarkers[geoStr] = mkr;
            }
            mkr.partners.push(tpvm.partners[sgi]);

            tpvm.partners[sgi].mapMarker = mkr;
            TP_Partner.updateMarkerLabels(mkr);
        }

        // Prevent zoom from being too close initially.
        google.maps.event.addListener(m, 'zoom_changed', function() {
            // noinspection JSUnusedLocalSymbols  Symbol is used by event handler.
            let zoomChangeBoundsListener = google.maps.event.addListener(m, 'bounds_changed', function(event) {
                if (this.getZoom() > 12 && this.initialZoom === true) {
                    this.setZoom(12);
                    this.initialZoom = false;
                }
                google.maps.event.removeListener(zoomChangeBoundsListener);
            });
        });
        m.initialZoom = true;
        m.fitBounds(bounds);
    }

    static updateMarkerLabels(mkr) {
        if (mkr === null) {
            return;
        }
        let names = []
        for (const ii in mkr.partners) {
            let i = mkr.partners[ii];
            if (!!i._visible) {
                names.push(i.name);
            }
        }
        mkr.setTitle(tpvm._utils.stringArrayToListString(names))
    }

}
TP_Partner.prototype.classShort = "gp";
TP_Partner.init();