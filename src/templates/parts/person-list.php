<?php

/** @var $people Person[] */


use tp\TouchPointWP\TouchPointWP;

if (! empty($people)) { ?>

<div <?php post_class("person-list"); ?>>
<?php
    foreach ($people as $person) {
        require TouchPointWP::$dir . "/src/templates/parts/person-list-item.php";
    } ?>
</div>
<?php
} else {
    echo "<!-- Error: No people to show -->";
    // TODO DIR return some useful output indicating that there's no one to show.
}