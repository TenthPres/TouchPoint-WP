"use strict";

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

            async function postData(url = '', data = {}) {
                const response = await fetch(url, {
                    method: 'POST',
                    mode: 'same-origin',
                    cache: 'no-cache',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: JSON.stringify(data) // body data type must match "Content-Type" header
                });
                return response.json(); // parses JSON response into native JavaScript objects
            }

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

                        return postData('/wp-admin/admin-ajax.php?action=tp_ident', data)
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