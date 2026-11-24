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

if (have_posts()) {
    global $wp_query;

    TouchPointWP::enqueuePartialsStyle("involvement-archive");
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

    <main class="TouchPointWP-main">

    <?php

    Involvement::doArchiveList($wp_query);
} else {
    $loadedPart = get_template_part('list-none', 'involvement-list-none');
    if ($loadedPart === false) {
        require TouchPointWP::$dir . "/src/templates/parts/involvement-list-none.php";
    }
}

?>
    </main><!-- .TouchPointWP-main -->
<?php
get_footer();