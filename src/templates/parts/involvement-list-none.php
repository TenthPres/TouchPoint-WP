<?php

use tp\TouchPointWP\Involvement;
use tp\TouchPointWP\TouchPointWP;

?>

<article id="involvement-none" class="error">
    <header class="entry-header">
        <div class="entry-header-inner">
        <?php
        $postType = is_archive() ? get_queried_object()->name : false;
        $tpwp = TouchPointWP::instance();
        $typeName = Involvement::getSettingsForPostType($postType)->namePlural;
        // translators: %s will be the plural post type (e.g. Small Groups)
        echo sprintf("<h2>" . __('No %s Found.', 'TouchPoint-WP') . "</h2>", $typeName);

        if (current_user_can('activate_plugins') && $tpwp->settings->inv_cron_last_run === false) {
	        // translators: %s will be the plural post type (e.g. Small Groups)
            echo sprintf("<p>" . __('%s will be imported overnight for the first time.', 'TouchPoint-WP') . "</p>", $typeName);
        }
        ?>
        </div>
    </header>
</article>