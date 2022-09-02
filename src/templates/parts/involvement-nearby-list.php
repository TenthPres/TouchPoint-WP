<div class="small-group-summary" id="<?php /** @noinspection PhpUndefinedVariableInspection */ echo $nearbyListId ?>">
    <p data-bind="html: labelStr" class="nearby-label"><?php _e('Loading...') ?></p>
    <!-- ko foreach: nearby -->
    <div class="resource-listing">
        <h3 class="resource-title">
            <a onclick="tpvm._utils.ga('send', 'event', this.getAttribute('data-inv-type'), 'finder click', this.innerText)"
               data-bind="text: name, attr: { href: path, 'data-inv-type': invType }"></a>
        </h3>
        <p><small><span data-bind="text: distance"></span>mi &sdot; <span data-bind="text: schedule"></span></small></p><!-- i18n -->
    </div>
    <!-- /ko -->
</div>