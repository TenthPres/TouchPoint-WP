<?php

use tp\TouchPointWP\Involvement;
use tp\TouchPointWP\Meeting;
use tp\TouchPointWP\PostTypeCapable;
use tp\TouchPointWP\Taxonomies;
use tp\TouchPointWP\TouchPointWP;

$postType = get_post_type();
$settings = Involvement::getSettingsForPostType($postType);

get_header($postType);

the_post();
$p   = get_post();
$tps = TouchPointWP::instance()->settings;
$obj = PostTypeCapable::fromPost($p);

TouchPointWP::enqueuePartialsStyle("involvement-single");

?>

<header class="archive-header has-text-align-center header-footer-group">
    <?php
    $image = get_the_post_thumbnail_url($p, 'full');
    $image = $image ? esc_url($image) : false;
    $imageAlt = esc_html(get_post(get_post_thumbnail_id($p))->post_title);
    if ($image) {
        echo "<div class=\"header-image-container\">";
        echo "<div class=\"header-image involvement-header-image\" style=\"background-image: url('$image');\">";
        echo "<img src='$image' alt='$imageAlt' class='tpwp-accessibility-header-image'>";
        echo "</div>";
        echo "</div>";
    }
    ?>

    <div class="archive-header-inner section-inner medium">
        <h1 class="archive-title page-title"><?php echo the_title() ?></h1>
        <?php
        $parent = $obj->getParent();
        if ($parent !== null && $parent->post_id() !== $p->ID) {
            $parentPost = get_post($parent->post_id());
            if ($parentPost !== null && $parentPost->post_status === 'publish') {
                $parentLink = "<a href=\"" . esc_url(get_permalink($parentPost)) . "\">" . esc_html(get_the_title($parentPost)) . "</a>";
                echo "<p class=\"parent-link\">";
                // Translators: %s is a link to the event or involvement this one is part of.
                echo wp_sprintf(__('Part of %s', 'TouchPoint-WP'), $parentLink);
                echo "</p>";
            }
        }
        ?>
    </div>
    <?php

    if ($obj instanceof Meeting) {
        if ($obj->status() === Meeting::STATUS_CANCELLED) {
            echo "<div class='section-inner tpwp-alert-block'>";

            $meetingsCalled = $tps->mc_name_singular;

            echo wp_sprintf(
                // Translators: %s is the singular name of the of a Meeting, such as "Event".
                __('This %s has been cancelled.', 'TouchPoint-WP'),
                strtolower(__($meetingsCalled)) // deliberately no domain
            );
            echo "</div>";
        } elseif ($obj->isPast()) {
            echo "<div class='section-inner tpwp-alert-block tpwp-alert-info'>";

            $meetingsCalled = $tps->mc_name_singular;

            echo wp_sprintf(
            // Translators: %s is the singular name of the of a Meeting, such as "Event".
                    __('This %s has already happened.', 'TouchPoint-WP'),
                    strtolower(__($meetingsCalled)) // deliberately no domain
            );
            echo "</div>";
        }
    }

    ?>

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
                $notableAttributes = $obj->notableAttributes();
                echo $notableAttributes->join("<br />");
                ?>
            </div>
            <div class="TouchPointWP-detail-cell-section involvement-actions">
                <?php echo $obj->getActionButtons('single-template', "btn button") ?>
            </div>
        </div>
        <?php if ($settings->useGeo && $obj->hasGeo()) { ?>
            <div class="TouchPointWP-detail-cell TouchPointWP-map-container">
                <!-- TODO this doesn't work for meetings. -->
                <?php echo Involvement::mapShortcode() ?>
            </div>
        <?php } ?>
    </div>
</article>

<?php
// Children of this post: child involvements, meetings, and groups of meetings (Editions, Clusters), in one
// list.  Hidden posts aren't included, since get_children() leaves out statuses that are excluded from search.
if ($settings->hierarchical || ($settings->importMeetings && $tps->enable_meeting_cal === "on")) {
	$now     = time();
	$current = [];
	$past    = [];

	foreach (get_children(['post_parent' => $p->ID, 'post_type' => $postType]) as $child) {
		/** @var WP_Post $child */
		$start = intval(get_post_meta($child->ID, Meeting::MEETING_START_META_KEY, true));
		$end   = intval(get_post_meta($child->ID, Meeting::MEETING_END_META_KEY, true)) ?: $start;

		if ($start === 0) {
			// Child involvements have no dates of their own.  They come first, as before.
			$current[] = [0, $child->post_title, $child];
		} elseif ($end >= $now) {
			$current[] = [1, $start, $child];
		} else {
			$past[] = [$start, $child];
		}
	}

	usort($current, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
	usort($past, fn($a, $b) => $b[0] <=> $a[0]); // Newest first

	$renderChild = function (WP_Post $child) use ($settings) {
		global $post;
		$post = $child;
		setup_postdata($post);

		$loadedPart = false;
		if (Meeting::postIsType($child)) {
			$loadedPart = get_template_part('list-item', 'event-list-item');
		}
		if ($loadedPart === false) {
			$loadedPart = get_template_part('list-item', 'involvement-list-item');
		}
		if ($loadedPart === false) {
			require TouchPointWP::$dir . "/src/templates/parts/involvement-list-item.php";
		}
	};

	if (count($current) + count($past) > 0) {
		TouchPointWP::enqueuePartialsStyle("involvement-single child-item");
	}

	if (count($current) > 0) {
		echo "<div class='inv-list child-items child-items-current'>";
		foreach ($current as $c) {
			$renderChild($c[2]);
		}
		echo "</div>";
	}

	if (count($past) > 0) {
		$heading = wp_sprintf(
			// Translators: %s is the plural name of Meetings, such as "Events".
			__('Past %s', 'TouchPoint-WP'),
			$tps->mc_name_plural
		);
		echo "<details class='child-items-past'><summary><h2 class='inline'>$heading</h2></summary>";
		echo "<div class='inv-list child-items'>";
		foreach ($past as $c) {
			$renderChild($c[1]);
		}
		echo "</div></details>";
	}

	wp_reset_postdata();
} ?>

<?php get_footer();