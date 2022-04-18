"use strict";

function utilInit() {
    tpvm._utils.stringArrayToListString = function(strings) {
        let concat = strings.join(''),
            comma = ', ',
            and = ' & ',
            useOxford = false,
            last, str;
        if (concat.indexOf(', ') !== -1) {
            comma     = '; ';
            useOxford = true;
        }
        if (concat.indexOf(' & ') !== -1) {
            and = ' and '; // i18n
            useOxford = true;
        }

        last = strings.pop();
        str = strings.join(comma);
        if (strings.length > 0) {
            if (useOxford)
                str += comma.trim();
            str += and;
        }
        str += last;
        return str;
    }

    tpvm._utils.registerAction = function(action, object, id) {
        let itemUId = object.classShort + id;
        if (!tpvm._actions.hasOwnProperty(action)) {
            tpvm._actions[action] = {};
        }
        if (typeof object[action + "Action"] === "function") {
            tpvm._actions[action][itemUId] = object[action + "Action"];
        }
    }

    tpvm._utils.defaultSwalClasses = function() {
        return {
            container: 'tp-swal-container'
        }
    }

    tpvm._utils.arrayAdd = function (a, b) {
        if (a.length !== b.length) {
            console.error("Array lengths do not match.");
            return;
        }
        for (const ai in a) {
            a[ai] += b[ai];
        }
        return a;
    }

    tpvm._utils.averageColor = function (arr) {
        let components = [0, 0, 0, 0],
            useAlpha = false,
            denominator = 0;
        for (const ai in arr) {
            arr[ai] = arr[ai].replace(';', '').trim();
            if (typeof arr[ai] === "string" && arr[ai][0] === '#' && arr[ai].length === 4) { // #abc
                components = tpvm._utils.arrayAdd(components, [
                    parseInt(arr[ai][1] + arr[ai][1], 16),
                    parseInt(arr[ai][2] + arr[ai][2], 16),
                    parseInt(arr[ai][3] + arr[ai][3], 16),
                    255]);
                denominator++;
            } else if (typeof arr[ai] === "string" && arr[ai][0] === '#' && arr[ai].length === 5) { // #abcd
                components = tpvm._utils.arrayAdd(components, [
                    parseInt(arr[ai][1] + arr[ai][1], 16),
                    parseInt(arr[ai][2] + arr[ai][2], 16),
                    parseInt(arr[ai][3] + arr[ai][3], 16),
                    parseInt(arr[ai][4] + arr[ai][4], 16)]);
                useAlpha = true;
                denominator++;
            } else if (typeof arr[ai] === "string" && arr[ai][0] === '#' && arr[ai].length === 7) { // #aabbcc
                components = tpvm._utils.arrayAdd(components, [
                    parseInt(arr[ai][1] + arr[ai][2], 16),
                    parseInt(arr[ai][3] + arr[ai][4], 16),
                    parseInt(arr[ai][5] + arr[ai][6], 16),
                    255]);
                denominator++;
            } else if (typeof arr[ai] === "string" && arr[ai][0] === '#' && arr[ai].length === 9) { // #aabbccdd
                components = tpvm._utils.arrayAdd(components, [
                    parseInt(arr[ai][1] + arr[ai][2], 16),
                    parseInt(arr[ai][3] + arr[ai][4], 16),
                    parseInt(arr[ai][5] + arr[ai][6], 16),
                    parseInt(arr[ai][7] + arr[ai][8], 16)]);
                useAlpha = true;
                denominator++;
            } else {
                console.error("Can't calculate the color for " + arr[ai]);
            }
        }
        if (!useAlpha) {
            components.pop();
        }
        for (const ci in components) {
            components[ci] = Math.round(components[ci] / denominator).toString(16); // convert to hex
            components[ci] = ("00" + components[ci]).slice(-2); // pad, just in case there's only one digit.
        }
        return "#" + components.join('');
    }
}
utilInit();

class TP_DataGeo {
    static loc = {
        "lat": null,
        "lng": null,
        "type": null,
        "human": "Loading..." // i18n
    };

    static init() {
        tpvm.trigger('dataGeo_class_loaded');
    }

