<?php

use tp\TouchPointWP\TouchPointWP;

?>

<article id="smallgroup-none" class="error">
    <header class="entry-header">
        <div class="entry-header-inner">
        <?php
        $tpwp = TouchPointWP::instance();
        $sgName = $tpwp->settings->sg_name_plural;
        echo sprintf("<h2>" . __('No %s Found.', TouchPointWP::TEXT_DOMAIN) . "</h2>", $sgName);

        if (current_user_can('activate_plugins') && $tpwp->settings->sg_cron_last_run === false) {
            echo sprintf("<p>" . __('%s will be imported overnight for the first time.', TouchPointWP::TEXT_DOMAIN) . "</p>", $sgName);
        }
        ?>
        </div>
    </header>
</article>