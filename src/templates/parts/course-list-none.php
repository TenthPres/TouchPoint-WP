<?php

use tp\TouchPointWP\TouchPointWP;

?>

<article id="course-none" class="error">
    <header class="entry-header">
        <div class="entry-header-inner">
        <?php
        $tpwp   = TouchPointWP::instance();
        $csName = $tpwp->settings->cs_name_plural;
        echo sprintf("<h2>" . __('No %s Found.', TouchPointWP::TEXT_DOMAIN) . "</h2>", $csName);

        if (current_user_can('activate_plugins') && $tpwp->settings->cs_cron_last_run === false) {
            echo sprintf("<p>" . __('%s will be imported overnight for the first time.', TouchPointWP::TEXT_DOMAIN) . "</p>", $csName);
        }
        ?>
        </div>
    </header>
</article>