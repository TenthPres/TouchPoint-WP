<?php
/**
 * The default template for listing small groups. This template will only be used if no more specific template is found
 * in the Theme.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * Template Name: TouchPoint Small Group List
 *
 */

use tp\TouchPointWP\SmallGroup;
use tp\TouchPointWP\TouchPointWP;

$q = new WP_Query([
        'post_type' => SmallGroup::POST_TYPE,
        'nopaging'  => true,
        'meta_query' => [
            'relation' => 'AND',
            'openClause' => [
                'key'     => TouchPointWP::SETTINGS_PREFIX . "groupClosed",
                'value'   => true,
                'compare' => '!=',
            ],
            'notFullClause' => [
                'key'     => TouchPointWP::SETTINGS_PREFIX . "groupFull",
                'value'   => true,
                'compare' => '!=',
            ]
        ],
        'order' => 'ASC',
        'meta_key' => TouchPointWP::SETTINGS_PREFIX . "nextMeeting",
        'orderby' => 'meta_value_num' // TODO figure out why this isn't sorting.
    ]);


get_header("smallgroups");

$description = get_the_archive_description();

if ( $q->have_posts() ) {
    wp_enqueue_style(TouchPointWP::SHORTCODE_PREFIX . 'smallgroups-template-style');
    wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . 'swal2-defer');
    wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . 'base');
    wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . 'smallgroups-defer');

    ?>

    <header class="archive-header has-text-align-center header-footer-group">
        <div class="archive-header-inner section-inner medium">
            <h1 class="archive-title page-title"><?php echo TouchPointWP::instance()->settings->sg_name_plural; ?></h1>
            <div class="map smallgroup-map-container"><?php echo SmallGroup::mapShortcode(['all' => true, 'query' => $q]) ?></div>
            <?php echo SmallGroup::filterShortcode([]); ?>
            <?php if ($description) { ?>
                <div class="archive-description"><?php echo wp_kses_post(wpautop($description)); ?></div>
            <?php } ?>

        </div>

    </header>
    <div class="smallgroup-list">
    <?php
    foreach ( $q->posts as $post ) {
        $post->obj = SmallGroup::fromPost($post);
        $loadedPart = get_template_part('list-item', 'smallgroup-list-item');
        if ($loadedPart === false) {
            require TouchPointWP::$dir . "/src/templates/parts/smallgroup-list-item.php";
        }

    } ?>
    </div>

<?php } else { ?>
    <?php get_template_part( 'template-parts/content/content-none' ); // todo remove or resolve ?>
<?php } ?>

<?php get_footer(); ?>