<?php
namespace tp\TouchPointWP;

/** @var Settings $this */

$invTypes = json_encode($this->parent->getInvolvementTypes());
$grouping = json_encode(Meeting_GroupingSettings::toObject());
echo "<script type=\"text/javascript\">tpvm._vmContext = tpvm._vmContext ?? {}; tpvm._vmContext.mtgGrouping = {invTypes: $invTypes, settings: $grouping }</script>";
?>
<style>
    #tp-mtg-grouping table.widefat { max-width: 60em; margin: 1em 0; }
    #tp-mtg-grouping th.tp-mg-opt, #tp-mtg-grouping td.tp-mg-opt { text-align: center; width: 8em; }
    #tp-mtg-grouping td.tp-mg-remove { width: 5em; text-align: right; }
    #tp-mtg-grouping tr.tp-mg-other td { font-style: italic; }
    #tp-mtg-grouping details { max-width: 60em; margin: .5em 0; }
    #tp-mtg-grouping details summary { cursor: pointer; font-weight: 600; }
    #tp-mtg-grouping details > div { padding: 0 1.5em; }
</style>
<div id="tp-mtg-grouping" style="display:none;" data-bind="visible: true">
    <p><?php _e("This is an advanced feature to adjust how groups of meetings are imported and displayed.  If you're just getting started with TouchPoint-WP, hold off on this until you get the hang of the default behaviors.", "TouchPoint-WP"); ?></p>
    <p><?php _e("Choose how meetings are grouped together on your site, based on each involvement's Involvement Type in TouchPoint.  These settings apply to every post type that imports meetings.", "TouchPoint-WP"); ?></p>
    <p><?php _e("An involvement uses the settings for its own Involvement Type, unless it is included in its parent's structure (because the parent's settings include child involvements).  Then, the parent's settings apply.", "TouchPoint-WP"); ?></p>

    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php _e("Involvement Type", "TouchPoint-WP"); ?></th>
                <th scope="col" class="tp-mg-opt"><a href="#tp-mg-help-children"><?php _e("Include Child Involvements", "TouchPoint-WP"); ?></a></th>
                <th scope="col" class="tp-mg-opt"><a href="#tp-mg-help-editions"><?php _e("Editions", "TouchPoint-WP"); ?></a></th>
                <th scope="col" class="tp-mg-opt"><a href="#tp-mg-help-timeSlots"><?php _e("Time Slots", "TouchPoint-WP"); ?></a></th>
                <th scope="col" class="tp-mg-opt"><a href="#tp-mg-help-clusters"><?php _e("Clusters", "TouchPoint-WP"); ?></a></th>
                <th scope="col" class="tp-mg-remove"><span class="screen-reader-text"><?php _e("Remove", "TouchPoint-WP"); ?></span></th>
            </tr>
        </thead>
        <tbody>
            <!-- ko foreach: rows -->
            <tr>
                <th scope="row" data-bind="text: $root.typeName(invTypeId)"></th>
                <td class="tp-mg-opt"><input type="checkbox" data-bind="checked: includeChildren, attr: {'aria-label': $root.optionLabel($data, 'children')}" /></td>
                <td class="tp-mg-opt"><input type="checkbox" data-bind="checked: editions, attr: {'aria-label': $root.optionLabel($data, 'editions')}" /></td>
                <td class="tp-mg-opt"><input type="checkbox" data-bind="checked: timeSlots, enable: timeSlotsAllowed, attr: {'aria-label': $root.optionLabel($data, 'timeSlots')}" /></td>
                <td class="tp-mg-opt"><input type="checkbox" data-bind="checked: clusters, attr: {'aria-label': $root.optionLabel($data, 'clusters')}" /></td>
                <td class="tp-mg-remove"><a href="#" class="button" data-bind="click: $root.removeRow"><?php _e("Remove", "TouchPoint-WP"); ?></a></td>
            </tr>
            <!-- /ko -->
            <tr class="tp-mg-other" data-bind="with: otherTypes">
                <th scope="row"><?php _e("All other Involvement Types", "TouchPoint-WP"); ?></th>
                <!-- ko ifnot: legacy -->
                <td class="tp-mg-opt"><input type="checkbox" data-bind="checked: includeChildren, attr: {'aria-label': $root.optionLabel($data, 'children')}" /></td>
                <td class="tp-mg-opt"><input type="checkbox" data-bind="checked: editions, attr: {'aria-label': $root.optionLabel($data, 'editions')}" /></td>
                <td class="tp-mg-opt"><input type="checkbox" data-bind="checked: timeSlots, enable: timeSlotsAllowed, attr: {'aria-label': $root.optionLabel($data, 'timeSlots')}" /></td>
                <td class="tp-mg-opt"><input type="checkbox" data-bind="checked: clusters, attr: {'aria-label': $root.optionLabel($data, 'clusters')}" /></td>
                <!-- /ko -->
                <!-- ko if: legacy -->
                <td colspan="4"><?php _e("Previous behavior: meetings less than 23 hours apart are collected.", "TouchPoint-WP"); ?></td>
                <!-- /ko -->
                <td class="tp-mg-remove"></td>
            </tr>
        </tbody>
    </table>

    <p data-bind="visible: invTypes.length > 0">
        <label for="tp-mg-add"><?php _e("Set grouping for an Involvement Type:", "TouchPoint-WP"); ?></label>
        <select id="tp-mg-add" data-bind="options: availableTypes, optionsText: 'description', optionsValue: 'id', value: typeToAdd, optionsCaption: '<?php echo esc_js(__("Select...", "TouchPoint-WP")); ?>'"></select>
        <button type="button" class="button" data-bind="click: addRow, enable: typeToAdd"><?php _e("Add", "TouchPoint-WP"); ?></button>
    </p>
    <p data-bind="visible: invTypes.length < 1"><?php _e("No Involvement Types could be loaded from TouchPoint, so only the settings for all Involvement Types can be changed.", "TouchPoint-WP"); ?></p>

    <p data-bind="visible: legacyAvailable">
        <input id="tp-mg-legacy" type="checkbox" data-bind="checked: otherTypes.legacy" />
        <label for="tp-mg-legacy"><?php _e("Use the previous behavior for all other Involvement Types", "TouchPoint-WP"); ?></label>
        (<a href="#tp-mg-help-legacy"><?php _e("What is this?", "TouchPoint-WP"); ?></a>)
    </p>

    <p>
        <input id="tp-mg-skipScheduled" type="checkbox" data-bind="checked: skipScheduled" />
        <label for="tp-mg-skipScheduled"><?php _e("Never group involvements that have a weekly schedule (recommended)", "TouchPoint-WP"); ?></label>
        <br />
        <span class="description"><?php _e("Classes and small groups that meet on a schedule keep each meeting on its own page, whatever their Involvement Type, and are never included in a parent's structure.", "TouchPoint-WP"); ?></span>
    </p>

    <h3><?php _e("How each option works", "TouchPoint-WP"); ?></h3>
    <p><?php _e("Options can be combined.  Any level that would contain only one item is skipped, so an Edition with only one meeting is just that meeting's page.", "TouchPoint-WP"); ?></p>

    <details id="tp-mg-help-children">
        <summary><?php _e("Include Child Involvements", "TouchPoint-WP"); ?></summary>
        <div>
            <p><?php _e("Meetings of child and grandchild involvements (that have \"Show in Sites\" checked) are shown within this involvement, instead of within their own involvements.  The child involvements are not listed separately, but the Register and RSVP buttons on their meetings still use the child involvement.  This is useful when parts of an event have their own registration or RSVP.", "TouchPoint-WP"); ?></p>
            <p><?php _e("If this is not checked, child involvements are listed on their own, as usual.", "TouchPoint-WP"); ?></p>
        </div>
    </details>

    <details id="tp-mg-help-editions">
        <summary><?php _e("Editions", "TouchPoint-WP"); ?></summary>
        <div>
            <p><?php _e("Each occurrence of an event that happens again and again in the same involvement becomes an Edition, such as this year's conference and last year's.  A new Edition starts whenever there are more than 25 days between the end of one meeting and the start of the next.  Past Editions stay on your site with their original descriptions.", "TouchPoint-WP"); ?></p>
        </div>
    </details>

    <details id="tp-mg-help-timeSlots">
        <summary><?php _e("Time Slots", "TouchPoint-WP"); ?></summary>
        <div>
            <p><?php _e("Meetings of sibling child involvements that happen at the same time are grouped into a Time Slot, which is titled with its date and time.  For example, breakout sessions from several tracks, each of which is its own child involvement.  Requires Include Child Involvements.", "TouchPoint-WP"); ?></p>
        </div>
    </details>

    <details id="tp-mg-help-clusters">
        <summary><?php _e("Clusters", "TouchPoint-WP"); ?></summary>
        <div>
            <p><?php _e("Meetings of the same involvement are grouped into a Cluster:", "TouchPoint-WP"); ?></p>
            <ul style="list-style: disc; margin-left: 1.5em;">
                <li><?php _e("Back-to-back meetings form a Cluster: no more than 2 hours apart, with no other meeting between them.  For example, a memorial service and its reception.  Meetings on different days are never grouped this way.", "TouchPoint-WP"); ?></li>
                <li><?php _e("Within an Edition, all of a child involvement's meetings form one Cluster, even if they're on different days.  For example, two performances of the same concert.", "TouchPoint-WP"); ?></li>
            </ul>
        </div>
    </details>

    <details id="tp-mg-help-legacy" data-bind="visible: legacyAvailable">
        <summary><?php _e("Previous behavior", "TouchPoint-WP"); ?></summary>
        <div>
            <p><?php _e("Meetings of the same involvement that start less than 23 hours apart are collected together, as in earlier versions of TouchPoint-WP.  Each post type keeps the \"Collect Meetings for Larger Events\" setting it had before.  This option is available because your site used it before upgrading, so that nothing changes until you choose.", "TouchPoint-WP"); ?></p>
        </div>
    </details>
