<?php
/**
 * The default template for listing meetings. This template will only be used if no more specific template is found
 * in the Theme.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * Template Name: TouchPoint Meeting List
 */

use tp\TouchPointWP\Meeting;
use tp\TouchPointWP\TouchPointWP;

$postType = is_archive() ? get_queried_object()->name : false;

get_header($postType);

$description = get_the_archive_description();

if (have_posts()) {
    global $wp_query;

    TouchPointWP::enqueuePartialsStyle();
    ?>
    <header class="archive-header has-text-align-center header-footer-group">
        <div class="archive-header-inner section-inner medium">
            <h1 class="archive-title page-title"><?php _ex("Events", "What Meetings should be called, plural.", 'TouchPoint-WP') ?></h1>
            <?php echo Meeting::filterShortcode(['type' => $postType]); ?>
            <?php if ($description) { ?>
                <div class="archive-description"><?php echo wp_kses_post(wpautop($description)); ?></div>
            <?php } ?>
        </div>
    </header>

    <?php

    Meeting::doMeetingList($wp_query);

    wp_reset_query();
    $taxQuery = [[]];
    $wp_query->tax_query->queries = $taxQuery;
    $wp_query->query_vars['tax_query'] = $taxQuery;
    $wp_query->is_tax = false;  // prevents templates from thinking this is a taxonomy archive
} else {
    $loadedPart = get_template_part('list-none', 'meeting-list-none');
    if ($loadedPart === false) {
        require TouchPointWP::$dir . "/src/templates/parts/meeting-list-none.php";
    }
}

get_footer();