const tpvm = {
    involvements: [],
    _events: {},
    people: {},
    _plausibleUsers: [],
    addEventListener: function(name, f) {
        if (typeof this._events[name] === "undefined")
            this._events[name] = [];
        this._events[name].push(f);
    },
    trigger: function(name) {
        for (const ei in this._events[name]) {
            if (!this._events[name].hasOwnProperty(ei)) continue;

            this._events[name][ei]();
        }
    }
}