const tpvm = {
    involvements: [],
    _events: {},
    people: {},
    _sg: {},
    _plausibleUsers: [],
    addEventListener: function(name, f) {
        if (typeof this._events[name] === "undefined")
            this._events[name] = [];
        this._events[name].push(f);
    },
    trigger: function(name, arg1 = null) {
        for (const ei in this._events[name]) {
            if (!this._events[name].hasOwnProperty(ei)) continue;

            if (arg1 === null) {
                this._events[name][ei]();
            } else {
                this._events[name][ei](arg1);
            }
        }
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
        Object.keys(data).map(
            function(key, inx) {
                params.push(`${encodeURIComponent(key)}=${encodeURIComponent(data[key])}`);}
        );
        const response = await fetch('/touchpoint-api/' + action + '?' + params.join("&"), {
            method: 'GET',
            mode: 'same-origin',
        });
        return response.json();
    }
}