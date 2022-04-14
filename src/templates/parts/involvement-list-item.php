<?php

use tp\TouchPointWP\Involvement;
use tp\TouchPointWP\TouchPointWP;

$post = get_post();

/** @var $post WP_Post */

$inv = Involvement::fromPost($post);

$postTypeClass = get_post_type($post);
$postTypeClass = str_replace(TouchPointWP::HOOK_PREFIX, "", $postTypeClass);

?>

<article id="<?php echo $postTypeClass; ?>-<?php the_ID(); ?>" <?php post_class("involvement-list-item"); ?> data-tp-involvement="<?php echo $inv->post_id ?>">
    <header class="tp-header">
        <div class="tp-header-inner">
        <?php
        /** @noinspection HtmlUnknownTarget */
        the_title(sprintf('<h2 class="entry-title default-max-width heading-size-1"><a href="%s">', esc_url(get_permalink())), '</a></h2>');
        ?>
        </div>
        <div class="post-meta-single post-meta-single-top">
            <span class="post-meta">
                <?php
                $metaStrings = [];
                $metaKeys = [
                    TouchPointWP::SETTINGS_PREFIX . "meetingSchedule",
                    TouchPointWP::SETTINGS_PREFIX . "locationName",
                ];
                foreach ($metaKeys as $mk) {
                    if ($post->$mk) {
                        $metaStrings[] = sprintf( '<span class="meta-text">%s</span>', $post->$mk);
                    }
                }

                foreach ($inv->getDivisionsStrings() as $a)
                {
                    $metaStrings[] = sprintf( '<span class="meta-text">%s</span>', $a);
                }

                $metaKeys = [
                    TouchPointWP::SETTINGS_PREFIX . "leaders",
                ];
                foreach ($metaKeys as $mk) {
                    if ($post->$mk) {
                        $metaStrings[] = sprintf( '<span class="meta-text">%s</span>', $post->$mk);
                    }
                }

                foreach ($inv->notableAttributes() as $a)
                {
                    $metaStrings[] = sprintf( '<span class="meta-text">%s</span>', $a);
                }

                echo implode(" &nbsp;&#9702;&nbsp; ", $metaStrings);
                ?>
            </span><!-- .post-meta -->
        </div>
    </header><!-- .entry-header -->
    <div class="tp-content">
        <?php echo wp_trim_words(get_the_excerpt(), 20, "..."); ?>
    </div>
    <div class="actions involvement-actions <?php echo $postTypeClass; ?>-actions">
        <?php echo $inv->getActionButtons(); ?>
    </div>
</article>