    static geoByNavigator(then = null, error = null) {
        navigator.geolocation.getCurrentPosition(geo, err);

        function geo(pos) {
            TP_DataGeo.loc = {
                "lat": pos.coords.latitude,
                "lng": pos.coords.longitude,
                "type": "nav",
                "permission": null,
                "human": "Your Location" // i18n
            }

            if (then !== null) {
                then(TP_DataGeo.loc)
            }

            tpvm.trigger("dataGeo_located", TP_DataGeo.loc)
        }

        function err(e) {
            let userFacingMessage = "";

            if (error !== null) {
                error(e)
            }

            console.error(e);

            switch(e.code) {
                case e.PERMISSION_DENIED:
                    userFacingMessage = "User denied the request for Geolocation." // i18n
                    break;
                case e.POSITION_UNAVAILABLE:
                    userFacingMessage = "Location information is unavailable."  // i18n
                    break;
                case e.TIMEOUT:
                    userFacingMessage = "The request to get user location timed out."  // i18n
                    break;
                case e.UNKNOWN_ERROR:
                    userFacingMessage = "An unknown error occurred."  // i18n
                    break;
            }

            tpvm.trigger("dataGeo_error", userFacingMessage)
        }
    }

    /**
     * Get the user's location.
     *
     * @param then function Callback for when the location is available.
     * @param error function Callback for an error. (Error data structure may vary.)
     * @param type string Type of fetching to use. "nav", "ip" or "both"
     */
    static getLocation(then, error, type = "both") {
        if (type === "both") {
            type = ["nav", "ip"];
        } else {
            type = [type];
        }

        // if location is already known and of an acceptable type
        if (TP_DataGeo.loc.lat !== null && type.indexOf(TP_DataGeo.loc.type) > -1) {
            then(TP_DataGeo.loc);
        }

        // navigator is preferred if available and allowed.
        if (navigator.geolocation && navigator.permissions && type.indexOf("nav") > -1) {
            navigator.permissions.query({name: 'geolocation'}).then(function(PermissionStatus) {
                TP_DataGeo.loc.permission = PermissionStatus.state;
                if (PermissionStatus.state === 'granted') {
                    return TP_DataGeo.geoByNavigator(then, error);
                }
            })
        }

        // Fallback to Server
        if (type.indexOf("ip") > -1) {
            return TP_DataGeo.geoByServer(then, error);
        }

        error({error: true, message: "No geolocation option available"});
    }

    static geoByServer(then, error) {
        tpvm.getData('geolocate').then(function (responseData) {
            if (responseData.hasOwnProperty("error")) {
                error(responseData.error)
                tpvm.trigger("dataGeo_error", responseData.error)
            } else {
                for (const di in responseData) {
                    if (responseData.hasOwnProperty(di))
                        TP_DataGeo.loc[di] = responseData[di];
                }

                then(TP_DataGeo.loc);
                tpvm.trigger("dataGeo_located", TP_DataGeo.loc)
            }
        }, error);
    }

    static errorMessageHtml() {
        if (TP_DataGeo.loc.type === 'nav') {
            return "You appear to be quite far away or using a mobile connection on a device without a GPS."; // i18n
        } else {
            if (navigator.geolocation) {
                return "You appear to be either quite far away or using a mobile connection.<br /><a href=\"javascript:TP_DataGeo.geoByNavigator();\" onclick=\"ga('send', 'event', 'sgf', 'permission', 'Device Location');\">Click here to use your actual location.</a>"; // i18n
            } else {
                return "You appear to be either quite far away or using a mobile connection.<br />Your browser doesn't support geolocation so we can't find a small group near you."; // i18n
            }
        }
    }
}
TP_DataGeo.prototype.classShort = "geo";
TP_DataGeo.init();

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

    updateLabel(highlighted = false) {
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
        if (highlighted) {
            super.setLabel(null); // Remove label if highlighted, because labels don't animate.
        } else {
            super.setLabel(this.getLabelContent());
        }
    }

    getLabelContent() {
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
        return label;
    }

    handleClick() {
        const mp = this.getMap();
        TP_MapMarker.smoothZoom(mp, this.getPosition())
    }

    /**
     * Smoothly zoom in (or out) on the given map.  By default, zooms in to the max level allowed.
     *
     * @param {google.maps.Map} map The Google Maps map
     * @param {google.maps.LatLng} position The position to move to center
     * @param {number, undefined} zoomTo Google Maps zoom level, or undefined for maxZoom.
     */
    static async smoothZoom(map, position = null, zoomTo = undefined) {
        if (zoomTo === undefined || zoomTo > map.maxZoom) {
            zoomTo = map.maxZoom;
        }

        if (map.getZoom() !== zoomTo) {
            let z = google.maps.event.addListener(map, 'zoom_changed', () => {
                google.maps.event.removeListener(z);
                setTimeout(() => this.smoothZoom(map, position, zoomTo), 150);
            });
            if (map.getZoom() < zoomTo) { // zoom in
                map.setZoom(map.getZoom() + 1);
            } else { // zoom out
                map.setZoom(map.getZoom() - 1);
            }
            if (position !== null) {
                let oldPos = map.getCenter(),
                    newPos = new google.maps.LatLng((oldPos.lat() + position.lat() * 2) / 3, (oldPos.lng() + position.lng() * 2) / 3);
                map.panTo(newPos);
            }
        } else {
            map.panTo(position);
        }
    }
}

