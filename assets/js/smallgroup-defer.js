"use strict";

class TP_SmallGroup extends TP_Involvement {
    static smallGroups = [];
    static currentFilters = {};
    static mapMarkers = {};

    mapMarker = null;
    geo = {};

    constructor(obj) {
        super(obj);

        this.invType = "smallgroup";

        this.geo = obj.geo ?? null;

        TP_SmallGroup.smallGroups.push(this);

        for (const ei in this.connectedElements) {
            if (!this.connectedElements.hasOwnProperty(ei)) continue;

            let that = this;
            this.connectedElements[ei].addEventListener('mouseenter', function(e){e.stopPropagation(); that.toggleHighlighted(true);});
            this.connectedElements[ei].addEventListener('mouseleave', function(e){e.stopPropagation(); that.toggleHighlighted(false);});

            let actionBtns = this.connectedElements[ei].querySelectorAll('[data-tp-action]')
            for (const ai in actionBtns) {
                if (!actionBtns.hasOwnProperty(ai)) continue;
                const action = actionBtns[ai].getAttribute('data-tp-action');
                actionBtns[ai].addEventListener('click', function(e){e.stopPropagation(); that[action + "Action"]();});
            }
        }
    }

    toggleHighlighted(hl) {
        super.toggleHighlighted(hl);

        if (this.highlighted) {
            if (this.mapMarker !== null &&
                this.mapMarker.getAnimation() !== google.maps.Animation.BOUNCE &&
                TP_SmallGroup.smallGroups.length > 1) {
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

    joinAction() {
        let inv = this;

        if (typeof ga === "function") {
            ga('send', 'event', inv.invType, 'join btn click', inv.name);
        }

        TP_Person.DoInformalAuth().then((res) => joinUi(inv, res), () => console.log("Informal auth failed, probably user cancellation."))

        function joinUi(inv, people) {
            if (typeof ga === "function") {
                ga('send', 'event', inv.invType, 'join userIdentified', inv.name);
            }

            Swal.fire({
                html: "<p id=\"swal-tp-text\">Who is joining the group?</p>" + TP_Person.peopleArrayToCheckboxes(people),
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
        let inv = this;

        if (typeof ga === "function") {
            ga('send', 'event', inv.invType, 'contact btn click', inv.name);
        }

        TP_Person.DoInformalAuth().then((res) => contactUi(inv, res), () => console.log("Informal auth failed, probably user cancellation."))

        function contactUi(inv, people) {
            if (typeof ga === "function") {
                ga('send', 'event', inv.invType, 'contact userIdentified', inv.name);
            }

            Swal.fire({
                html: `<p id=\"swal-tp-text\">Contact the leaders of<br />${inv.name}</p>` +
                    '<form id="tp_sg_contact_form">' +
                    '<div class="form-group"><label for="tp_sg_contact_fromPid">From</label>' + TP_Person.peopleArrayToSelect(people, "tp_sg_contact_fromPid", "fromPid") + '</div>' +
                    '<div class="form-group"><label for="tp_sg_contact_body">Message</label><textarea name="body" id="tp_sg_contact_body"></textarea></div>' +
                    '</form>',
                showConfirmButton: true,
                showCancelButton: true,
                confirmButtonText: 'Send',
                focusConfirm: false,
                preConfirm: () => {
                    let form = document.getElementById('tp_sg_contact_form'),
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
        tpvm.trigger('smallgroups_loaded');
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

    static initFilters() {
        const filtOptions = document.querySelectorAll("[data-smallgroup-filter]");
        for (const ei in filtOptions) {
            if (!filtOptions.hasOwnProperty(ei)) continue;
            filtOptions[ei].addEventListener('change', TP_SmallGroup.applyFilters)
        }
    }

    static applyFilters(ev = null) {
        if (ev !== null) {
            let attr = ev.target.getAttribute("data-smallgroup-filter"),
                val = ev.target.value;
            if (attr !== null) {
                if (val === "") {
                    delete TP_SmallGroup.currentFilters[attr];
                } else {
                    TP_SmallGroup.currentFilters[attr] = val;
                }
            }
        }

        groupLoop:
        for (const ii in TP_SmallGroup.smallGroups) {
            if (!TP_SmallGroup.smallGroups.hasOwnProperty(ii)) continue;
            const group = TP_SmallGroup.smallGroups[ii];
            for (const ai in TP_SmallGroup.currentFilters) {
                if (!TP_SmallGroup.currentFilters.hasOwnProperty(ai)) continue;

                if (!group.attributes.hasOwnProperty(ai) ||
                    group.attributes[ai] === null ||
                    (!Array.isArray(group.attributes[ai]) && group.attributes[ai].slug !== TP_SmallGroup.currentFilters[ai] && group.attributes[ai] !== TP_SmallGroup.currentFilters[ai]) ||
                    (Array.isArray(group.attributes[ai]) && group.attributes[ai].find(a => a.slug === TP_SmallGroup.currentFilters[ai]) === undefined)) {

                    group.toggleVisibility(false)
                    continue groupLoop;
                }
            }
            group.toggleVisibility(true)
        }
    }

    static initNearby(targetId, count) {
        if (window.location.pathname.substring(0, 10) === "/wp-admin/")
            return;

        let target = document.getElementById(targetId);
        tpvm._sg.nearby = ko.observableArray([]);
        ko.applyBindings(tpvm._sg, target);

        TP_DataGeo.getLocation(getNearbyGroups, console.error);

        function getNearbyGroups() {
            tpvm.getData('tp_sg_nearby', {
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