<?php

use tp\TouchPointWP\Person;

/** @var $person Person */
/** @var $iid int */

?>

<article id="person-<?php echo $person->peopleId; ?>" <?php post_class("people-index-item"); ?> data-tp-person="<?php echo $person->peopleId ?>">
    <header class="entry-header">
        <div class="entry-header-inner">
            <?php
            if ($person->hasProfilePage()) {
                /** @noinspection HtmlUnknownTarget */
                echo sprintf(
                    '<h2 class="entry-title default-max-width heading-size-1"><a href="%s">%s</a></h2>',
                    esc_url($person->getProfileUrl()),
                    esc_html($person->display_name)
                );
            } else {
                echo sprintf(
                    '<h2 class="entry-title default-max-width heading-size-1">%s</h2>',
                    esc_html($person->display_name)
                );
            }
        ?>
        </div>
        <div class="post-meta-single post-meta-single-top">
            <span class="post-meta">
                <?php
                if (isset($iid)) {
                    $membership = $person->getInvolvementMemberships($iid);
                    echo $membership->description;
                }
                ?>
            </span><!-- .post-meta -->
        </div>
    </header><!-- .entry-header -->
    <div class="entry-content">
        <?php //echo wp_trim_words($person->description, 20, "..."); ?>
    </div>
    <div class="actions person-actions">
        <?php echo $person->getActionButtons(); ?>
    </div>
</article>