<?php
/**
 * The template for displaying archive pages
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package WordPress
 * @subpackage Twenty_Twenty_One
 * @since Twenty Twenty-One 1.0
 */

use tp\TouchPointWP\SmallGroup;

get_header();

$description = get_the_archive_description();
?>

<?php if ( have_posts() ) : ?>

    <header class="archive-header has-text-align-center header-footer-group page-header">
        <div class="archive-header-inner section-inner medium">
            <?php
            the_archive_title('<h1 class="archive-title page-title">', '</h1>'); ?>
            <div class="map" style="width:100%; padding-bottom:50%; position:relative;"><?php echo SmallGroup::mapShortcode(['all' => true]) ?></div>
            <?php if ($description) { ?>
                <div class="archive-description"><?php echo wp_kses_post(wpautop($description)); ?></div>
            <?php } ?>

        </div><!-- .archive-header-inner -->

    </header>

    <?php while ( have_posts() ) { ?>
        <?php the_post(); ?>
        <?php get_template_part('parts/listItem'); ?>
    <?php } ?>

<!--    --><?php //twenty_twenty_one_the_posts_navigation(); ?>

<?php else : ?>
    <?php get_template_part( 'template-parts/content/content-none' ); ?>
<?php endif; ?>

<?php get_footer(); ?>