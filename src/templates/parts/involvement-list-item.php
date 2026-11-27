<?php

use tp\TouchPointWP\PostTypeCapable;
use tp\TouchPointWP\TouchPointWP;

global $post;

/** @var $post WP_Post */

$inv = PostTypeCapable::fromPost($post);

$postTypeClass = get_post_type($post);
$postTypeClass = str_replace(TouchPointWP::HOOK_PREFIX, "", $postTypeClass);
$postItemClass = $params['itemclass'] ?? "inv-list-item";

?>

<article id="<?php echo $postTypeClass; ?>-<?php the_ID(); ?>" <?php post_class($postItemClass); ?> data-tp-involvement="<?php echo $post->ID ?>">
    <header class="entry-header">
        <div class="entry-header-inner">
        <?php $listTitle = $inv->titleInList(); ?>
        <?php if ($listTitle !== '') { ?>
            <h2 class="entry-title default-max-width heading-size-1"><a href="<?php echo esc_url($inv->permalink()); ?>"><?php echo $listTitle; ?></a></h2>
        <?php } ?>
        </div>
        <div class="post-meta-single post-meta-single-top">
            <span class="post-meta">
                <?php
                $notableAttributes = $inv->notableAttributes();
                echo $notableAttributes;
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
</article>