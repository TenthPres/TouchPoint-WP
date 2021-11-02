<?php
namespace tp\TouchPointWP;

$divs = json_encode($this->parent->getDivisions());
echo "<script type=\"text/javascript\">tpvm._vmContext = {divs: {$divs}}</script>";
?>
<style>
    .column-wrap {
        column-count: 1;
    }
    @media (min-width: 1300px) {
        .column-wrap {
            column-count: 2;
        }
    }
    @media (min-width: 1500px) {
        .column-wrap {
            column-count: 3;
        }
    }
</style>
<form>
<div data-bind="foreach: invTypes, visible: invTypes().length > 0" style="display:none;">
    <div data-bind="click: toggleVisibility">
        <span style="padding: .1em .5em 0 0" class="dashicons dashicons dashicons-arrow-up" data-bind="visible: _visible" aria-hidden="true"></span>
        <span style="padding: .1em .5em 0 0" class="dashicons dashicons dashicons-arrow-down" data-bind="hidden: _visible" aria-hidden="true"></span>
        <span style="font-size: 1.5em; padding-right:2em; text-decoration: none;" data-bind="text: namePlural"></span>
        <a href="#" class="button" data-bind="click: $parent.removeInvType"><?php _e("Delete", TouchPointWP::TEXT_DOMAIN); ?></a>
    </div>

    <table data-bind="visible: _visible" style="margin-left: 2.4em;">
        <tr>
            <th>
                <label for="it-singular" data-bind="attr: { for: 'it-' + slug() + '-singular'}"><?php _e("Singular Name", TouchPointWP::TEXT_DOMAIN); ?></label>
            </th>
            <td colspan="2">
                <input id="it-singular" type="text" data-bind="value: nameSingular, attr: { id: 'it-' + slug() + '-singular'}" />
            </td>
        </tr>
        <tr>
            <th>
                <label for="it-plural" data-bind="attr: { for: 'it-' + slug() + '-plural'}"><?php _e("Plural Name", TouchPointWP::TEXT_DOMAIN); ?></label>
            </th>
            <td colspan="2">
                <input id="it-plural" type="text" data-bind="value: namePlural, attr: { id: 'it-' + slug() + '-plural'}, valueUpdate: ['afterkeydown', 'input']" />
            </td>
        </tr>
        <tr>
            <th>
                <label for="it-slug" data-bind="attr: { for: 'it-' + slug() + '-slug'}"><?php _e("Slug", TouchPointWP::TEXT_DOMAIN); ?></label>
            </th>
            <td colspan="2">
                <input id="it-slug" type="text" data-bind="value: slug, attr: { id: 'it-' + slug() + '-slug'}" />
            </td>
        </tr>

        <tr>
            <th><?php _e("Divisions to Import", TouchPointWP::TEXT_DOMAIN); ?></th>
            <td colspan="2" class="column-wrap">
                <!-- ko foreach: $root.divisions -->
                <p>
                    <input id="it-div" type="checkbox" data-bind="value: id, checked: $parent.importDivs, attr: {id: 'it-' + $parent.slug() + '-div-' + id}" />
                    <label for="it-div" data-bind="text: name, attr: {for: 'it-' + $parent.slug() + '-div-' + id}"></label>
                </p>
                <!-- /ko -->
            </td>
        </tr>

        <tr>
            <th>
                <label for="it-useGeo" data-bind="attr: { for: 'it-' + slug() + '-useGeo'}"><?php _e("Use Geographic Location", TouchPointWP::TEXT_DOMAIN); ?></label>
            </th>
            <td colspan="2"><input id="it-useGeo" type="checkbox" data-bind="checked: useGeo, attr: { id: 'it-' + slug() + '-useGeo'}" /></td>
        </tr>

        <tr>
            <th><?php _e("Leader Member Types", TouchPointWP::TEXT_DOMAIN); ?></th>
            <td colspan="2">
                <!-- ko if: $data._activeMemberTypes().length < 1 -->
                <p><?php _e("loading...", TouchPointWP::TEXT_DOMAIN); ?></p>
                <!-- /ko -->
                <!-- ko foreach: $data._activeMemberTypes -->
                <p>
                    <input id="it-leader-type" type="checkbox" data-bind="value: id, checked: $parent.leaderTypes, attr: {id: 'it-' + $parent.slug() + '-leader-type-' + id}" />
                    <label for="it-leader-type" data-bind="text: description, attr: {for: 'it-' + $parent.slug() + '-leader-type-' + id}"></label>
                </p>
                <!-- /ko -->
            </td>
        </tr>
        <tr data-bind="visible: useGeo">
            <th>
                <?php _e("Host Member Types", TouchPointWP::TEXT_DOMAIN); ?>
            </th>
            <td colspan="2">
                <!-- ko if: $data._activeMemberTypes().length < 1 -->
                <p><?php _e("loading...", TouchPointWP::TEXT_DOMAIN); ?></p>
                <!-- /ko -->
                <!-- ko foreach: $data._activeMemberTypes -->
                <p>
                    <input id="it-host-type" type="checkbox" data-bind="value: id, checked: $parent.hostTypes, attr: {id: 'it-' + $parent.slug() + '-host-type-' + id}" />
                    <label for="it-host-type" data-bind="text: description, attr: {for: 'it-' + $parent.slug() + '-host-type-' + id}"></label>
                </p>
                <!-- /ko -->
            </td>
        </tr>
        <tr>
            <th>
                <?php _e("Default Filters", TouchPointWP::TEXT_DOMAIN); ?>
            </th>
            <td colspan="2">
                <p>
                    <input id="it-filt-div" type="checkbox" value="div" data-bind="checked: filters, attr: { id: 'it-' + slug() + '-filt-div'}" />
                    <label for="it-filt-div" data-bind="attr: { for: 'it-' + slug() + '-filt-div'}"><?php echo $this->get('dv_name_singular') ?></label>
                </p>
                <p>
                    <input id="it-filt-genderId" type="checkbox" value="genderId" data-bind="checked: filters, attr: { id: 'it-' + slug() + '-filt-genderId'}" />
                    <label for="it-filt-genderId" data-bind="attr: { for: 'it-' + slug() + '-filt-genderId'}"><?php _e("Gender", TouchPointWP::TEXT_DOMAIN); ?></label>
                </p>
                <p data-bind="visible: useGeo">
                    <input id="it-filt-useGeo" type="checkbox" value="rescode" data-bind="checked: filters, attr: { id: 'it-' + slug() + '-filt-useGeo'}" />
                    <label for="it-filt-useGeo" data-bind="attr: { for: 'it-' + slug() + '-filt-useGeo'}"><?php echo $this->get('rc_name_singular') ?></label>
                </p>
                <p>
                    <input id="it-filt-weekday" type="checkbox" value="weekday" data-bind="checked: filters, attr: { id: 'it-' + slug() + '-filt-weekday'}" />
                    <label for="it-filt-weekday" data-bind="attr: { for: 'it-' + slug() + '-filt-weekday'}"><?php _e("Weekday", TouchPointWP::TEXT_DOMAIN); ?></label>
                </p>
                <p>
                    <input id="it-filt-marital" type="checkbox" value="inv_marital" data-bind="checked: filters, attr: { id: 'it-' + slug() + '-filt-marital'}" />
                    <label for="it-filt-marital" data-bind="attr: { for: 'it-' + slug() + '-filt-marital'}"><?php _e("Prevailing Marital Status", TouchPointWP::TEXT_DOMAIN); ?></label>
                </p>
                <p>
                    <input id="it-filt-agegroup" type="checkbox" value="agegroup" data-bind="checked: filters, attr: { id: 'it-' + slug() + '-filt-agegroup'}" />
                    <label for="it-filt-agegroup" data-bind="attr: { for: 'it-' + slug() + '-filt-agegroup'}"><?php _e("Age Group", TouchPointWP::TEXT_DOMAIN); ?></label>
                </p>
            </td>
        </tr>
    </table>

    <hr />

