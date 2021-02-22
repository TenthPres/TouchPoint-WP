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

class TP_User {
    static DoInformalAuth(opts = {}) { // TODO return a promise
        Swal.fire({
            html: '<form id="ident_form">' +
                '<label for="ident_email">Email Address</label><input type="email" name="email" id="ident_email" required />' +
                '<label for="ident_zip">Zip Code</label><input type="text" name="zip" id="ident_zip" pattern="[0-9]{5}" maxlength="5" required />' +
                '</form>',
            showConfirmButton: true,
            focusConfirm: false,
            preConfirm: () => {
                let form = document.getElementById('ident_form'),
                    inputs = form.querySelectorAll("input"),
                    data = {};
                form.checkValidity()
                for (const ii in inputs) {
                    if (!inputs.hasOwnProperty(ii)) continue;
                    if (!inputs[ii].reportValidity()) {
                        return false;
                    }
                    data[inputs[ii].name.replace("ident_", "")] = inputs[ii].value;
                }
                return data;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                console.log(result.value);
            }
        });
    }
}