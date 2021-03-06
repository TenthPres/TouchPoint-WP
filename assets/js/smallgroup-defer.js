"use strict";

class TP_SmallGroup extends TP_Involvement {
    static smallGroups = [];
    static currentFilters = {};

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

    toggleVisibility(vis = null) {
        super.toggleVisibility(vis);

        markerLoop:
        for (const mi in this.mapMarkers) {
            if (!this.mapMarkers.hasOwnProperty(mi)) continue;

            for (const ii in this.mapMarkers[mi].involvements) {
                if (!this.mapMarkers[mi].involvements.hasOwnProperty(ii)) continue;

                if (this.mapMarkers[mi].involvements[ii].visibility) {
                    this.mapMarkers[mi].setVisible(true);
                    // TODO update marker labels to reflect which of the multiple are visible.
                    continue markerLoop;
                }
                this.mapMarkers[mi].setVisible(false);
            }
        }
    }

    joinAction() {
        let group = this;
        TP_Person.DoInformalAuth().then((res) => joinUi(group, res), (res) => console.error(group, res))

        function joinUi(group, people) {
            // TODO if only one person, just immediately join.

            Swal.fire({
                html: "<p id=\"swal-tp-text\">Who is joining the group?</p>" + TP_Person.peopleArrayToCheckboxes(people),
                showConfirmButton: true,
                showCancelButton: true,
                confirmButtonText: 'Next',
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

                    return group.doJoin(people, true);
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
        tpvm.trigger('smallgroupsLoaded');
    }

    static initMap(mapDivId) {
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

            // skip small groups that aren't locatable.
            if (tpvm.involvements[sgi].geo === null || tpvm.involvements[sgi].geo.lat === null) continue;

            // TODO figure out shared markers (one marker representing multiple groups meeting in one place)
            let mkr = new google.maps.Marker({
                position: tpvm.involvements[sgi].geo,
                title: tpvm.involvements[sgi].name,
                map: m,
            });
            mkr.involvements = [tpvm.involvements[sgi]];

            tpvm.involvements[sgi].mapMarkers.push(mkr);
            bounds.extend(tpvm.involvements[sgi].geo);
        }
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
}

TP_SmallGroup.init();