class TP_Mappable {
    name = "";
    post_id = 0;

    geo = {};

    color = "#000";

    static items = [];
    static itemsWithoutMarkers = [];

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
        if (obj.geo !== undefined && obj.geo !== null && obj.geo.lat !== null && obj.geo.lng !== null) {
            obj.geo.lat = Math.round(obj.geo.lat * 1000) / 1000;
            obj.geo.lng = Math.round(obj.geo.lng * 1000) / 1000;
        }

        this.geo = [obj.geo] ?? [];

        this.name = obj.name.replace("&amp;", "&");
        this.post_id = obj.post_id;

        if (obj.post_id === undefined) {
            this.post_id = 0;
        }

        if (obj.hasOwnProperty('color')) {
            this.color = obj.color;
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
        const map = new google.maps.Map(containerElt, mapOptions),
            bounds = new google.maps.LatLngBounds();

        for (const ii in list) {
            if (!list.hasOwnProperty(ii)) continue;

            // skip items that aren't locatable.
            let hasMarkers = false;
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
                        animation: google.maps.Animation.DROP,
                    });
                    mkr.geoStr = geoStr;

                    // Add to collection of all markers
                    this.markers.push(mkr);
                }

                bounds.extend(mkr.getPosition());

                // If the marker doesn't already have a reference to this item, add one.
                if (!mkr.items.includes(item)) {
                    mkr.items.push(item);
                }

                // If the item doesn't already have a reference to this marker, add one.
                if (!item.markers.includes(mkr)) {
                    item.markers.push(mkr);
                }

                hasMarkers = true;

                mkr.updateLabel();
            }
            if (!hasMarkers) {
                this.itemsWithoutMarkers.push(this);
            }
        }

        map.fitBounds(bounds);

        map.addListener('bounds_changed', this.handleZoom);
    }

    /**
     * Currently, this will apply visibility to ALL mappable items, even if they're on a different map.
     */
    static handleZoom() {
        for (const ii in TP_Mappable.items) {
            if (TP_Mappable.items.length > 1) { // Don't hide details on Single pages
                TP_Mappable.items[ii].applyVisibilityToConnectedElements();
            }
        }
    }

    updateMarkerLabels() {
        for (const mi in this.markers) {
            this.markers[mi].updateLabel();
        }
    }

    showOnMapAction() {
        // One marker (probably typical)
        if (this.markers.length === 1) {
            let mp = this.markers[0].getMap(),
                el = mp.getDiv(),
                rect = el.getBoundingClientRect(),
                viewHeight = Math.max(document.documentElement.clientHeight, window.innerHeight),
                mpWithinView = !(rect.bottom < 0 || rect.top - viewHeight >= 0);
            TP_MapMarker.smoothZoom(mp, this.markers[0].getPosition());
            if (!mpWithinView) {
                window.scroll({
                    top: rect.top,
                    left: rect.left,
                    behavior: 'smooth'
                })
            }
            return;
        }

        // No Markers
        if (this.markers.length === 0) {
            console.warn("\"Show on Map\" was called on a Mappable item that doesn't have markers.")
            return;
        }

        // More than one marker
        console.warn("\"Show on Map\" for Mappable items with multiple markers is not fully supported.")
        // Hide all non-matching markers.  There isn't really a way to get them back, but that's why this isn't fully supported.
        for (const mi in TP_Mappable.markers) {
            for (const ii in TP_Mappable.markers[mi].items) {
                TP_Mappable.markers[mi].items[ii].toggleVisibility(TP_Mappable.markers[mi].items[ii] === this);
            }
        }
    }

    get visible() {
        return this._visible && (this.markers.some((m) => m.visible) || this.markers.length === 0);
    }

    get inBounds() {
        return this.markers.some((m) => m.inBounds);
    }

    static get mapExcludesSomeMarkers() {
        return this.markers.some((m) => !m.inBounds);
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
            elts[ei].style.display = (this.visible && (this.inBounds || !TP_Mappable.mapExcludesSomeMarkers)) ? "" : "none";
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
                if (TP_Mappable.items.length > 1) {
                    if (mk.getAnimation() !== google.maps.Animation.BOUNCE) {
                        mk.setAnimation(google.maps.Animation.BOUNCE);
                    }
                }
                mk.updateLabel(this.highlighted)
            }
        } else {
            for (const mi in this.markers) {
                let mk = this.markers[mi];
                if (mk.getAnimation() !== null) {
                    mk.setAnimation(null)
                }
                mk.updateLabel(this.highlighted)
            }
        }
    }
}

