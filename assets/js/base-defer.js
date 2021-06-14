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
    _visible = true;
    invType = "involvement"; // Can be set to something more specific like 'smallgroup'

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
        return this._visible;
    }

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
            this._visible = !this._visible
        } else {
            this._visible = !!vis;
        }

        for (const ei in this.connectedElements) {
            if (!this.connectedElements.hasOwnProperty(ei)) continue;

            TP_Involvement.setElementVisibility(this.connectedElements[ei], this._visible);
        }

        return this._visible;
    }

    async doJoin(people, showConfirm = true) {
        let inv = this;
        showConfirm = !!showConfirm;

        if (typeof ga === "function") {
            ga('send', 'event', inv.invType, 'join complete', inv.name);
        }

        let res = await tpvm.postData('tp_inv_join', {invId: inv.invId, people: people});
        if (res.success.length > 0) {
            if (showConfirm) {
                Swal.fire({
                    icon: 'success',
                    title: `Added to ${inv.name}`,
                    timer: 3000
                });
            }
        } else {
            console.error(res);
            if (showConfirm) {
                Swal.fire({
                    icon: 'error',
                    title: `Something strange happened.`,
                    timer: 3000
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

        let res = await tpvm.postData('tp_inv_contact', {invId: inv.invId, fromPerson: fromPerson, message: message});
        if (res.success.length > 0) {
            if (showConfirm) {
                Swal.fire({
                    icon: 'success',
                    title: `Your message has been sent.`,
                    timer: 3000
                });
            }
        } else {
            console.error(res);
            if (showConfirm) {
                Swal.fire({
                    icon: 'error',
                    title: `Something strange happened.`,
                    timer: 3000
                });
            }
        }
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

    static async DoInformalAuth() {
        return new Promise(function (resolve, reject) {
            if (tpvm._plausibleUsers.length > 0) {
                resolve(tpvm._plausibleUsers);
            } else {
                Swal.fire({
                    html: '<form id="tp_ident_form">' +
                        '<div class="form-group"><label for="tp_ident_email">Email Address</label><input type="email" name="email" id="tp_ident_email" required /></div>' +
                        '<div class="form-group"><label for="tp_ident_zip">Zip Code</label><input type="text" name="zip" id="tp_ident_zip" pattern="[0-9]{5}" maxlength="5" required /></div>' +
                        '<input type="submit" hidden style="display:none;" /></form>',
                    showConfirmButton: true,
                    showCancelButton: true,
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

                        let result = await tpvm.postData('tp_ident', data);
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