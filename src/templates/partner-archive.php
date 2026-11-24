<?php
/**
 * The default template for listing global partners. This template will only be used if no more specific template is found
 * in the Theme.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * Template Name: TouchPoint Partner List
 */

use tp\TouchPointWP\Partner;
use tp\TouchPointWP\TouchPointWP;

$postType = is_archive() ? get_queried_object()->name : false;

get_header($postType);

$description = get_the_archive_description();

if (have_posts()) {
    $tpwp = TouchPointWP::instance();

    TouchPointWP::enqueuePartialsStyle("partner-archive");
    ?>
    <header class="archive-header has-text-align-center header-footer-group">
        <div class="archive-header-inner section-inner medium">
            <h1 class="archive-title page-title"><?php echo $tpwp->settings->global_name_plural ?></h1>
            <div class="map TouchPointWP-map-container"><?php echo Partner::mapShortcode(); ?></div>
            <?php echo Partner::filterShortcode(['type' => $postType]); ?>
            <?php if ($description) { ?>
                <div class="archive-description"><?php echo wp_kses_post(wpautop($description)); ?></div>
            <?php } ?>
        </div>
    </header>

    <main class="TouchPointWP-main">
    <div class="partner-list">
    <?php Partner::doList(); ?>
    </div>
    </main>
    <?php
} else {
    $loadedPart = get_template_part('list-none', 'involvement-list-none');
    if ($loadedPart === false) {
        require TouchPointWP::$dir . "/src/templates/parts/involvement-list-none.php";
    }
}

get_footer();