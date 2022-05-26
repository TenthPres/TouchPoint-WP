
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

    get shortClass() {
        return "gp";
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
                text: " ",
                fontFamily: "\"Font Awesome 6 Free\", FontAwesome",
                color: "#00000088",
                fontSize: "90%",
                className: "fa fa-solid fa-lock"
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
            filtOptions[ei].addEventListener('change', this.applyFilters.bind(this))
        }
    }

    static applyFilters(ev = null) {
        if (ev !== null) {
            let attr = ev.target.getAttribute("data-partner-filter"),
                val = ev.target.value;
            if (attr !== null) {
                if (val === "") {
                    delete this.currentFilters[attr];
                } else {
                    this.currentFilters[attr] = val;
                }
            }
        }

        itemLoop:
            for (const ii in tpvm.partners) {
                if (!tpvm.partners.hasOwnProperty(ii)) continue;
                const item = tpvm.partners[ii];
                for (const ai in this.currentFilters) {
                    if (!this.currentFilters.hasOwnProperty(ai)) continue;

                    if (!item.attributes.hasOwnProperty(ai) ||
                        item.attributes[ai] === null ||
                        (   !Array.isArray(item.attributes[ai]) &&
                            item.attributes[ai].slug !== this.currentFilters[ai] &&
                            item.attributes[ai] !== this.currentFilters[ai]
                        ) || (
                            Array.isArray(item.attributes[ai]) &&
                            item.attributes[ai].find(a => a.slug === this.currentFilters[ai]) === undefined
                        )
                    ) {
                        item.toggleVisibility(false)
                        continue itemLoop;
                    }
                }
                item.toggleVisibility(true)
            }
        TP_Mappable.updateFilterWarnings();
    }

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
            minZoom: 2,
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
            zoom: 6,
            center: {lat: 0, lng: 0}, // gets overwritten by bounds later.
            streetViewControl: false,
            fullscreenControl: false,
            disableDefaultUI: true
        };

        super.initMap(document.getElementById(mapDivId), mapOptions, tpvm.partners)
    }

}
TP_Partner.init();