class TP_Involvement extends TP_Mappable {
    invId = "";
    invType = "involvement"; // overwritten by constructor

    attributes = {};

    static currentFilters = {};

    static actions = ['join', 'contact'];

    static mapMarkers = {};

    constructor(obj) {
        super(obj);

        this.invId = obj.invId;
        this.invType = obj.invType;

        this.attributes = obj.attributes ?? null;

        tpvm.involvements[this.invId] = this;
    }

    // noinspection JSUnusedGlobalSymbols  Used via dynamic instantiation.
    static fromObjArray(invArr) {
        let ret = [];
        for (const i in invArr) {
            if (!invArr.hasOwnProperty(i)) continue;

            if (typeof invArr[i].invId === "undefined") {
                continue;
            }

            if (typeof tpvm.involvements[invArr[i].invId] === "undefined") {
                ret.push(new this(invArr[i]));
            } else {
                ret.push(tpvm.involvements[invArr[i].invId]);
            }
        }
        tpvm.trigger("Involvement_fromObjArray");
        return ret;
    };

    static initFilters() {
        const filtOptions = document.querySelectorAll("[data-involvement-filter]");
        for (const ei in filtOptions) {
            if (!filtOptions.hasOwnProperty(ei)) continue;
            filtOptions[ei].addEventListener('change', this.applyFilters.bind(this, "Involvement"))
        }
    }

    static applyFilters(invType, ev = null) {
        if (ev !== null) {
            let attr = ev.target.getAttribute("data-involvement-filter"),
                val = ev.target.value;
            if (attr !== null) {
                if (val === "") {
                    delete this.currentFilters[attr];
                } else {
                    this.currentFilters[attr] = val;
                }
            }
        }

        groupLoop:
            for (const ii in tpvm.involvements) {
                if (!tpvm.involvements.hasOwnProperty(ii)) continue;
                const group = tpvm.involvements[ii];
                for (const ai in this.currentFilters) {
                    if (!this.currentFilters.hasOwnProperty(ai)) continue;

                    if (!group.attributes.hasOwnProperty(ai) ||
                        group.attributes[ai] === null ||
                        (   !Array.isArray(group.attributes[ai]) &&
                            group.attributes[ai].slug !== this.currentFilters[ai] &&
                            group.attributes[ai] !== this.currentFilters[ai]
                        ) || (
                            Array.isArray(group.attributes[ai]) &&
                            group.attributes[ai].find(a => a.slug === this.currentFilters[ai]) === undefined
                        )
                    ) {
                        group.toggleVisibility(false)
                        continue groupLoop;
                    }
                }
                group.toggleVisibility(true)
            }
    }

    static init() {
        tpvm.trigger('Involvement_class_loaded');
    }

    async doJoin(people, showConfirm = true) {
        let inv = this;
        showConfirm = !!showConfirm;

        if (typeof ga === "function") {
            ga('send', 'event', inv.invType, 'join complete', inv.name);
        }

        let res = await tpvm.postData('inv/join', {invId: inv.invId, people: people, invType: inv.invType});
        if (res.success.length > 0) {
            if (showConfirm) {
                Swal.fire({
                    icon: 'success',
                    title: `Added to ${inv.name}`,
                    timer: 3000,
                    customClass: tpvm._utils.defaultSwalClasses()
                });
            }
        } else {
            console.error(res);
            if (showConfirm) {
                Swal.fire({
                    icon: 'error',
                    title: `Something strange happened.`,
                    timer: 3000,
                    customClass: tpvm._utils.defaultSwalClasses()
                });
            }
        }
    }

