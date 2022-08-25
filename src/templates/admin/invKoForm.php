<?php
namespace tp\TouchPointWP;

/** @var TouchPointWP_Settings $this */

$divs = json_encode($this->parent->getDivisions());
$kws = json_encode($this->parent->getKeywords());
/** @noinspection CommaExpressionJS */
echo "<script type=\"text/javascript\">tpvm._vmContext = {divs: $divs, kws: $kws }</script>";
?>
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
                    <input id="it-div" type="checkbox" data-bind="value: 'div' + id, checked: $parent.importDivs, attr: {id: 'it-' + $parent.slug() + '-div-' + id}" />
                    <label for="it-div" data-bind="text: name, attr: {for: 'it-' + $parent.slug() + '-div-' + id}"></label>
                </p>
                <!-- /ko -->
            </td>
        </tr>

        <tr>
            <th>
                <label for="it-hierarchical" data-bind="attr: { for: 'it-' + slug() + '-hierarchical'}"><?php _e("Import Hierarchically (Parent-Child Relationships)", TouchPointWP::TEXT_DOMAIN); ?></label>
            </th>
            <td colspan="2"><input id="it-hierarchical" type="checkbox" data-bind="checked: hierarchical, attr: { id: 'it-' + slug() + '-hierarchical'}" /></td>
        </tr>

        <tr>
            <th>
                <label for="it-useGeo" data-bind="attr: { for: 'it-' + slug() + '-useGeo'}"><?php _e("Use Geographic Location", TouchPointWP::TEXT_DOMAIN); ?></label>
            </th>
            <td colspan="2"><input id="it-useGeo" type="checkbox" data-bind="checked: useGeo, attr: { id: 'it-' + slug() + '-useGeo'}" /></td>
        </tr>

        <tr>
            <th><?php _e("Exclude Involvements if", TouchPointWP::TEXT_DOMAIN); ?></th>
            <td colspan="2">
                <p>
                    <input id="it-excludeIf-closed" type="checkbox" value="closed" data-bind="checked: excludeIf, attr: {id: 'it-' + slug() + '-excludeIf-closed'}" />
                    <label for="it-excludeIf-closed" data-bind="attr: {for: 'it-' + slug() + '-excludeIf-closed'}"><?php _e("Involvement is Closed", TouchPointWP::TEXT_DOMAIN); ?></label>
                </p>
                <p>
                    <input id="it-excludeIf-child" type="checkbox" value="child" data-bind="checked: excludeIf, attr: {id: 'it-' + slug() + '-excludeIf-child'}" />
                    <label for="it-excludeIf-child" data-bind="attr: {for: 'it-' + slug() + '-excludeIf-child'}"><?php _e("Involvement is a Child Involvement", TouchPointWP::TEXT_DOMAIN); ?></label>
                </p>
            </td>
        </tr>

        <tr>
            <th><?php _e("Leader Member Types", TouchPointWP::TEXT_DOMAIN); ?></th>
            <td colspan="2">
                <!-- ko if: $data._activeMemberTypes().length < 1 -->
                <p><?php _e("loading...", TouchPointWP::TEXT_DOMAIN); ?></p>
                <!-- /ko -->
                <!-- ko foreach: $data._activeMemberTypes -->
                <p>
                    <input id="it-leader-type" type="checkbox" data-bind="value: 'mt' + id, checked: $parent.leaderTypes, attr: {id: 'it-' + $parent.slug() + '-leader-type-' + id}" />
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
                    <input id="it-host-type" type="checkbox" data-bind="value: 'mt' + id, checked: $parent.hostTypes, attr: {id: 'it-' + $parent.slug() + '-host-type-' + id}" />
                    <label for="it-host-type" data-bind="text: description, attr: {for: 'it-' + $parent.slug() + '-host-type-' + id}"></label>
                </p>
                <!-- /ko -->
            </td>
        </tr>
        <tr data-bind="">
            <th>
                <label for="it-tense" data-bind="attr: { for: 'it-' + slug() + '-tense'}"><?php _e("Default Grouping", TouchPointWP::TEXT_DOMAIN); ?></label>
            </th>
            <td colspan="2">
                <select id="it-tense" data-bind="value: groupBy, attr: { id: 'it-' + slug() + '-tense'}">
                    <option value=""><?php _e("No Grouping", TouchPointWP::TEXT_DOMAIN); ?></option>
                    <option value="-<?php echo TouchPointWP::TAX_TENSE; ?>"><?php _e("Upcoming / Current", TouchPointWP::TEXT_DOMAIN); ?></option>
                    <option value="<?php echo TouchPointWP::TAX_TENSE; ?>"><?php _e("Current / Upcoming", TouchPointWP::TEXT_DOMAIN); ?></option>
                </select>
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
                    <input id="it-filt-timeOfDay" type="checkbox" value="timeOfDay" data-bind="checked: filters, attr: { id: 'it-' + slug() + '-filt-timeOfDay'}" />
                    <label for="it-filt-timeOfDay" data-bind="attr: { for: 'it-' + slug() + '-filt-timeOfDay'}"><?php _e("Time of Day", TouchPointWP::TEXT_DOMAIN); ?></label>
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
        <tr data-bind="">
            <th><label for="it-taskOwner" data-bind="attr: {for: 'it-' + slug() + '-taskOwner'}"><?php _e("Task Owner", TouchPointWP::TEXT_DOMAIN); ?></th>
            <td colspan="2">
                <select id="it-taskOwner" data-bind="value: taskOwner, attr: { id: 'it-' + slug() + '-taskOwner'}" class="select2">
                </select>
            </td>
        </tr>
        <tr data-bind="">
            <th><?php _e("Contact Leader Task Keywords", TouchPointWP::TEXT_DOMAIN); ?></th>
            <td colspan="2" class="column-wrap">
                <!-- ko foreach: $root.keywords -->
                <p>
                    <input id="it-clt-kw" type="checkbox" data-bind="value: 'kw' + id, checked: $parent.contactKeywords, attr: {id: 'it-' + $parent.slug() + '-clt-kw-' + id}" />
                    <label for="it-clt-kw" data-bind="text: name, attr: {for: 'it-' + $parent.slug() + '-clt-kw-' + id}"></label>
                </p>
                <!-- /ko -->
            </td>
        </tr>
        <tr data-bind="">
            <th><?php _e("Join Task Keywords", TouchPointWP::TEXT_DOMAIN); ?></th>
            <td colspan="2" class="column-wrap">
                <!-- ko foreach: $root.keywords -->
                <p>
                    <input id="it-jt-kw" type="checkbox" data-bind="value: 'kw' + id, checked: $parent.joinKeywords, attr: {id: 'it-' + $parent.slug() + '-jt-kw-' + id}" />
                    <label for="it-jt-kw" data-bind="text: name, attr: {for: 'it-' + $parent.slug() + '-jt-kw-' + id}"></label>
                </p>
                <!-- /ko -->
            </td>
        </tr>
    </table>

    <hr />

