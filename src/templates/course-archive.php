<?php
/**
 * The default template for listing courses. This template will only be used if no more specific template is found
 * in the Theme.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * Template Name: TouchPoint Course List
 */

use tp\TouchPointWP\Course;
use tp\TouchPointWP\TouchPointWP;


get_header("courses");

$description = get_the_archive_description();

if ( have_posts() ) {
    Course::enqueueTemplateStyle();
    wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . 'swal2-defer');
    wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . 'base');

    function lengthen_course_excerpts($length): int
    {
        global $post;
        if ($post->post_type === Course::POST_TYPE)
            return 40;
        return $length;
    }
    add_filter('excerpt_length', 'lengthen_course_excerpts');

    ?>

    <header class="archive-header has-text-align-center header-footer-group">
        <div class="archive-header-inner section-inner medium">
            <h1 class="archive-title page-title"><?php echo TouchPointWP::instance()->settings->cs_name_plural; ?></h1>
            <?php echo Course::filterShortcode([]); ?>
            <?php if ($description) { ?>
                <div class="archive-description"><?php echo wp_kses_post(wpautop($description)); ?></div>
            <?php } ?>

        </div>

    </header>
    <div class="course-list">
    <?php

    global $wp_the_query;

    $wp_the_query->set('posts_per_page', -1);
    $wp_the_query->set('nopaging', true);

    $wp_the_query->get_posts();
    $wp_the_query->rewind_posts();

    while ($wp_the_query->have_posts()) {
        $wp_the_query->the_post();
        $loadedPart = get_template_part('list-item', 'course-list-item');
        if ($loadedPart === false) {
            require TouchPointWP::$dir . "/src/templates/parts/course-list-item.php";
        }
    }
    ?>
    </div>
    <?php
} else {
    $loadedPart = get_template_part('list-none', 'course-list-none');
    if ($loadedPart === false) {
        require TouchPointWP::$dir . "/src/templates/parts/course-list-none.php";
    }
}

get_footer();