    async doInvContact(fromPerson, message, showConfirm = true) {
        let inv = this;
        showConfirm = !!showConfirm;

        if (typeof ga === "function") {
            ga('send', 'event', inv.invType, 'contact complete', inv.name);
        }

        let res = await tpvm.postData('inv/contact', {invId: inv.invId, fromPerson: fromPerson, message: message, invType: inv.invType});
        if (res.success.length > 0) {
            if (showConfirm) {
                Swal.fire({
                    icon: 'success',
                    title: `Your message has been sent.`,
                    timer: 3000,
                    customClass: tpvm._utils.defaultSwalClasses()
                });
            }
        } else {
            console.error(res);
            if (showConfirm) {
                Swal.fire({
                    icon: 'error',
                    title: `Something strange happened.`,
                    timer: 3000,
                    customClass: tpvm._utils.defaultSwalClasses()
                });
            }
        }
    }

    // noinspection JSUnusedGlobalSymbols  Used dynamically from btns.
    joinAction() {
        let inv = this,
            title = `Join ${inv.name}`;

        if (typeof ga === "function") {
            ga('send', 'event', inv.invType, 'join btn click', inv.name);
        }

        TP_Person.DoInformalAuth(title).then(
            (res) => joinUi(inv, res),
            () => console.log("Informal auth failed, probably user cancellation.")
        )

        function joinUi(inv, people) {
            if (typeof ga === "function") {
                ga('send', 'event', inv.invType, 'join userIdentified', inv.name);
            }

            Swal.fire({
                title: title,
                html: "<p id=\"swal-tp-text\">Who is joining the group?</p>" + TP_Person.peopleArrayToCheckboxes(people),
                customClass: tpvm._utils.defaultSwalClasses(),
                showConfirmButton: true,
                showCancelButton: true,
                confirmButtonText: 'Join',
                focusConfirm: false,
                preConfirm: () => {
                    let form = document.getElementById('tp_people_list_checkboxes'),
                        inputs = form.querySelectorAll("input"),
                        data = [];
                    for (const ii in inputs) {
                        if (!inputs.hasOwnProperty(ii) || !inputs[ii].checked) continue;
                        data.push(tpvm.people[inputs[ii].value]);
                    }

                    if (data.length < 1) {
                        let prompt = document.getElementById('swal-tp-text');
                        prompt.innerText = "Select who should be added to the group.";
                        prompt.classList.add('error')
                        return false;
                    }

                    Swal.showLoading();

                    return inv.doJoin(data, true);
                }
            });
        }
    }

    contactAction() {
        let inv = this,
            title = `<span class="no-wrap">Contact the leaders</span> of <span class="no-wrap">${inv.name}</span>`;

        if (typeof ga === "function") {
            ga('send', 'event', inv.invType, 'contact btn click', inv.name);
        }

        TP_Person.DoInformalAuth(title).then((res) => contactUi(inv, res), () => console.log("Informal auth failed, probably user cancellation."))

        function contactUi(inv, people) {
            if (typeof ga === "function") {
                ga('send', 'event', inv.invType, 'contact userIdentified', inv.name);
            }

            Swal.fire({
                title: title,
                html: '<form id="tp_inv_contact_form">' +
                    '<div class="form-group"><label for="tp_inv_contact_fromPid">From</label>' + TP_Person.peopleArrayToSelect(people, "tp_inv_contact_fromPid", "fromPid") + '</div>' +
                    '<div class="form-group"><label for="tp_inv_contact_body">Message</label><textarea name="body" id="tp_inv_contact_body"></textarea></div>' +
                    '</form>',
                customClass: tpvm._utils.defaultSwalClasses(),
                showConfirmButton: true,
                showCancelButton: true,
                confirmButtonText: 'Send',
                focusConfirm: false,
                preConfirm: () => {
                    let form = document.getElementById('tp_inv_contact_form'),
                        fromPerson = tpvm.people[parseInt(form.getElementsByTagName('select')[0].value)],
                        message = form.getElementsByTagName('textarea')[0].value;

                    if (message.length < 5) {
                        let prompt = document.getElementById('swal-tp-text');
                        prompt.innerText = "Please provide a message.";
                        prompt.classList.add('error')
                        return false;
                    }

                    Swal.showLoading();

                    return inv.doInvContact(fromPerson, message, true);
                }
            });
        }
    }

