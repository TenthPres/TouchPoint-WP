TouchPointWP = new Proxy({}, {
    get(target, key) {
        if (target.hasOwnProperty(key))
            return target[key];

        if (key === "RSVP")
            target.RSVP = {'jawn' : true}

        return target[key];

        function loadModule(which) {

        }
    }
})