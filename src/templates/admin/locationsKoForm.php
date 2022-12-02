<?php
namespace tp\TouchPointWP;

/** @var TouchPointWP_Settings $this */

?>
<form>
<div data-bind="foreach: locations, visible: locations().length > 0" style="display:none;">
    <div data-bind="click: toggleVisibility">
        <span style="padding: .1em .5em 0 0" class="dashicons dashicons dashicons-arrow-down" data-bind="visible: _visible" aria-hidden="true"></span>
        <span style="padding: .1em .5em 0 0" class="dashicons dashicons dashicons-arrow-up" data-bind="hidden: _visible" aria-hidden="true"></span>
        <span style="font-size: 1.5em; padding-right:2em; text-decoration: none;" data-bind="text: name"></span>
        <a href="#" class="button" data-bind="click: $parent.removeLocation"><?php _e("Delete", "TouchPoint-WP"); ?></a>
    </div>

    <table data-bind="visible: _visible" style="margin-left: 2.4em;">
        <tr>
            <th>
                <label for="location-name" data-bind="attr: { for: 'location-' + $index() + '-name'}"><?php _e("Location Name", "TouchPoint-WP"); ?></label>
            </th>
            <td colspan="2">
                <input id="location-name" type="text" data-bind="value: name, attr: { id: 'location-' + $index() + '-name'}, valueUpdate: ['afterkeydown', 'input']" />
            </td>
        </tr>
        <tr>
            <th>
                <label for="location-lat" data-bind="attr: { for: 'location-' + $index() + '-lat'}"><?php _e("Latitude", "TouchPoint-WP"); ?></label>
            </th>
            <td colspan="2">
                <input id="location-lat" type="text" data-bind="value: lat, attr: { id: 'location-' + $index() + '-lat'}" />
            </td>
        </tr>
        <tr>
            <th>
                <label for="location-lng" data-bind="attr: { for: 'location-' + $index() + '-lng'}"><?php _e("Longitude", "TouchPoint-WP"); ?></label>
            </th>
            <td colspan="2">
                <input id="location-lng" type="text" data-bind="value: lng, attr: { id: 'location-' + $index() + '-lng'}" />
            </td>
        </tr>
        <tr>
            <th>
                <label for="location-ips" data-bind="attr: { for: 'location-' + $index() + '-ips'}"><?php _e("Static IP Addresses", "TouchPoint-WP"); ?></label>
            </th>
            <td colspan="2">
                <p><?php _e("If this Location has an internet connection with Static IP Addresses, you can put those addresses here so users are automatically identified with this location.", 'TouchPoint-WP'); ?></p>
                <table data-bind="foreach: ipAddresses, visible: ipAddresses().length > 0" style="display:none;">
                    <tr>
                        <td data-bind="text: $rawData"></td>
                        <td><a href="#" class="button" data-bind="click: $parent.removeIp"><?php _e("Delete", "TouchPoint-WP"); ?></a></td>
                    </tr>
                </table>
                <input id="location-ips" type="text" data-bind="value: _newIp, attr: { id: 'location-' + $index() + '-ips'}" />
                <button type="submit" class="button" data-bind="click: addIp"><?php _e("Add IP Address", "TouchPoint-WP"); ?></button>
            </td>
        </tr>

    </table>

    <hr />

</div>

<button type="submit" class="button" data-bind="click: addLocation"><?php _e("Add Location", "TouchPoint-WP"); ?></button>

</form>
<script type="text/javascript">

    function Location(data) {
        let self = this;
        this.name = ko.observable(data.name ?? "<?php _e("The Campus", "TouchPoint-WP"); ?>");
        this.lat = ko.observable(data.lat ?? "");
        this.lng = ko.observable(data.lng ?? "");
        this.ipAddresses = ko.observableArray(data.ipAddresses ?? []);

        this._visible = ko.observable(false);
        this._newIp = ko.observable("");

        // operations
        this.toggleVisibility = function() {
            self._visible(! self._visible())
        };

        this.addIp = function() {
            if (self.ipAddresses().indexOf(self._newIp()) !== -1)
                return;
            self.ipAddresses.push(self._newIp());
            self._newIp("");
        };
        this.removeIp = function(ip) { self.ipAddresses.remove(ip) };
    }

    function LocationsVM(locationData) {
        // Data
        let self = this,
            locationInits = [];
        for (const ii in locationData) {
            locationInits.push(new Location(locationData[ii]))
        }
        self.locations = ko.observableArray(locationInits);

        // Operations
        self.addLocation = function() {
            let newL = new Location({})
            newL._visible = true;
            self.locations.push(newL);
        };
        self.removeLocation = function(type) { self.locations.remove(type) };
    }

    function initLocationVM() {
        // noinspection JSUnusedLocalSymbols
        ko.extenders.slug = function(target, option) {
            let result = ko.pureComputed({
                read: target,
                write: function(newValue) {
                    let current = target();
                    newValue = newValue.toLowerCase().replaceAll(/([^a-z0-9]+)+/g, '-')

                    //only write if it changed
                    if (newValue !== current) {
                        target(newValue);
                    }
                }
            }).extend({ notify: 'always' });

            //initialize
            result(target());

            return result;
        }

        let formElt = document.getElementById('locations_json'),
            locationData = JSON.parse(formElt.innerText);
        tpvm._vmContext.locationsVM = new LocationsVM(locationData)
        ko.options.deferUpdates = true;

        ko.applyBindings(tpvm._vmContext.locationsVM);

        tpvm._vmContext.locationsVM.locations.toJSON = function() {
            let copy = ko.toJS(tpvm._vmContext.locationsVM.locations);
            for (const ii in copy) {
                for (const attr in copy[ii]) {
                    if (attr[0] === '_') {
                        delete copy[ii][attr];
                    }
                }
            }
            return JSON.stringify(copy);
        }

        ko.computed(function() {
            return ko.toJSON(tpvm._vmContext.locationsVM);
        }).subscribe(function() {
            formElt.innerText = tpvm._vmContext.locationsVM.locations.toJSON()
        });
    }

    tpvm.addOrTriggerEventListener('load', () => initLocationVM())

</script>
