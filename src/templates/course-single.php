<?php

use tp\TouchPointWP\Course;
use tp\TouchPointWP\TouchPointWP;

get_header("course");

the_post();
$p  = get_post();
$cs = Course::fromPost($p);

Course::enqueueTemplateStyle();
wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . 'swal2-defer');
wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . 'base');

?>

<header class="archive-header has-text-align-center header-footer-group">
    <div class="archive-header-inner section-inner medium">
        <h1 class="archive-title page-title"><?php echo the_title() ?></h1>
    </div>
</header>

<article <?php post_class(); ?> id="post-<?php the_ID(); ?>" data-tp-involvement="<?php echo $cs->invId ?>">
    <div class="post-inner course-inner">
        <div class="entry-content">
            <?php
                the_content();
            ?>
        </div><!-- .entry-content -->
    </div><!-- .post-inner -->

    <div class="section-inner course-detail">
        <div class="course-detail-cell">
            <div class="course-detail-cell-section course-logistics" >
                <?php
                $metaKeys    = [
                    TouchPointWP::SETTINGS_PREFIX . "meetingSchedule",
                    TouchPointWP::SETTINGS_PREFIX . "locationName",
                    TouchPointWP::SETTINGS_PREFIX . "leaders"
                ];
                $metaStrings = [];
                foreach ($metaKeys as $mk) {
                    if ($p->$mk) {
                        $metaStrings[] = sprintf('<span class="meta-text">%s</span>', $p->$mk);
                    }
                }
                foreach ($cs->notableAttributes() as $a)
                {
                    $metaStrings[] = sprintf( '<span class="meta-text">%s</span>', $a);
                }
                echo implode("<br />", $metaStrings);
                ?>
            </div>
            <div class="course-detail-cell-section course-actions">
                <?php echo $cs->getActionButtons() ?>
            </div>
        </div>
    </div>
</article>

<?php get_footer();