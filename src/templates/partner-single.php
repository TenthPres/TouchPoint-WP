<?php

use tp\TouchPointWP\Partner;
use tp\TouchPointWP\TouchPointWP;

$postType = get_post_type();

get_header($postType);

the_post();
$pst   = get_post();
$prtnr = Partner::fromPost($pst);

TouchPointWP::enqueuePartialsStyle();

?>

<header class="archive-header has-text-align-center header-footer-group">
    <div class="archive-header-inner section-inner medium">
        <h1 class="archive-title page-title"><?php echo the_title() ?></h1>
    </div>
</header>

<article <?php post_class(); ?> id="post-<?php the_ID(); ?>" data-tp-partner="<?php echo $prtnr->post_id ?>">
    <div class="post-inner partner-inner">
        <div class="entry-content">
            <?php
                the_content();
            ?>
        </div><!-- .entry-content -->
    </div><!-- .post-inner -->

    <div class="section-inner TouchPointWP-detail">
        <div class="TouchPointWP-detail-cell">
            <div class="TouchPointWP-detail-cell-section partner-logistics" >
                <?php
                $metaStrings = [];
                foreach ($prtnr->notableAttributes() as $a)
                {
                    $metaStrings[] = sprintf( '<span class="meta-text">%s</span>', $a);
                }
                echo implode("<br />", $metaStrings);
                ?>
            </div>
            <div class="TouchPointWP-detail-cell-section partner-actions">
                <?php echo $prtnr->getActionButtons('single-template', "btn button") ?>
            </div>
        </div>
        <?php if (!$prtnr->decoupleLocation && $prtnr->geo !== null) { ?>
        <div class="TouchPointWP-detail-cell TouchPointWP-map-container">
            <?php echo Partner::mapShortcode() ?>
        </div>
        <?php } ?>
    </div>
</article>

<?php get_footer();