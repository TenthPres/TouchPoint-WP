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
                $metaStrings = [];
                foreach ($inv->notableAttributes() as $a)
                {
                    $metaStrings[] = sprintf( '<span class="meta-text">%s</span>', $a);
                }
                echo implode("<br />", $metaStrings);
                ?>
            </div>
            <div class="TouchPointWP-detail-cell-section involvement-actions">
                <?php echo $inv->getActionButtons('single-template', "btn button") ?>
            </div>
        </div>
        <?php if ($settings->useGeo && $inv->geo !== null) { ?>
        <div class="TouchPointWP-detail-cell TouchPointWP-map-container">
            <?php echo Involvement::mapShortcode() ?>
        </div>
        <?php } ?>
    </div>
</article>

<?php if ($settings->hierarchical) {
    $children = get_children([
                                 'post_parent' => $post->ID,
                                 'orderby' => 'title',
                                 'order' => 'ASC'
                             ]);
    if (count($children) > 0) {
        echo "<div class='involvement-list child-involvements'>";
    }
    foreach ($children as $post) {
        /** @var WP_Post $post */
        $loadedPart = get_template_part('list-item', 'involvement-list-item');
        if ($loadedPart === false) {
            TouchPointWP::enqueuePartialsStyle();
            require TouchPointWP::$dir . "/src/templates/parts/involvement-list-item.php";
        }
    }
    if (count($children) > 0) {
        echo "</div>";
    }
} ?>

<?php get_footer();