    static initMap(mapDivId) {
        let mapOptions = {
            mapTypeId: google.maps.MapTypeId.ROADMAP,
            linksControl: false,
            maxZoom: 15,
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
                        {visibility: 'on'}
                    ]
                },
                {
                    featureType: "transit",
                    stylers: [
                        {visibility: 'on'}
                    ]
                }
            ],
            zoom: 15,
            center: {lat: 0, lng: 0}, // gets overwritten by bounds later.
            streetViewControl: false,
            fullscreenControl: false,
            disableDefaultUI: true
        };

        super.initMap(document.getElementById(mapDivId), mapOptions, tpvm.involvements)
    }

    static initNearby(targetId, type, count) {
        if (window.location.pathname.substring(0, 10) === "/wp-admin/")
            return;

        let target = document.getElementById(targetId);
        tpvm._invNear.nearby = ko.observableArray([]);
        ko.applyBindings(tpvm._invNear, target);

        TP_DataGeo.getLocation(getNearbyGroups, console.error);

        function getNearbyGroups() {
            tpvm.getData('inv/nearby', {
                lat: TP_DataGeo.loc.lat, // TODO reduce double-requesting
                lng: TP_DataGeo.loc.lng,
                type: type,
                limit: count,
            }).then(handleGroupsLoaded);
        }

        function handleGroupsLoaded(response) {
            tpvm._invNear.nearby(response);
        }
    }
}
TP_Involvement.prototype.classShort = "i";
TP_Involvement.init();

class TP_Person {
    peopleId;
    displayName;

    static actions = ['join', 'contact'];

    constructor(peopleId) {
        peopleId = Number(peopleId);
        this.peopleId = peopleId;

        for (const ei in this.connectedElements) {
            if (!this.connectedElements.hasOwnProperty(ei)) continue;

            let psn = this;

            let actionBtns = this.connectedElements[ei].querySelectorAll('[data-tp-action]')
            for (const ai in actionBtns) {
                if (!actionBtns.hasOwnProperty(ai)) continue;
                const action = actionBtns[ai].getAttribute('data-tp-action');
                if (TP_Person.actions.includes(action)) {
                    tpvm._utils.registerAction(action, psn, psn.peopleId)
                    actionBtns[ai].addEventListener('click', function (e) {
                        e.stopPropagation();
                        psn[action + "Action"]();
                    });
                }
            }
        }

        tpvm.people[peopleId] = this;
    }

    static fromObj(obj) {
        let person;
        if (tpvm.people[obj.peopleId] !== undefined) {
            person = tpvm.people[obj.peopleId]
        } else {
            person = new TP_Person(obj.peopleId);
        }
        for (const a in obj) {
            if (!obj.hasOwnProperty(a) || a === 'peopleId') continue;

            person[a] = obj[a];
        }
        return person;
    }

    static fromObjArray(peopleArray) {
        let ret = [];

        for (const pi in peopleArray) {
            if (!peopleArray.hasOwnProperty(pi)) continue;
            ret.push(TP_Person.fromObj(peopleArray[pi]));
        }

        return ret;
    }

    static init() {
        tpvm.trigger('Person_class_loaded');
    }

    get connectedElements() {
        const sPath = '[data-tp-person="' + this.peopleId + '"]'
        return document.querySelectorAll(sPath);
    }

    static mergePeopleArrays(a, b) {
        return [...new Set([...a, ...b])]
    }

    /**
     * Take an array of person-like objects and make a list of checkboxes out of them.  These are NOT TP_People objects. TODO they should be.
     *
     * @param array TP_Person[]
     */
    static peopleArrayToCheckboxes(array) {
        let out = "<form id=\"tp_people_list_checkboxes\"><table class=\"tp-checkbox-list\"><tbody>"

        for (const pi in array) {
            if (!array.hasOwnProperty(pi)) continue;
            let p = array[pi];

            out += '<tr><td><input type="checkbox" name="people[]" id="tp_people_list_checks_' + p.peopleId + '" value="' + p.peopleId + '" required /></td>'
            out += '<td><label for="tp_people_list_checks_' + p.peopleId + '">' + p.goesBy + ' ' + p.lastName + '</label></td></tr>'
        }

        return out + "</tbody></table></form>"
    }

