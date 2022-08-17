<?php

use tp\TouchPointWP\Involvement;
use tp\TouchPointWP\Involvement_PostTypeSettings;
use tp\TouchPointWP\TouchPointWP;

global $post;

/** @var $post WP_Post */
/** @var $settings Involvement_PostTypeSettings */

$inv = Involvement::fromPost($post);

if (!isset($settings)) {
    $settings = Involvement::getSettingsForPostType($inv->invType);
}

$postTypeClass = get_post_type($post);
$postTypeClass = str_replace(TouchPointWP::HOOK_PREFIX, "", $postTypeClass);
$postItemClass = $params['itemclass'] ?? "inv-list-item";

?>

<article id="<?php echo $postTypeClass; ?>-<?php the_ID(); ?>" <?php post_class($postItemClass); ?> data-tp-involvement="<?php echo $inv->post_id ?>">
    <header class="entry-header">
        <div class="entry-header-inner">
        <?php
        /** @noinspection HtmlUnknownTarget */
        the_title(sprintf('<h2 class="entry-title default-max-width heading-size-1"><a href="%s">', esc_url(get_permalink())), '</a></h2>');
        ?>
        </div>
        <div class="post-meta-single post-meta-single-top">
            <span class="post-meta">
                <?php
                $metaStrings = [];

                foreach ($inv->notableAttributes() as $a)
                {
                    $metaStrings[] = sprintf( '<span class="meta-text">%s</span>', $a);
                }

                echo implode(tp\TouchPointWP\TouchPointWP::$joiner, $metaStrings);
                ?>
            </span><!-- .post-meta -->
        </div>
    </header><!-- .entry-header -->
    <div class="entry-content">
        <?php echo wp_trim_words(get_the_excerpt(), 20, "..."); ?>
    </div>
    <div class="actions involvement-actions <?php echo $postTypeClass; ?>-actions">
        <?php echo $inv->getActionButtons('list-item', "btn button"); ?>
    </div>
    <?php if (isset($settings) && $settings->hierarchical) {
        $children = get_children([
            'post_parent' => $post->ID,
            'orderby' => 'title',
            'order' => 'ASC'
        ]);
        if (count($children) > 0) {
            echo "<div class='child-involvements'>";
        }
        foreach ($children as $child) {
            /** @var WP_Post $child */
            echo "<div>";
            $link = get_permalink($child);
            echo "<h3 class='inline'><a href=\"$link\" class='small'>$child->post_title</a></h3>";
            echo "</div>";
        }
        if (count($children) > 0) {
            echo "</div>";
        }
    } ?>
</article>