<?php

use tp\TouchPointWP\Involvement;
use tp\TouchPointWP\TouchPointWP;

$postType = get_post_type();
$settings = Involvement::getSettingsForPostType($postType);

get_header($postType);

the_post();
$p   = get_post();
$inv = Involvement::fromPost($p);

TouchPointWP::enqueuePartialsStyle();

?>

<header class="archive-header has-text-align-center header-footer-group">
    <div class="archive-header-inner section-inner medium">
        <h1 class="archive-title page-title"><?php echo the_title() ?></h1>
    </div>
</header>

<article <?php post_class(); ?> id="post-<?php the_ID(); ?>" data-tp-involvement="<?php echo $inv->post_id ?>">
    <div class="post-inner involvement-inner">
        <div class="entry-content">
            <?php
                the_content();
            ?>
        </div><!-- .entry-content -->
    </div><!-- .post-inner -->

    <div class="section-inner TouchPointWP-detail">
        <div class="TouchPointWP-detail-cell">
            <div class="TouchPointWP-detail-cell-section involvement-logistics" >
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
                foreach ($inv->notableAttributes() as $a)
                {
                    $metaStrings[] = sprintf( '<span class="meta-text">%s</span>', $a);
                }
                echo implode("<br />", $metaStrings);
                ?>
            </div>
            <div class="TouchPointWP-detail-cell-section involvement-actions">
                <?php echo $inv->getActionButtons() ?>
            </div>
        </div>
        <?php if ($settings->useGeo) { ?>
        <div class="TouchPointWP-detail-cell TouchPointWP-map-container">
            <?php echo Involvement::mapShortcode() ?>
        </div>
        <?php } ?>
    </div>
</article>

<?php get_footer();