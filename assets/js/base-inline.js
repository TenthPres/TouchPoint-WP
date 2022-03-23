const tpvm = {
    involvements: [],
    partners: [],
    meetings: [],
    people: {},
    _actions: {}, // collection of actions that have been registered that can be linked via location.hash.
    _utils: {},
    _vmContext: {},
    _events: {},
    _eventsTriggered: [],
    _invNear: {},
    _plausibleUsers: [],
    addEventListener: function(name, f) {
        if (typeof this._events[name] === "undefined")
            this._events[name] = [];
        this._events[name].push(f);
    },
    addOrTriggerEventListener: function(name, f) {
        if (this._eventsTriggered.hasOwnProperty(name)) {
            if (this._eventsTriggered[name] === null) {
                f();
            } else {
                f(this._eventsTriggered[name]);
            }
        } else {
            this.addEventListener(name, f);
        }
    },
    trigger: function(name, arg1 = null) {
        console.log("Firing " + name); // TODO remove.  For debugging only.
        for (const ei in this._events[name]) {
            if (!this._events[name].hasOwnProperty(ei)) continue;

            if (arg1 === null) {
                this._events[name][ei]();
            } else {
                this._events[name][ei](arg1);
            }
        }
        this._eventsTriggered[name] = arg1;
    },
    postData: async function(action = '', data = {}) {
        const response = await fetch('/touchpoint-api/' + action, {
            method: 'POST',
            mode: 'same-origin',
            cache: 'no-cache',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: JSON.stringify(data) // body data type must match "Content-Type" header
        });
        return await response.json()
    },
    getData: async function(action = '', data = {}) {
        let params = [];
        Object.keys(data).map(key => params.push(`${encodeURIComponent(key)}=${encodeURIComponent(data[key])}`));
        const response = await fetch('/touchpoint-api/' + action + '?' + params.join("&"), {
            method: 'GET',
            mode: 'same-origin',
        });
        return response.json();
    }
}
window.addEventListener('load', () => tpvm.trigger('load'))