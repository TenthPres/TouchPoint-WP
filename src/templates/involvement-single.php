<?php

use tp\TouchPointWP\Involvement;
use tp\TouchPointWP\Meeting;
use tp\TouchPointWP\PostTypeCapable;
use tp\TouchPointWP\TouchPointWP;

$postType = get_post_type();
$settings = Involvement::getSettingsForPostType($postType);

get_header($postType);

the_post();
$p   = get_post();
$tps = TouchPointWP::instance()->settings;
/** @var Involvement|Meeting $obj */
$obj = PostTypeCapable::fromPost($p);

TouchPointWP::enqueuePartialsStyle("involvement-single");

// What this page shows is worked out by the object, so a theme's version of this template can do the same:
$headerImage = $obj->headerImage();   // An object with the image's url and alt text, or null.
$parentLink  = $obj->parentLink();    // A link to the parent, or null.
$notice      = $obj->statusNotice();  // An object with a level and a message, such as "This event has been cancelled", or null.
$children    = $obj->childItems();    // An object with `current` and `past` lists, whose items have a printListItem() method.

?>

<header class="archive-header has-text-align-center header-footer-group">
    <?php if ($headerImage !== null) { ?>
        <div class="header-image-container">
            <div class="header-image involvement-header-image" style="background-image: url('<?php echo $headerImage->url; ?>');">
                <img src="<?php echo $headerImage->url; ?>" alt="<?php echo $headerImage->alt; ?>" class="tpwp-accessibility-header-image">
            </div>
        </div>
    <?php } ?>

    <div class="archive-header-inner section-inner medium">
        <h1 class="archive-title page-title"><?php echo the_title() ?></h1>
        <?php if ($parentLink !== null) { ?>
            <p class="parent-link">
                <?php
                // Translators: %s is a link to the event or involvement this one is part of.
                echo wp_sprintf(__('Part of %s', 'TouchPoint-WP'), $parentLink);
                ?>
            </p>
        <?php } ?>
    </div>

    <?php if ($notice !== null) { ?>
        <div class="section-inner tpwp-alert-block<?php echo $notice->level === 'info' ? ' tpwp-alert-info' : ''; ?>">
            <?php echo $notice->message; ?>
        </div>
    <?php } ?>
</header>

<article <?php post_class(); ?> id="post-<?php the_ID(); ?>" data-tp-involvement="<?php echo $p->ID ?>">
    <div class="post-inner involvement-inner">
        <div class="entry-content">
            <?php
            the_content();
            ?>
        </div><!-- .entry-content -->
    </div><!-- .post-inner -->

    <div class="section-inner TouchPointWP-detail">
        <div class="TouchPointWP-detail-cell">
            <div class="TouchPointWP-detail-cell-section involvement-logistics">
                <?php
                $notableAttributes = $obj->notableAttributes();
                echo $notableAttributes->join("<br />");
                ?>
            </div>
            <div class="TouchPointWP-detail-cell-section involvement-actions">
                <?php echo $obj->getActionButtons('single-template', "btn button") ?>
            </div>
        </div>
        <?php if ($settings->useGeo && $obj->hasGeo()) { ?>
            <div class="TouchPointWP-detail-cell TouchPointWP-map-container">
                <!-- TODO this doesn't work for meetings. -->
                <?php echo Involvement::mapShortcode() ?>
            </div>
        <?php } ?>
    </div>
</article>

<?php
// Child involvements, meetings, and groups of meetings (Editions, Clusters), in one list, with the ones that are over
// listed after the others.  If there's nothing else, they're listed without hiding them.
$hasCurrent = count($children->current) > 0;
$hasPast    = count($children->past) > 0;
?>

<?php if ($hasCurrent) { ?>
    <div class="inv-list child-items child-items-current">
        <?php foreach ($children->current as $child) { ?>
            <?php $child->printListItem(); ?>
        <?php } ?>
    </div>
<?php } ?>

<?php if ($hasPast && ! $hasCurrent) { ?>
    <div class="inv-list child-items child-items-past">
        <?php foreach ($children->past as $child) { ?>
            <?php $child->printListItem(); ?>
        <?php } ?>
    </div>
<?php } elseif ($hasPast) { ?>
    <details class="child-items-past">
        <summary>
            <h2 class="inline">
                <?php
                // Translators: %s is the plural name of Meetings, such as "Events".
                echo wp_sprintf(__('Past %s', 'TouchPoint-WP'), $tps->mc_name_plural);
                ?>
            </h2>
        </summary>
        <div class="inv-list child-items">
            <?php foreach ($children->past as $child) { ?>
                <?php $child->printListItem(); ?>
            <?php } ?>
        </div>
    </details>
<?php } ?>

im<?php wp_reset_postdata(); // The list items change the current post. ?>

<?php get_footer();