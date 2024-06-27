<?php

use tp\TouchPointWP\Involvement;
use tp\TouchPointWP\Meeting;
use tp\TouchPointWP\PostTypeCapable;
use tp\TouchPointWP\TouchPointWP;

$postType = get_post_type();
$settings = Involvement::getSettingsForPostType($postType);

get_header($postType);

the_post();
$p   = get_post();
$tps = TouchPointWP::instance()->settings;
$obj = PostTypeCapable::fromPost($p);

TouchPointWP::enqueuePartialsStyle();

?>

<header class="archive-header has-text-align-center header-footer-group">
    <?php
    $image = get_the_post_thumbnail_url($p, 'full');
    $imageAlt = esc_html(get_the_post_thumbnail_caption($p));
    if ($image) {
        echo "<div class=\"header-image-container\" style=\"background-image: url('$image');\">";
        echo "<img src='$image' alt='$imageAlt' class='involvement-header-image tp-header-image'>";
        echo "</div>";
    }
    ?>

    <div class="archive-header-inner section-inner medium">
        <h1 class="archive-title page-title"><?php echo the_title() ?></h1>
    </div>
</header>

<article <?php post_class(); ?> id="post-<?php the_ID(); ?>" data-tp-involvement="<?php echo $p->ID ?>">
    <div class="post-inner involvement-inner">
        <div class="entry-content">
            <?php
            the_content();
            ?>
        </div><!-- .entry-content -->
    </div><!-- .post-inner -->

    <div class="section-inner TouchPointWP-detail">
        <div class="TouchPointWP-detail-cell">
            <div class="TouchPointWP-detail-cell-section involvement-logistics">
                <?php
                $metaStrings = [];
                foreach ($obj->notableAttributes() as $a)
                {
                    $metaStrings[] = sprintf( '<span class="meta-text">%s</span>', $a);
                }
                echo implode("<br />", $metaStrings);
                ?>
            </div>
            <div class="TouchPointWP-detail-cell-section involvement-actions">
                <?php echo $obj->getActionButtons('single-template', "btn button") ?>
            </div>
        </div>
        <?php if ($settings->useGeo && $obj->hasGeo() !== null) { ?>
        <div class="TouchPointWP-detail-cell TouchPointWP-map-container">
            <!-- TODO this doesn't work for meetings. -->
            <?php echo Involvement::mapShortcode() ?>
        </div>
        <?php } ?>
    </div>
</article>

<?php if ($settings->hierarchical) {
	$children = get_children([
		                         'post_parent' => $p->ID,
		                         'orderby' => 'title',
		                         'order' => 'ASC',
		                         'meta_key'     => TouchPointWP::INVOLVEMENT_META_KEY,
		                         'meta_value'   => 0,
		                         'meta_compare' => '>'
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
}

if ($settings->importMeetings && $tps->enable_meeting_cal === "on") {
	$meetings = get_children([
		                         'post_parent'  => $p->ID,
		                         'order'        => 'ASC',
		                         'orderby'      => 'meta_value_num',
		                         'meta_key'     => Meeting::MEETING_START_META_KEY,
		                         'meta_value'   => time(),
		                         'meta_compare' => '>'
	                         ]);
	$count = count($meetings);
	if ($count > 0) {
		echo "<div class='event-list'>";
		$heading = sprintf(
		// translators: %1$s is the singular name of the event type, %2$s is the plural name of the event type
			_n('Upcoming %1$s', 'Upcoming %2$s', 'TouchPoint-WP'),
			TouchPointWP::instance()->settings->mc_name_singular,
			TouchPointWP::instance()->settings->mc_name_plural
		);
		echo "<h3>$heading</h3>";
	}
	foreach ($meetings as $post) {
		/** @var WP_Post $post */
		$loadedPart = get_template_part('list-item', 'event-list-item');
		if ($loadedPart === false) {
			TouchPointWP::enqueuePartialsStyle();
			require TouchPointWP::$dir . "/src/templates/parts/meeting-list-item.php";
		}
	}
	if (count($meetings) > 0) {
		echo "</div>";
	}
} ?>

<?php get_footer();