    /**
     * Take an array of person-like objects and make a list of checkboxes out of them.  These are NOT TP_People objects. TODO they should be.
     *
     * @param array TP_Person[]
     * @param options string[]
     * @param defaultPosition int - the Nth position in the options array should be selected by default.
     */
    static peopleArrayToRadio(array, options, defaultPosition = -1) {
        let out = "<form id=\"tp_people_list_radio\"><table class=\"tp-radio-list\"><tbody>"

        // headers
        out += "<tr>";
        for (const oi in options) {
            if (!options.hasOwnProperty(oi)) continue;
            out += `<th>${options[oi]}</th>`
        }
        out += `<th colspan="2"></th></tr>`;

        // people
        for (const pi in array) {
            if (!array.hasOwnProperty(pi)) continue;
            let p = array[pi];

            out += '<tr>'
            for (const oi in options) {
                if (!options.hasOwnProperty(oi)) continue;
                let selected = (parseInt(oi, 10) === defaultPosition ? "selected" : "")
                out += `<td><input type="radio" name="${p.peopleId}" id="tp_people_list_checks_${p.peopleId}_${options[oi]}" value="${options[oi]}" ${selected} /></td>`
            }
            out += `<td><a href="#" class="swal-tp-clear-item" onclick="TP_Person.clearRadio('${p.peopleId}'); return false;">clear</a></td>`
            out += `<td style="text-align:left; width:50%;">${p.goesBy} ${p.lastName}</td></tr>`
        }

        return out + "</tbody></table></form>"
    }

    static clearRadio(name) {
        let elts = document.getElementsByName(name);
        for (const ei in elts) {
            if (!elts.hasOwnProperty(ei)) continue;
            elts[ei].checked = false;
        }
    }

    contactAction() {
        let psn = this,
            title = `Contact ${psn.displayName}`;

        if (typeof ga === "function") {
            ga('send', 'event', 'Person', 'contact btn click', psn.peopleId);
        }

        TP_Person.DoInformalAuth(title).then((res) => contactUi(psn, res), () => console.log("Informal auth failed, probably user cancellation."))

        function contactUi(psn, people) {
            if (typeof ga === "function") {
                ga('send', 'event', 'Person', 'contact userIdentified', psn.peopleId);
            }

            Swal.fire({
                title: title,
                html: '<form id="tp_person_contact_form">' +
                    '<div class="form-group"><label for="tp_person_contact_fromPid">From</label>' + TP_Person.peopleArrayToSelect(people, "tp_person_contact_fromPid", "fromPid") + '</div>' +
                    '<div class="form-group"><label for="tp_person_contact_body">Message</label><textarea name="body" id="tp_person_contact_body"></textarea></div>' +
                    '</form>',
                customClass: tpvm._utils.defaultSwalClasses(),
                showConfirmButton: true,
                showCancelButton: true,
                confirmButtonText: 'Send',
                focusConfirm: false,
                preConfirm: () => {
                    let form = document.getElementById('tp_person_contact_form'),
                        fromPerson = tpvm.people[parseInt(form.getElementsByTagName('select')[0].value)],
                        message = form.getElementsByTagName('textarea')[0].value;

                    if (message.length < 5) {
                        let prompt = document.getElementById('swal-tp-text');
                        prompt.innerText = "Please provide a message.";
                        prompt.classList.add('error')
                        return false;
                    }

                    Swal.showLoading();

                    return psn.doPersonContact(fromPerson, message, true);
                }
            });
        }
    }

    async doPersonContact(fromPerson, message, showConfirm = true) {
        let psn = this;
        showConfirm = !!showConfirm;

        if (typeof ga === "function") {
            ga('send', 'event', 'Person', 'contact complete', psn.peopleId);
        }

        let res = await tpvm.postData('person/contact', {toId: psn.peopleId, fromPerson: fromPerson, message: message});
        if (res.success.length > 0) {
            if (showConfirm) {
                Swal.fire({
                    icon: 'success',
                    title: `Your message has been sent.`,
                    timer: 3000,
                    customClass: tpvm._utils.defaultSwalClasses()
                });
            }
        } else {
            console.error(res);
            if (showConfirm) {
                Swal.fire({
                    icon: 'error',
                    title: `Something strange happened.`,
                    timer: 3000,
                    customClass: tpvm._utils.defaultSwalClasses()
                });
            }
        }
    }

