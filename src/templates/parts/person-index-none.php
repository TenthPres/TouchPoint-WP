<?php

use tp\TouchPointWP\TouchPointWP;

?>
<article id="people-none" class="error">
    <header class="entry-header">
        <div class="entry-header-inner">
        <?php
        $tpwp = TouchPointWP::instance();
        $people = __("People", TouchPointWP::TEXT_DOMAIN);
        echo sprintf("<h2>" . __('No %s Found.', TouchPointWP::TEXT_DOMAIN) . "</h2>", $people);

        if (current_user_can('activate_plugins') && $tpwp->settings->inv_cron_last_run === false) { // TODO replace with something not involvement-specific
            echo sprintf("<p>" . __('%s will be imported overnight for the first time.', TouchPointWP::TEXT_DOMAIN) . "</p>", $people);
        }
        ?>
        </div>
    </header>
</article>