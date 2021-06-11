<?php
/**
 * The default template for listing small groups. This template will only be used if no more specific template is found
 * in the Theme.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * Template Name: TouchPoint Small Group List
 *
 * TODO use native loop rather than re-querying.  Allows external filtering to be applied.
 *
 */

use tp\TouchPointWP\SmallGroup;
use tp\TouchPointWP\TouchPointWP;


get_header("smallgroups");

$description = get_the_archive_description();

if ( have_posts() ) {
    SmallGroup::enqueueTemplateStyle();
    wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . 'swal2-defer');
    wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . 'base');
    wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . 'smallgroups-defer');

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
    while (have_posts()) {
        the_post();
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