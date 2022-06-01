<?php

use tp\TouchPointWP\Partner;
use tp\TouchPointWP\TouchPointWP;

global $post;

/** @var $post WP_Post */

$gp = Partner::fromPost($post);

$postTypeClass = get_post_type($post);
$postTypeClass = str_replace(TouchPointWP::HOOK_PREFIX, "", $postTypeClass);
$postItemClass = $params['itemclass'] ?? "partner-list-item";

?>

<article id="<?php echo $postTypeClass; ?>-<?php the_ID(); ?>" <?php post_class($postItemClass); ?> data-tp-partner="<?php echo $gp->jsId() ?>" style="border-bottom-color: <?php echo $gp->color ?>">
    <?php
    if (has_post_thumbnail($post)) {
        $imgUrl = get_the_post_thumbnail_url($post);
        echo "<img src=\"$imgUrl\" alt=\"$gp->name\" class=\"partner-thumbnail\" />";
    }
    ?>
    <header class="entry-header">
        <div class="entry-header-inner">
        <?php
        /** @noinspection HtmlUnknownTarget */
        the_title(sprintf('<h2 class="entry-title default-max-width heading-size-1"><a href="%s">', esc_url(get_permalink())), '</a></h2>');
        ?>
        </div>
        <div class="post-meta-list-item">
            <span class="post-meta">
                <?php
                $metaStrings = [];

                foreach ($gp->notableAttributes() as $a)
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
    <div class="actions partner-actions <?php echo $postTypeClass; ?>-actions">
        <?php echo $gp->getActionButtons('list-item', "btn button"); ?>
    </div>
</article>