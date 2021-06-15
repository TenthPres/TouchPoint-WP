<?php

use tp\TouchPointWP\Course;
use tp\TouchPointWP\TouchPointWP;

/** @var $post WP_Post */

$cs = Course::fromPost($post);

?>

<article id="course-<?php the_ID(); ?>" <?php post_class("course-list-item"); ?> data-tp-involvement="<?php echo $cs->invId ?>">
    <header class="entry-header">
        <div class="entry-header-inner">
        <?php
        the_title( sprintf( '<h2 class="entry-title default-max-width heading-size-1"><a href="%s">', esc_url( get_permalink() ) ), '</a></h2>' );
        ?>
        </div>
        <div class="post-meta-single post-meta-single-top">
            <span class="post-meta">
                <?php
                $metaStrings = [];
                $metaKeys = [
                    TouchPointWP::SETTINGS_PREFIX . "meetingSchedule",
                    TouchPointWP::SETTINGS_PREFIX . "locationName",
                ];
                foreach ($metaKeys as $mk) {
                    if ($post->$mk) {
                        $metaStrings[] = sprintf( '<span class="meta-text">%s</span>', $post->$mk);
                    }
                }

                foreach ($cs->getDivisionsStrings() as $a)
                {
                    $metaStrings[] = sprintf( '<span class="meta-text">%s</span>', $a);
                }

                $metaKeys = [
                    TouchPointWP::SETTINGS_PREFIX . "leaders",
                ];
                foreach ($metaKeys as $mk) {
                    if ($post->$mk) {
                        $metaStrings[] = sprintf( '<span class="meta-text">%s</span>', $post->$mk);
                    }
                }

                foreach ($cs->notableAttributes() as $a)
                {
                    $metaStrings[] = sprintf( '<span class="meta-text">%s</span>', $a);
                }

                echo implode(" &nbsp;&#9702;&nbsp; ", $metaStrings);
                ?>
            </span><!-- .post-meta -->
        </div>
    </header><!-- .entry-header -->
    <div class="thin entry-content">
        <?php echo wp_trim_words(get_the_excerpt(), 40, "..."); ?>
    </div>
    <div class="thin actions course-actions">
        <?php echo $cs->getActionButtons(); ?>
    </div>
</article>