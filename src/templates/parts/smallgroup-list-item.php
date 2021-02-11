<?php

use tp\TouchPointWP\TouchPointWP;

$sg = new \tp\TouchPointWP\SmallGroup(the_post());
?>

<article id="smallgroup-<?php the_ID(); ?>" <?php post_class("smallgroup-list-item"); ?>>
    <header class="entry-header">
        <div class="entry-header-inner">
        <?php
        the_title( sprintf( '<h2 class="entry-title default-max-width heading-size-1"><a href="%s">', esc_url( get_permalink() ) ), '</a></h2>' );
        ?>
        </div>
        <div class="post-meta-single post-meta-single-top">
            <span class="post-meta">
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

                echo implode(" &nbsp;&#9702;&nbsp; ", $metaStrings);
                ?>
            </span><!-- .post-meta -->
        </div>
    </header><!-- .entry-header -->
    <div class="thin entry-content">
            <?php echo wp_trim_words(get_the_excerpt(), 20, "..."); ?>
    </div>
    <div class="thin actions smallgroup-actions">
        <button type="button">Join</button><?php // TODO: all of this ?>
    </div>
</article><!-- #post-${ID} -->