</div>

<button type="submit" class="button" data-bind="click: addInvType"><?php _e("Add Involvement Post Type", TouchPointWP::TEXT_DOMAIN); ?></button>

</form>
<script type="text/javascript">

    function InvType(data) {
        let self = this;
        this.nameSingular = ko.observable(data.nameSingular ?? "<?php _e("Small Group", TouchPointWP::TEXT_DOMAIN); ?>");
        this.namePlural = ko.observable(data.namePlural ?? "<?php _e("Small Groups", TouchPointWP::TEXT_DOMAIN); ?>");
        this.slug = ko.observable(data.slug ?? "<?php _e("smallgroup", TouchPointWP::TEXT_DOMAIN); ?>").extend({slug: 0});
        this.importDivs = ko.observable(data.importDivs ?? []);
        this.useGeo = ko.observable(data.useGeo ?? false);
        this.excludeIf = ko.observable(data.excludeIf ?? []);
        this.hierarchical = ko.observable(data.hierarchical ?? false);
        this.groupBy = ko.observable(data.groupBy ?? "");
        this.leaderTypes = ko.observableArray(data.leaderTypes ?? []);
        this.hostTypes = ko.observableArray(data.hostTypes ?? []);
        this.filters = ko.observableArray(data.filters ?? ['genderId', 'weekday', 'rescode', 'agegroup', 'div']);
        this.taskOwner = ko.observable(data.taskOwner ?? 0);
        this.contactKeywords = ko.observableArray(data.contactKeywords ?? []);
        this.joinKeywords = ko.observableArray(data.joinKeywords ?? []);

        this.postType = data.postType;

        this._visible = ko.observable(false);

        this._activeMemberTypes_promise = ko.observable([]);
        this._activeMemberTypes_divs = "loading"; // value doesn't really matter
        this._activeMemberTypes = ko.pureComputed({
            read: function() {
                let divs = self.importDivs().sort().join(',').replaceAll('div', '');
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
        self.keywords = tpvm._vmContext.kws;

        // Operations
        self.addInvType = function() {
            let newT = new InvType({})
            self.invTypes.push(newT);
            applySelect2ForData('#it-' + newT.slug() + '-taskOwner');
        };
        self.removeInvType = function(type) { self.invTypes.remove(type) };
    }

    function initInvVm() {
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

        let formElt = document.getElementById('inv_json'),
            invData = JSON.parse(formElt.innerText);
        tpvm._vmContext.invTypesVM = new InvTypeVM(invData)
        ko.options.deferUpdates = true;

        ko.applyBindings(tpvm._vmContext.invTypesVM);

        let types = tpvm._vmContext.invTypesVM.invTypes();
        for (let i in types) {
            let name = tpvm.people[invData[i].taskOwner]?.displayName ?? "(named person)";
            applySelect2ForData('#it-' + types[i].slug() + '-taskOwner', name, invData[i].taskOwner);
        }

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

    function applySelect2ForData(sel, optName = "", optId = "") {
        let item = jQuery(sel).select2({
            ajax: {
                url: "/touchpoint-api/person/src",
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        q: params.term, // search term
                        fmt: "s2"
                    };
                },
                cache: true
            },
            placeholder: 'Select...', // i18n
            minimumInputLength: 1,
        });

        if (optName !== "" && optId !== "") {
            let newOption = new Option(optName, optId, true, true);
            item.append(newOption).trigger('change');
        }
    }

    tpvm.addOrTriggerEventListener('load', () => initInvVm())

</script>
