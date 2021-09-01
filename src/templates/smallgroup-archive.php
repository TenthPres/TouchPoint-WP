<?php
/**
 * The default template for listing small groups. This template will only be used if no more specific template is found
 * in the Theme.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * Template Name: TouchPoint Small Group List
 */

use tp\TouchPointWP\SmallGroup;
use tp\TouchPointWP\TouchPointWP;


get_header("smallgroups");

$description = get_the_archive_description();

if ( have_posts() ) {
    SmallGroup::enqueueTemplateStyle();

    ?>

    <header class="archive-header has-text-align-center header-footer-group">
        <div class="archive-header-inner section-inner medium">
            <h1 class="archive-title page-title"><?php echo TouchPointWP::instance()->settings->sg_name_plural; ?></h1>
            <div class="map smallgroup-map-container"><?php echo SmallGroup::mapShortcode() ?></div>
            <?php echo SmallGroup::filterShortcode([]); ?>
            <?php if ($description) { ?>
                <div class="archive-description"><?php echo wp_kses_post(wpautop($description)); ?></div>
            <?php } ?>

        </div>

    </header>
    <div class="smallgroup-list">
    <?php

    global $wp_the_query;

    $wp_the_query->set('posts_per_page', -1);
    $wp_the_query->set('nopaging', true);

    $wp_the_query->set('orderby', 'title');
    $wp_the_query->set('order', 'ASC');

    $wp_the_query->get_posts();
    $wp_the_query->rewind_posts();

    while ($wp_the_query->have_posts()) {
        $wp_the_query->the_post();
        $loadedPart = get_template_part('list-item', 'smallgroup-list-item');
        if ($loadedPart === false) {
            require TouchPointWP::$dir . "/src/templates/parts/smallgroup-list-item.php";
        }
    }
    ?>
    </div>
    <?php
} else {
    $loadedPart = get_template_part('list-none', 'smallgroup-list-none');
    if ($loadedPart === false) {
        require TouchPointWP::$dir . "/src/templates/parts/smallgroup-list-none.php";
    }
}

get_footer();