<?php

use tp\TouchPointWP\TouchPointWP;
use tp\TouchPointWP\TouchPointWP_Settings;

?>

<article id="smallgroup-none" class="error">
    <header class="entry-header">
        <div class="entry-header-inner">
        <?php
        $tpwp = TouchPointWP::instance();
        $sgName = TouchPointWP_Settings::instance($tpwp)->sg_name_plural;
        sprintf("<h2>" . __('No %s Found.') . "</h2>");
        ?>
        </div>
    </header>
</article>