</div>
<script type="text/javascript">

    function MtgGroupingRow(data, isOther) {
        let self = this;
        this.invTypeId = isOther ? null : Number(data.invTypeId);
        this.includeChildren = ko.observable(!!data.includeChildren);
        this.editions = ko.observable(!!data.editions);
        this.timeSlots = ko.observable(!!data.timeSlots);
        this.clusters = ko.observable(!!data.clusters);
        this.legacy = ko.observable(isOther && !!data.legacy);

        // Time Slots are made from sibling involvements, so they need child involvements to be included.
        this.timeSlotsAllowed = ko.pureComputed(() => self.includeChildren());
        this.includeChildren.subscribe(function(v) {
            if (!v) {
                self.timeSlots(false);
            }
        });

        this.toJS = function() {
            let o = {
                includeChildren: self.includeChildren(),
                editions: self.editions(),
                timeSlots: self.timeSlots(),
                clusters: self.clusters()
            };
            if (isOther) {
                o.legacy = self.legacy();
            } else {
                o.invTypeId = self.invTypeId;
            }
            return o;
        };
    }

    function MtgGroupingVM(context) {
        let self = this,
            s = context.settings;

        self.invTypes = context.invTypes ?? [];
        self.legacyAvailable = !!s.legacyAvailable;
        self.rows = ko.observableArray((s.types ?? []).map((t) => new MtgGroupingRow(t, false)));
        self.otherTypes = new MtgGroupingRow(s.otherTypes ?? {}, true);
        self.skipScheduled = ko.observable(s.skipScheduled ?? true);
        self.typeToAdd = ko.observable(undefined);

        self.typeName = function(id) {
            let t = self.invTypes.find((it) => Number(it.id) === id);
            // Translators: %s is the ID number of an Involvement Type that no longer exists in TouchPoint.
            return t ? t.description : "<?php echo esc_js(__("(Involvement Type %s)", "TouchPoint-WP")); ?>".replace("%s", id);
        };

        let optionNames = {
            children: "<?php echo esc_js(__("Include Child Involvements", "TouchPoint-WP")); ?>",
            editions: "<?php echo esc_js(__("Editions", "TouchPoint-WP")); ?>",
            timeSlots: "<?php echo esc_js(__("Time Slots", "TouchPoint-WP")); ?>",
            clusters: "<?php echo esc_js(__("Clusters", "TouchPoint-WP")); ?>"
        };
        self.optionLabel = function(row, option) {
            let typeName = row.invTypeId === null ? "<?php echo esc_js(__("All other Involvement Types", "TouchPoint-WP")); ?>" : self.typeName(row.invTypeId);
            return typeName + ": " + optionNames[option];
        };

        self.availableTypes = ko.pureComputed(function() {
            let used = self.rows().map((r) => r.invTypeId);
            return self.invTypes.filter((it) => !used.includes(Number(it.id)));
        });

        // Operations
        self.addRow = function() {
            if (self.typeToAdd() === undefined) {
                return;
            }
            self.rows.push(new MtgGroupingRow({invTypeId: self.typeToAdd()}, false));
            self.typeToAdd(undefined);
        };
        self.removeRow = function(row) { self.rows.remove(row) };

        self.toJSON = function() {
            return JSON.stringify({
                types: self.rows().map((r) => r.toJS()),
                otherTypes: self.otherTypes.toJS(),
                skipScheduled: self.skipScheduled()
            });
        };
    }

    function initMtgGroupingVm() {
        let formElt = document.getElementById('mc_grouping_json'),
            container = document.getElementById('tp-mtg-grouping'),
            vm = new MtgGroupingVM(tpvm._vmContext.mtgGrouping);

        tpvm._vmContext.mtgGroupingVM = vm;
        ko.applyBindings(vm, container);

        // Links to an explanation open it.
        container.querySelectorAll('a[href^="#tp-mg-help-"]').forEach(function(a) {
            a.addEventListener('click', function() {
                let d = document.getElementById(a.getAttribute('href').substring(1));
                if (d) {
                    d.open = true;
                }
            });
        });

        formElt.value = vm.toJSON();
        ko.computed(() => vm.toJSON()).subscribe(function(json) {
            formElt.value = json;
        });
    }

    tpvm.addOrTriggerEventListener('load', () => initMtgGroupingVm())

</script>
