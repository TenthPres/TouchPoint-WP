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

class TP_Involvement {
    name = "";
    invId = "";
    _visible = true;
    invType = "involvement"; // overwritten by constructor

    attributes = {};

    mapMarker = null;
    geo = {};

    static currentFilters = {};

    static actions = ['join', 'contact'];

    static mapMarkers = {};

    constructor(obj) {
        this.name = obj.name;
        this.invId = obj.invId;
        this.invType = obj.invType;

        this.attributes = obj.attributes ?? null;

        for (const ei in this.connectedElements) {
            if (!this.connectedElements.hasOwnProperty(ei)) continue;

            let inv = this;
            this.connectedElements[ei].addEventListener('mouseenter', function(e){e.stopPropagation(); inv.toggleHighlighted(true);});
            this.connectedElements[ei].addEventListener('mouseleave', function(e){e.stopPropagation(); inv.toggleHighlighted(false);});

            let actionBtns = this.connectedElements[ei].querySelectorAll('[data-tp-action]')
            for (const ai in actionBtns) {
                if (!actionBtns.hasOwnProperty(ai)) continue;
                const action = actionBtns[ai].getAttribute('data-tp-action');
                if (TP_Involvement.actions.includes(action)) {
                    tpvm._utils.registerAction(action, inv, inv.invId)
                    actionBtns[ai].addEventListener('click', function (e) {
                        e.stopPropagation();
                        inv[action + "Action"]();
                    });
                }
            }
        }

        this.geo = obj.geo ?? null;

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

    get connectedElements() {
        const sPath = '[data-tp-involvement="' + this.invId + '"]'
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
                tpvm.involvements.length > 1) {
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

            TP_Involvement.setElementVisibility(this.connectedElements[ei], this._visible);
        }

        if (this.mapMarker === null)
            return this._visible;

        let shouldBeVisible = false;

        for (const ii in this.mapMarker.involvements) {
            if (!this.mapMarker.involvements.hasOwnProperty(ii)) continue;

            if (this.mapMarker.involvements[ii].visibility) {
                shouldBeVisible = true;

                TP_Involvement.updateMarkerLabels(this.mapMarker);
            }
        }
        this.mapMarker.setVisible(shouldBeVisible);

        return this._visible;
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

            if (TP_Involvement.mapMarkers.hasOwnProperty(geoStr)) {
                mkr = TP_Involvement.mapMarkers[geoStr];
            } else {
                mkr = new google.maps.Marker({
                    position: tpvm.involvements[sgi].geo,
                    map: m,
                });
                mkr.involvements = [];
                bounds.extend(tpvm.involvements[sgi].geo); // only needed for a new marker.

                TP_Involvement.mapMarkers[geoStr] = mkr;
            }
            mkr.involvements.push(tpvm.involvements[sgi]);

            tpvm.involvements[sgi].mapMarker = mkr;
            TP_Involvement.updateMarkerLabels(mkr);
        }

        // Prevent zoom from being too close initially.
        google.maps.event.addListener(m, 'zoom_changed', function() {
            // noinspection JSUnusedLocalSymbols  Symbol is used by event handler.
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

    static updateMarkerLabels(mkr) {
        if (mkr === null) {
            return;
        }
        let names = []
        for (const ii in mkr.involvements) {
            let i = mkr.involvements[ii];
            if (!!i._visible) {
                names.push(i.name);
            }
        }
        mkr.setTitle(tpvm._utils.stringArrayToListString(names))
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