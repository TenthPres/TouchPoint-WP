"use strict";

let TouchPointWP = new Proxy({
    PLUGIN_PATH: "/TouchPoint-WP/"
}, {
    get(target, key, that) {
        if (target.hasOwnProperty(key))
            return target[key];

        if (key === "RSVP")
            target.RSVP = new _TouchPointWP_RSVP(target, that);

        return target[key];
    }
});