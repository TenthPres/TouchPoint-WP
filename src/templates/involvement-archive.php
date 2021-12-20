<?php
/**
 * The default template for listing involvements. This template will only be used if no more specific template is found
 * in the Theme.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * Template Name: TouchPoint Involvement List
 */

use tp\TouchPointWP\Involvement;
use tp\TouchPointWP\TouchPointWP;

$postType = is_archive() ? get_queried_object()->name : false;
$settings = Involvement::getSettingsForPostType($postType);

get_header($postType);

$description = get_the_archive_description();

if ( have_posts() ) {
    Involvement::enqueueTemplateStyle();
    ?>
    <header class="archive-header has-text-align-center header-footer-group">
        <div class="archive-header-inner section-inner medium">
            <h1 class="archive-title page-title"><?php echo $settings->namePlural; ?></h1>
            <?php if ($settings->useGeo) { ?>
            <div class="map involvement-map-container"><?php echo Involvement::mapShortcode(); ?></div>
            <?php } ?>
            <?php echo Involvement::filterShortcode(['type' => $postType]); ?>
            <?php if ($description) { ?>
                <div class="archive-description"><?php echo wp_kses_post(wpautop($description)); ?></div>
            <?php } ?>
        </div>
    </header>
    <div class="involvement-list">
    <?php

    global $wp_the_query;

    $wp_the_query->set('posts_per_page', -1);
    $wp_the_query->set('nopaging', true);
    $wp_the_query->set('orderby', 'title'); // will mostly be overwritten by geographic sort, if available.
    $wp_the_query->set('order', 'ASC');

    $wp_the_query->get_posts();
    $wp_the_query->rewind_posts();

    $location = TouchPointWP::instance()->geolocate(false);

    if ((get_class($location) !== WP_Error::class) && $location !== false) {
        // we have a viable location. Use it for sorting by distance.
        Involvement::setComparisonGeo($location);
        TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_PRIVATE);
    }

    global $posts;
    usort($posts, [Involvement::class, 'sortPosts']);

    while ($wp_the_query->have_posts()) {
        $wp_the_query->the_post();
        $loadedPart = get_template_part('list-item', 'involvement-list-item');
        if ($loadedPart === false) {
            require TouchPointWP::$dir . "/src/templates/parts/involvement-list-item.php";
        }
    }
    ?>
    </div>
    <?php
} else {
    $loadedPart = get_template_part('list-none', 'involvement-list-none');
    if ($loadedPart === false) {
        require TouchPointWP::$dir . "/src/templates/parts/involvement-list-none.php";
    }
}

get_footer();