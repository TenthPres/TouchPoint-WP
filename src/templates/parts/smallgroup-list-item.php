

<article id="post-<?php the_ID(); ?>" <?php post_class(); ?> class="smallgroup-list">

    <header class="entry-header">
        <?php
        the_title( sprintf( '<h2 class="entry-title default-max-width"><a href="%s">', esc_url( get_permalink() ) ), '</a></h2>' );
        ?>
    </header><!-- .entry-header -->

	<div class="entry-content">
		<?php the_excerpt(); ?>
	</div><!-- .entry-content -->

	<footer class="entry-footer default-max-width">
<!--		--><?php //twenty_twenty_one_entry_meta_footer(); ?>
	</footer><!-- .entry-footer -->
</article><!-- #post-${ID} -->