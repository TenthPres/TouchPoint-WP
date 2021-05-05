<?php

use tp\TouchPointWP\SmallGroup;
use tp\TouchPointWP\TouchPointWP;

get_header("smallgroups");

the_post();
$p = get_post();
$sg = SmallGroup::fromPost($p);

wp_enqueue_style(TouchPointWP::SHORTCODE_PREFIX . 'smallgroups-template-style');
wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . 'swal2-defer');
wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . 'base');
wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . 'smallgroups-defer');

?>

<header class="archive-header has-text-align-center header-footer-group">
    <div class="archive-header-inner section-inner medium">
        <h1 class="archive-title page-title"><?php echo the_title() ?></h1>
    </div>
</header>

<article <?php post_class(); ?> id="post-<?php the_ID(); ?>" data-tp-involvement="<?php echo $sg->invId ?>">
    <div class="post-inner smallgroup-inner">
        <div class="entry-content">
            <?php
                the_content();
            ?>
        </div><!-- .entry-content -->
    </div><!-- .post-inner -->

    <div class="section-inner smallgroup-detail">
        <div class="smallgroup-detail-cell">
            <div class="smallgroup-detail-cell-section smallgroup-logistics" >
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
                foreach ($sg->notableAttributes() as $a)
                {
                    $metaStrings[] = sprintf( '<span class="meta-text">%s</span>', $a);
                }
                echo implode("<br />", $metaStrings);
                ?>
            </div>
            <div class="smallgroup-detail-cell-section smallgroup-actions">
                <?php echo $sg->getActionButtons() ?>
            </div>
        </div>
        <div class="smallgroup-detail-cell smallgroup-map-container">
            <?php echo SmallGroup::mapShortcode([]) ?>
        </div>
    </div>
</article>

<?php get_footer();