"use strict";

class TP_SmallGroup extends TP_Involvement {
    static smallGroups = [];

    mapMarkers = [];
    geo = {};

    constructor(obj) {
        super(obj);

        this.geo = obj.geo ?? null;

        TP_SmallGroup.smallGroups.push(this);

        for (const ei in this.connectedElements) {
            if (!this.connectedElements.hasOwnProperty(ei)) continue;

            let that = this;
            this.connectedElements[ei].addEventListener('mouseenter', function(e){e.stopPropagation(); that.toggleHighlighted(true);});
            this.connectedElements[ei].addEventListener('mouseleave', function(e){e.stopPropagation(); that.toggleHighlighted(false);});
        }
    }

    toggleHighlighted(hl) {
        super.toggleHighlighted(hl);

        if (this.highlighted) {
            for (const mmi in this.mapMarkers) {
                if (!this.mapMarkers.hasOwnProperty(mmi)) continue;
                if (!this.mapMarkers[mmi].getAnimation() !== google.maps.Animation.BOUNCE)
                    this.mapMarkers[mmi].setAnimation(google.maps.Animation.BOUNCE);
            }
        } else {
            for (const mmi in this.mapMarkers) {
                if (!this.mapMarkers.hasOwnProperty(mmi)) continue;
                if (this.mapMarkers[mmi].getAnimation() !== null)
                    this.mapMarkers[mmi].setAnimation(null);
            }
        }
    }

    static fromArray(invArr) {
        let ret = [];
        for (const i in invArr) {
            if (!invArr.hasOwnProperty(i)) continue;

            if (typeof invArr[i].invId === "undefined") {
                continue;
            }

            if (typeof tpvm.involvements[invArr[i].invId] === "undefined") {
                ret.push(new TP_SmallGroup(invArr[i]))
            }
        }
        return ret;
    };

    static init() {
        tpvm.trigger('smallgroupsLoaded');
    }

    static doMap(mapDivId) {
        const bounds = new google.maps.LatLngBounds();

        let mapOptions = {
            zoom: 0,
            mapTypeId: google.maps.MapTypeId.ROADMAP,
            center: {lat: 0, lng: 0},
            bounds: bounds
        };
        const m = new google.maps.Map(document.getElementById(mapDivId), mapOptions);

        for (const sgi in tpvm.involvements) {
            if (!tpvm.involvements.hasOwnProperty(sgi)) continue;

            tpvm.involvements[sgi].mapMarkers.push(new google.maps.Marker({
                position: tpvm.involvements[sgi].geo,
                title: tpvm.involvements[sgi].name,
                map: m,
            }));
            bounds.extend(tpvm.involvements[sgi].geo);
        }
        m.fitBounds(bounds);
    }
}

TP_SmallGroup.init();