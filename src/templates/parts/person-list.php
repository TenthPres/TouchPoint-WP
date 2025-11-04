<?php

/** @var $people Person[] */
/** @var $listClass string */


use tp\TouchPointWP\Person;
use tp\TouchPointWP\TouchPointWP;

if (! empty($people)) { ?>

<div class="<?php echo $listClass; ?>" >
<?php
    foreach ($people as $person) {
        /** @noinspection PhpIncludeInspection */
        require TouchPointWP::$dir . "/src/templates/parts/person-list-item.php";
    } ?>
</div>
<?php
} else {
    echo "<!-- " . __("No people to show.  This may be because the list hasn't synced yet, or because it is not configured correctly.") . " -->";
}