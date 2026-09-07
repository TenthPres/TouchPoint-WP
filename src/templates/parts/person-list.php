<?php

/** @var $people tp\TouchPointWP\Person[] */
/** @var $listClass string */
/** @var $content string Alternative text to use if no people are  */


use tp\TouchPointWP\TouchPointWP;

if (count($people) > 0) { ?>

<div class="<?php echo $listClass; ?>" >
<?php
    foreach ($people as $person) {
        require TouchPointWP::$dir . "/src/templates/parts/person-list-item.php";
    } ?>
</div>
<?php
} else {
    echo $content;
}