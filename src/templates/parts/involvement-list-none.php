<?php

use tp\TouchPointWP\Involvement;
use tp\TouchPointWP\TouchPointWP;

?>

<article id="smallgroup-none" class="error">
    <header class="entry-header">
        <div class="entry-header-inner">
        <?php
        $postType = is_archive() ? get_queried_object()->name : false;
        $tpwp = TouchPointWP::instance();
        $typeName = Involvement::getSettingsForPostType($postType)->namePlural;
        echo sprintf("<h2>" . __('No %s Found.', TouchPointWP::TEXT_DOMAIN) . "</h2>", $typeName);

        if (current_user_can('activate_plugins') && $tpwp->settings->inv_cron_last_run === false) {
            echo sprintf("<p>" . __('%s will be imported overnight for the first time.', TouchPointWP::TEXT_DOMAIN) . "</p>", $typeName);
        }
        ?>
        </div>
    </header>
</article>