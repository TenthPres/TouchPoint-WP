"use strict";

class TP_Involvement {

    name = "";
    invId = "";
    #visible = true;

    constructor(obj) {
        this.name = obj.name;
        this.invId = obj.invId;

        tpvm.involvements[this.invId] = this;
    }

    get connectedElements() {
        const sPath = '[data-tp-involvement="' + this.invId + '"]'
        return document.querySelectorAll(sPath);
    }

    // TODO potentially move to a helper of some kind.
    static setElementVisibility(elt, visibility)
    {
        elt.style.display = !!visibility ? "" : "none";
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
    }

}