    /**
     *
     * @param array TP_Person[]
     * @param id string
     * @param name string
     */
    static peopleArrayToSelect(array, id, name) {
        let out = `<select id="${id}" name="${name}">`

        for (const pi in array) {
            if (!array.hasOwnProperty(pi)) continue;
            let p = array[pi];

            out += `<option value="${p.peopleId}">${p.goesBy} ${p.lastName}</option>`;
        }

        return out + "</select>"
    }

    static async DoInformalAuth(title, forceAsk = false) {
        return new Promise(function (resolve, reject) {
            if (tpvm._plausibleUsers.length > 0 && !forceAsk) {
                resolve(tpvm._plausibleUsers);
            } else {
                Swal.fire({
                    html: `<p id=\"swal-tp-text\">Tell us about yourself.</p>` +
                        '<form id="tp_ident_form">' +
                        '<div class="form-group"><label for="tp_ident_email">Email Address</label><input type="email" name="email" id="tp_ident_email" required /></div>' +
                        '<div class="form-group"><label for="tp_ident_zip">Zip Code</label><input type="text" name="zip" id="tp_ident_zip" pattern="[0-9]{5}" maxlength="5" required /></div>' +
                        '<input type="submit" hidden style="display:none;" /></form>',
                    customClass: tpvm._utils.defaultSwalClasses(),
                    showConfirmButton: true,
                    showCancelButton: true,
                    title: title,
                    confirmButtonText: 'Next',
                    focusConfirm: false,
                    didOpen: () => {
                        document.getElementById('tp_ident_form').addEventListener('submit', (e) => {
                            Swal.clickConfirm();
                            e.preventDefault();
                        })
                    },
                    preConfirm: async () => {
                        let form = document.getElementById('tp_ident_form'),
                            inputs = form.querySelectorAll("input"),
                            data = {};
                        form.checkValidity()
                        for (const ii in inputs) {
                            if (!inputs.hasOwnProperty(ii)) continue;
                            if (!inputs[ii].reportValidity()) {
                                return false;
                            }
                            let name = inputs[ii].name.replace("tp_ident_", "");
                            if (name.length > 0) // removes entry generated by submit input
                                data[name] = inputs[ii].value;
                        }

                        Swal.showLoading();

                        let result = await tpvm.postData('person/ident', data);
                        if (result.people.length > 0) {
                            return result;
                        } else {
                            Swal.hideLoading();
                            Swal.update({
                                html: "<p>Our system doesn't recognize you,<br />so we need a little more info.</p>" +
                                    '<form id="tp_ident_form">' +
                                    '<div class="form-group"><label for="tp_ident_email">Email Address</label><input type="email" name="email" id="tp_ident_email" value="' + data.email + '" required /></div>' +
                                    '<div class="form-group"><label for="tp_ident_zip">Zip Code</label><input type="text" name="zip" id="tp_ident_zip" pattern="[0-9]{5}" maxlength="5" value="' + data.zip + '" required /></div>' +
                                    '<div class="form-group"><label for="tp_ident_first">First Name</label><input type="text" name="firstName" id="tp_ident_first" required /></div>' +
                                    '<div class="form-group"><label for="tp_ident_last">Last Name</label><input type="text" name="lastName" id="tp_ident_last" required /></div>' +
                                    // '<div class="form-group"><label for="tp_ident_dob">Birthdate</label><input type="date" name="dob" id="tp_ident_dob" /></div>' +
                                    '<div class="form-group"><label for="tp_ident_phone">Phone</label><input type="tel" name="phone" id="tp_ident_phone" /></div>' +
                                    '<input type="submit" hidden style="display:none;" /></form>'
                            });
                            document.getElementById('tp_ident_form').addEventListener('submit', (e) => {
                                Swal.clickConfirm();
                                e.preventDefault();
                            });
                            return false;
                        }
                    }
                }).then((result) => {
                    if (result.value) {
                        let p = TP_Person.fromObjArray(result.value.people);
                        tpvm._plausibleUsers = TP_Person.mergePeopleArrays(tpvm._plausibleUsers, p);
                    }

                    if (result.isDismissed) {
                        reject(false);
                    } else {
                        resolve(tpvm._plausibleUsers);
                    }
                });
            }
        });
    }
}
TP_Person.prototype.classShort = "p";
TP_Person.init();