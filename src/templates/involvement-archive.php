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

if (have_posts()) {
    $location = TouchPointWP::instance()->geolocate(false);

    if ($location !== false) {
        // we have a viable location. Use it for sorting by distance.
        Involvement::setComparisonGeo($location);
        TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_PRIVATE);
    }
}

get_header($postType);

$description = get_the_archive_description();

if (have_posts()) {
    global $wp_query;

    $wp_query->set('posts_per_page', -1);
    $wp_query->set('nopaging', true);
    $wp_query->set('orderby', 'title'); // will mostly be overwritten by geographic sort, if available.
    $wp_query->set('order', 'ASC');
    $wp_query->set('post_parent', 0);

    TouchPointWP::enqueuePartialsStyle();
    ?>
    <header class="archive-header has-text-align-center header-footer-group">
        <div class="archive-header-inner section-inner medium">
            <h1 class="archive-title page-title"><?php echo $settings->namePlural; ?></h1>
            <?php if ($settings->useGeo) { ?>
            <div class="map TouchPointWP-map-container"><?php echo Involvement::mapShortcode(); ?></div>
            <?php } ?>
            <?php echo Involvement::filterShortcode(['type' => $postType]); ?>
            <?php if ($description) { ?>
                <div class="archive-description"><?php echo wp_kses_post(wpautop($description)); ?></div>
            <?php } ?>
        </div>
    </header>

    <?php

    $terms = [null];

    $groupBy = $settings->groupBy;
    $groupByOrder = "ASC";
    if (strlen($groupBy) > 1 && $groupBy[0] === "-") {
        $groupBy = substr($groupBy, 1);
        $groupByOrder = "DESC";
    }

    if ($groupBy !== "" && taxonomy_exists($groupBy)) {
        $terms = get_terms([
            'taxonomy'   => $groupBy,
            'order'      => $groupByOrder,
            'orderby'    => 'name',
            'hide_empty' => true,
            'fields'     => 'id=>name'
        ]);
    }

    foreach ($terms as $termId => $name) {
        if (count($terms) > 1) {
            // do the tax filtering
            $taxQuery = [[
                'taxonomy' => $groupBy,
                'field'    => 'term_id',
                'terms'    => [$termId],
            ]];
            $wp_query->tax_query->queries = $taxQuery;
            $wp_query->query_vars['tax_query'] = $taxQuery;
            global $posts;

            echo "<div class=\"involvement-list\">";

            echo "<h2>$name</h2>";

            global $posts;
            $posts = $wp_query->get_posts();

            usort($posts, [Involvement::class, 'sortPosts']);

            while ($wp_query->have_posts()) {
                $wp_query->the_post();
                $loadedPart = get_template_part('list-item', 'involvement-list-item');
                if ($loadedPart === false) {
                    require TouchPointWP::$dir . "/src/templates/parts/involvement-list-item.php";
                }
            }

            echo "</div>";
        }

        wp_reset_query();
    }
    $taxQuery = [[]];
    $wp_query->tax_query->queries = $taxQuery;
    $wp_query->query_vars['tax_query'] = $taxQuery;
    $wp_query->is_tax = false;  // prevents templates from thinking this is a taxonomy archive
    global $posts;
} else {
    $loadedPart = get_template_part('list-none', 'involvement-list-none');
    if ($loadedPart === false) {
        require TouchPointWP::$dir . "/src/templates/parts/involvement-list-none.php";
    }
}

get_footer();