</div>

<button type="submit" class="button" data-bind="click: addInvType"><?php _e("Add Involvement Type", TouchPointWP::TEXT_DOMAIN); ?></button>

</form>
<script type="text/javascript">

    function InvType(data) {
        let self = this;
        this.nameSingular = ko.observable(data.nameSingular ?? "<?php _e("Small Group", TouchPointWP::TEXT_DOMAIN); ?>");
        this.namePlural = ko.observable(data.namePlural ?? "<?php _e("Small Groups", TouchPointWP::TEXT_DOMAIN); ?>");
        this.slug = ko.observable(data.slug ?? "<?php _e("smallgroup", TouchPointWP::TEXT_DOMAIN); ?>").extend({slug: 0});
        this.importDivs = ko.observable(data.importDivs ?? []);
        this.useGeo = ko.observable(data.useGeo ?? false);
        this.leaderTypes = ko.observableArray(data.leaderTypes ?? []);
        this.hostTypes = ko.observableArray(data.hostTypes ?? []);
        this.filters = ko.observableArray(data.filters ?? ['genderId', 'weekday', 'rescode', 'agegroup', 'div']);

        this._visible = ko.observable(false);

        this._activeMemberTypes_promise = ko.observable([]);
        this._activeMemberTypes_divs = "loading"; // value doesn't really matter
        this._activeMemberTypes = ko.pureComputed({
            read: function() {
                let divs = self.importDivs().sort().join(',');
                if (divs !== self._activeMemberTypes_divs) {
                    self._activeMemberTypes_divs = divs;
                    tpvm.getData("admin/memtypes", {divs: divs}).then(self._activeMemberTypes_promise);
                }
                return self._activeMemberTypes_promise();
            }
        })

        // operations
        this.toggleVisibility = function() {
            self._visible(! self._visible())
        };

        // Initialize
        this._activeMemberTypes();
    }

    function InvTypeVM(invData) {
        // Data
        let self = this,
            invInits = [];
        for (const ii in invData) {
            invInits.push(new InvType(invData[ii]))
        }
        self.invTypes = ko.observableArray(invInits);
        self.divisions = tpvm._vmContext.divs;

        // Operations
        self.addInvType = function() {
            let newI = new InvType({})
            self.invTypes.push(newI);
        };
        self.removeInvType = function(type) { self.invTypes.remove(type) };
    }

    function initInvVm() {
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

        let formElt = document.getElementById('inv_json'),
            invData = JSON.parse(formElt.innerText);
        tpvm._vmContext.invTypesVM = new InvTypeVM(invData)
        ko.options.deferUpdates = true;

        ko.applyBindings(tpvm._vmContext.invTypesVM);

        tpvm._vmContext.invTypesVM.invTypes.toJSON = function() {
            let copy = ko.toJS(tpvm._vmContext.invTypesVM.invTypes);
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
            return ko.toJSON(tpvm._vmContext.invTypesVM);
        }).subscribe(function() {
            formElt.innerText = tpvm._vmContext.invTypesVM.invTypes.toJSON()
        });
    }

    tpvm.addOrTriggerEventListener('load', () => initInvVm())

</script>