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
    },
    postData: async function(action = '', data = {}) {
        const response = await fetch('/wp-admin/admin-ajax.php?action=' + action, {
            method: 'POST',
            mode: 'same-origin',
            cache: 'no-cache',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: JSON.stringify(data) // body data type must match "Content-Type" header
        });
        return response.json();
    }
}