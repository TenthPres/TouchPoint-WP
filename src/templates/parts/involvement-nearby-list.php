<div class="small-group-summary" id="<?php /** @noinspection PhpUndefinedVariableInspection */ echo $nearbyListId ?>">
    <p data-bind="html: labelStr" class="nearby-label"><?php _e('Loading...', 'TouchPoint-WP') ?></p>
    <!-- ko foreach: nearby -->
    <div class="resource-listing" style="display: none;" data-bind="visible: true">
        <h3 class="resource-title">
            <a onclick="tpvm._utils.ga('send', 'event', this.getAttribute('data-inv-type'), 'finder click', this.innerText)"
               data-bind="text: name, attr: { href: path, 'data-inv-type': invType }"></a>
        </h3>
        <p><small>
            <span data-bind="text: sprintf('<?php /* translators: number of miles */ _ex("%2.1fmi", "miles. Unit is appended to a number.  %2.1f is the number, so %2.1fmi looks like '12.3mi'", 'TouchPoint-WP'); ?>', distance)"></span> &sdot;
            <span data-bind="text: schedule"></span>
        </small></p>
    </div>
    <!-- /ko -->
</div>