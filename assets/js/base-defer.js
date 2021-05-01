"use strict";

class TP_DataGeo {
    static loc = {
        "lat": null,
        "lng": null,
        "type": null,
        "human": "Loading..." // i18n
    };

    static init() {
        tpvm.trigger('dataGeo_loaded');
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

    static getLocation(then, error) {
        // TODO cache... if it's worth it?

        if (navigator.geolocation && navigator.permissions) {
            navigator.permissions.query({name: 'geolocation'}).then(function(PermissionStatus) {
                TP_DataGeo.loc.permission = PermissionStatus.state;
                if (PermissionStatus.state === 'granted') {
                    TP_DataGeo.geoByNavigator(then, error);
                } else {
                    TP_DataGeo.geoByServer(then, error);
                }
            })
        } else {
            TP_DataGeo.geoByServer(then, error);
        }
    }

    static geoByServer(then, error) {
        tpvm.getData('tp_geolocate').then(function (responseData) {
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
TP_DataGeo.init();

class TP_Involvement {

    name = "";
    invId = "";
    #visible = true;

    attributes = {};

    constructor(obj) {
        this.name = obj.name;
        this.invId = obj.invId;

        this.attributes = obj.attributes ?? null;

        tpvm.involvements[this.invId] = this;
    }

    get connectedElements() {
        const sPath = '[data-tp-involvement="' + this.invId + '"]'
        return document.querySelectorAll(sPath);
    }

    get visibility() {
        return this.#visible;
    }

    // TODO potentially move to a helper of some kind.
    static setElementVisibility(elt, visibility)
    {
        elt.style.display = !!visibility ? "" : "none";
    }

    toggleHighlighted(hl)
    {
        this.highlighted = !!hl;
    }

    toggleVisibility(vis = null) {
        if (vis === null) {
            this.#visible = !this.#visible
        } else {
            this.#visible = !!vis;
        }

        for (const ei in this.connectedElements) {
            if (!this.connectedElements.hasOwnProperty(ei)) continue;

            TP_Involvement.setElementVisibility(this.connectedElements[ei], this.#visible);
        }

        return this.#visible;
    }

    async doJoin(people, showConfirm = true) {
        let group = this;
        showConfirm = !!showConfirm;
        return tpvm.postData('tp_inv_add', {invId: group.invId, people: people}).then((res) => {
            console.log(res);
            if (showConfirm) {
                Swal.fire({
                    icon: 'success',
                    title: `Added to ${group.name}.`,
                    timer: 3000
                });
            }
        });
    }
}

class TP_Person {
    peopleId;

    constructor(peopleId) {
        peopleId = Number(peopleId);
        this.peopleId = peopleId;
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

    static mergePeopleArrays(a, b) {
        return [...new Set([...a, ...b])]
    }

    /**
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

    static async DoInformalAuth() {

        return new Promise(function (resolve, reject) {

            if (tpvm._plausibleUsers.length > 0) {
                resolve(tpvm._plausibleUsers);
            } else {
                Swal.fire({
                    html: '<form id="tp_ident_form">' +
                        '<div class="form-group"><label for="tp_ident_email">Email Address</label><input type="email" name="email" id="tp_ident_email" required /></div>' +
                        '<div class="form-group"><label for="tp_ident_zip">Zip Code</label><input type="text" name="zip" id="tp_ident_zip" pattern="[0-9]{5}" maxlength="5" required /></div>' +
                        '</form>',
                    showConfirmButton: true,
                    showCancelButton: true,
                    confirmButtonText: 'Next',
                    focusConfirm: false,
                    preConfirm: () => {
                        let form = document.getElementById('tp_ident_form'),
                            inputs = form.querySelectorAll("input"),
                            data = {};
                        form.checkValidity()
                        for (const ii in inputs) {
                            if (!inputs.hasOwnProperty(ii)) continue;
                            if (!inputs[ii].reportValidity()) {
                                return false;
                            }
                            data[inputs[ii].name.replace("tp_ident_", "")] = inputs[ii].value;
                        }

                        Swal.showLoading();

                        return tpvm.postData('tp_ident', data)
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        if (result.value.length === 0) {
                            // TODO form for new person in TP
                        } else {
                            let p = TP_Person.fromObjArray(result.value);

                            tpvm._plausibleUsers = TP_Person.mergePeopleArrays(tpvm._plausibleUsers, p);

                            resolve(tpvm._plausibleUsers);
                        }
                    }
                });
            }
        });
    }
}