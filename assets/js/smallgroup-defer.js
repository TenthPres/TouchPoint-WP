"use strict";

class TP_SmallGroup extends TP_Involvement {
    static mapMarkers = {};

    mapMarker = null;
    geo = {};

    constructor(obj) {
        super(obj);

        this.invType = "smallgroup";

        this.geo = obj.geo ?? null;
    }

    toggleHighlighted(hl) {
        super.toggleHighlighted(hl);

        if (this.highlighted) {
            if (this.mapMarker !== null &&
                this.mapMarker.getAnimation() !== google.maps.Animation.BOUNCE &&
                TP_SmallGroup.involvements.length > 1) {
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
        super.toggleVisibility(vis);

        if (this.mapMarker === null)
            return;

        let shouldBeVisible = false;

        for (const ii in this.mapMarker.involvements) {
            if (!this.mapMarker.involvements.hasOwnProperty(ii)) continue;

            if (this.mapMarker.involvements[ii].visibility) {
                shouldBeVisible = true;
                // TODO update marker labels to reflect which of the multiple are visible.
            }
        }
        this.mapMarker.setVisible(shouldBeVisible);
    }

    static init() {
        tpvm.trigger('SmallGroup_class_loaded');
    }

    static initMap(mapDivId) {
        const bounds = new google.maps.LatLngBounds();

        let mapOptions = {
            zoom: 0,
            mapTypeId: google.maps.MapTypeId.ROADMAP,
            center: {lat: 0, lng: 0},
            bounds: bounds,
            maxZoom: 15,
            streetViewControl: false,
            fullscreenControl: false,
            disableDefaultUI: true
        };
        const m = new google.maps.Map(document.getElementById(mapDivId), mapOptions);

        for (const sgi in tpvm.involvements) {
            if (!tpvm.involvements.hasOwnProperty(sgi)) continue;

            // skip small groups that aren't locatable.
            if (tpvm.involvements[sgi].geo === null || tpvm.involvements[sgi].geo.lat === null) continue;

            let mkr,
                geoStr = "" + tpvm.involvements[sgi].geo.lat + "," + tpvm.involvements[sgi].geo.lng;

            if (TP_SmallGroup.mapMarkers.hasOwnProperty(geoStr)) {
                mkr = TP_SmallGroup.mapMarkers[geoStr];
                mkr.setTitle("Multiple Groups"); // i18n
            } else {
                mkr = new google.maps.Marker({
                    position: tpvm.involvements[sgi].geo,
                    title: tpvm.involvements[sgi].name,
                    map: m,
                });
                mkr.involvements = [];
                bounds.extend(tpvm.involvements[sgi].geo); // only needed for a new marker.

                TP_SmallGroup.mapMarkers[geoStr] = mkr;
            }
            mkr.involvements.push(tpvm.involvements[sgi]);

            tpvm.involvements[sgi].mapMarker = mkr;
        }
        // Prevent zoom from being too close initially.
        google.maps.event.addListener(m, 'zoom_changed', function() {
            let zoomChangeBoundsListener = google.maps.event.addListener(m, 'bounds_changed', function(event) {
                if (this.getZoom() > 13 && this.initialZoom === true) {
                    this.setZoom(13);
                    this.initialZoom = false;
                }
                google.maps.event.removeListener(zoomChangeBoundsListener);
            });
        });
        m.initialZoom = true;
        m.fitBounds(bounds);
    }

    static initNearby(targetId, count) {
        if (window.location.pathname.substring(0, 10) === "/wp-admin/")
            return;

        let target = document.getElementById(targetId);
        tpvm._sg.nearby = ko.observableArray([]);
        ko.applyBindings(tpvm._sg, target);

        TP_DataGeo.getLocation(getNearbyGroups, console.error);

        function getNearbyGroups() {
            tpvm.getData('sg/nearby', {
                lat: TP_DataGeo.loc.lat, // TODO reduce double-requesting
                lng: TP_DataGeo.loc.lng,
                limit: count,
            }).then(handleGroupsLoaded);
        }

        function handleGroupsLoaded(response) {
            tpvm._sg.nearby(response);
        }
    }
}

TP_SmallGroup.init();