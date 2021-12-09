<?php

/** @var $people Person[] */


use tp\TouchPointWP\TouchPointWP;

if (! empty($people)) { ?>

<div <?php post_class("people-index"); ?>>
<?php
    foreach ($people as $person) {
        require TouchPointWP::$dir . "/src/templates/parts/person-index-item.php";
    } ?>
</div>
<?php
} else {
    echo "<!-- Error: No people to show -->";
    // TODO return some useful output indicating that there's no one to show.
}