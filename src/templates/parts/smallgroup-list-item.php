<?php

use tp\TouchPointWP\TouchPointWP;
?>

<article id="smallgroup-<?php the_ID(); ?>" <?php post_class("smallgroup-list-item"); ?>>
    <header class="entry-header">
        <div class="entry-header-inner">
        <?php
        the_title( sprintf( '<h2 class="entry-title default-max-width heading-size-1"><a href="%s">', esc_url( get_permalink() ) ), '</a></h2>' );
        ?>
        </div>
        <div class="post-meta-single post-meta-single-top">
            <ul class="post-meta">
                <li class="post-date meta-wrapper">
                    <?php
                    $metaKeys = [
                        TouchPointWP::SETTINGS_PREFIX . "meetingSchedule",
                        TouchPointWP::SETTINGS_PREFIX . "locationName"
                    ];
                    $metaStrings = [];
                    foreach ($metaKeys as $mk) {
                        if ($post->$mk) {
                            $metaStrings[] = sprintf( '<span class="meta-text"><a href="%s">%s</a></span>',
                                                      esc_url( get_permalink() ), $post->$mk );
                        }
                    }
                    // TODO add leader names

                    echo implode(" &nbsp;&bull;&nbsp; ", $metaStrings);
                    ?>
                </li>
            </ul><!-- .post-meta -->
        </div>
    </header><!-- .entry-header -->
    <div class="thin">
        <div class="entry-content">
            <?php echo wp_trim_words(get_the_excerpt(), 20, "..."); ?>
        </div><!-- .entry-content -->
    </div>

	<footer class="entry-footer default-max-width">
<!--		--><?php //twenty_twenty_one_entry_meta_footer(); ?>
	</footer><!-- .entry-footer -->
</article><!-- #post-${ID} -->