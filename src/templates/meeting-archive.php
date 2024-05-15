<?php
/**
 * The default template for listing meetings. This template will only be used if no more specific template is found
 * in the Theme.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * Template Name: TouchPoint Meeting List
 */

use tp\TouchPointWP\CalendarGrid;
use tp\TouchPointWP\TouchPointWP;

$postType = is_archive() ? get_queried_object()->name : false;

get_header($postType);

$description = get_the_archive_description();

if (have_posts()) {
    global $wp_query;

    TouchPointWP::enqueuePartialsStyle();
    ?>
    <header class="archive-header has-text-align-center header-footer-group">
        <div class="archive-header-inner section-inner medium">
            <h1 class="archive-title page-title"><?php _ex("Events", "What Meetings should be called, plural.", 'TouchPoint-WP') ?></h1>
            <?php echo Meeting::filterShortcode(['type' => $postType]); ?>
            <?php if ($description) { ?>
                <div class="archive-description"><?php echo wp_kses_post(wpautop($description)); ?></div>
            <?php } ?>
        </div>
    </header>

    <main class="TouchPointWP-main">

    <?php

    if (!isset($_GET['page']) || !preg_match('/^(?P<mo>[0-9]{2})-(?P<yr>[0-9]{4})$/', $_GET['page'], $matches)) {
	    $matches = [
		    'mo' => null,
		    'yr' => null
	    ];
    }

    $grid = new CalendarGrid($wp_query, $matches['mo'], $matches['yr']);

	echo $grid->navBar(true);
    echo $grid;

    wp_reset_query();
    $taxQuery = [[]];
    $wp_query->tax_query->queries = $taxQuery;
    $wp_query->query_vars['tax_query'] = $taxQuery;
    $wp_query->is_tax = false;  // prevents templates from thinking this is a taxonomy archive
}
    ?>
</main><!-- .TouchPointWP-main -->
<?php

get_footer();