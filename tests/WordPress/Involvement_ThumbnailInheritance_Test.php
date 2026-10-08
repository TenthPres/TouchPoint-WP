<?php
/**
 * Tests for Involvement and Meeting posts using their ancestors' featured images
 *
 * @package TouchPointWP\Tests\WordPress
 */

namespace tp\TouchPointWP\Tests\WordPress;

use tp\TouchPointWP\Involvement;
use tp\TouchPointWP\Involvement_PostTypeSettings;
use tp\TouchPointWP\Meeting;

/**
 * Test case for Involvement::filterThumbnailId(): a post that has no featured image of its own uses the image of its
 * nearest ancestor that has one, unless the post is archived, isn't one of the plugin's, or a filter says not to.
 *
 * The current time is Tuesday, 2026-03-10 at noon, and meetings that ended more than a week before that are archived.
 *
 * @covers \tp\TouchPointWP\Involvement
 */
class Involvement_ThumbnailInheritance_Test extends WPTestCase
{
    private const POST_TYPE = 'tp_inv_thumbtest';

    public function set_up(): void
    {
        parent::set_up();

        register_post_type(self::POST_TYPE, ['public' => true, 'hierarchical' => true]);
        self::setStatic(
            Involvement_PostTypeSettings::class,
            'settings',
            [new Involvement_PostTypeSettings((object)['postType' => 'inv_thumbtest'])]
        );

        $this->setSetting('mc_archive_days', 7);
        $this->setNow('2026-03-10 12:00');
    }

    /**
     * An image in the media library.
     *
     * @return int
     */
    private function makeImage(): int
    {
        return self::factory()->attachment->create(['post_mime_type' => 'image/jpeg']);
    }

    /**
     * Make a post, with its own image if there is one.
     *
     * @param int    $parentId
     * @param int    $imageId
     * @param string $postType
     *
     * @return int
     */
    private function makePost(int $parentId = 0, int $imageId = 0, string $postType = self::POST_TYPE): int
    {
        $id = self::factory()->post->create(['post_type' => $postType, 'post_parent' => $parentId]);
        if ($imageId > 0) {
            update_post_meta($id, '_thumbnail_id', $imageId);
        }

        return $id;
    }

    /**
     * What the filter says the post's image is.
     *
     * @param int $postId
     *
     * @return int|false
     */
    private function imageOf(int $postId): int|false
    {
        return Involvement::filterThumbnailId(0, $postId);
    }

    /**
     * Give a post the dates of a meeting.
     *
     * @param int    $postId
     * @param string $start
     * @param string $end
     */
    private function setMeetingTimes(int $postId, string $start, string $end): void
    {
        update_post_meta($postId, Meeting::MEETING_START_META_KEY, strtotime("$start UTC"));
        update_post_meta($postId, Meeting::MEETING_END_META_KEY, strtotime("$end UTC"));
    }

    public function test_aPostWithItsOwnImageKeepsIt(): void
    {
        $own    = $this->makeImage();
        $parent = $this->makePost(0, $this->makeImage());

        $this->assertSame($own, Involvement::filterThumbnailId($own, $this->makePost($parent, $own)));
    }

    public function test_aPostUsesItsParentsImage(): void
    {
        $image  = $this->makeImage();
        $parent = $this->makePost(0, $image);
        $child  = $this->makePost($parent);

        $this->assertSame($image, $this->imageOf($child));
    }

    public function test_aPostUsesTheImageOfTheNearestAncestorThatHasOne(): void
    {
        $far         = $this->makeImage();
        $near        = $this->makeImage();
        $grandparent = $this->makePost(0, $far);
        $parent      = $this->makePost($grandparent);
        $child       = $this->makePost($parent);

        $this->assertSame($far, $this->imageOf($child), 'Through a parent that has no image.');

        update_post_meta($parent, '_thumbnail_id', $near);

        $this->assertSame($near, $this->imageOf($child));
    }

    public function test_aPostWithNoImageAnywhereHasNone(): void
    {
        $child = $this->makePost($this->makePost($this->makePost()));

        $this->assertSame(0, $this->imageOf($child));
    }

    public function test_aTopLevelPostHasNothingToInherit(): void
    {
        $this->assertSame(0, $this->imageOf($this->makePost()));
    }

    public function test_postsOfOtherTypesDoNotInherit(): void
    {
        $image = $this->makeImage();
        $page  = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($page, '_thumbnail_id', $image);
        $child = self::factory()->post->create(['post_type' => 'page', 'post_parent' => $page]);

        $this->assertSame(0, $this->imageOf($child));
    }

    public function test_aMeetingThatIsUpcomingInherits(): void
    {
        $image = $this->makeImage();
        $child = $this->makePost($this->makePost(0, $image));
        $this->setMeetingTimes($child, '2026-04-10 09:00', '2026-04-10 10:00');

        $this->assertSame($image, $this->imageOf($child));
    }

    public function test_aMeetingThatIsArchivedDoesNotInherit(): void
    {
        // So that an archived meeting doesn't change appearance when the image of its involvement does.
        $image = $this->makeImage();
        $child = $this->makePost($this->makePost(0, $image));
        $this->setMeetingTimes($child, '2026-02-10 09:00', '2026-02-10 10:00');

        $this->assertSame(0, $this->imageOf($child));
    }

    public function test_aMeetingThatEndedRecentlyIsNotArchivedYet(): void
    {
        $image = $this->makeImage();
        $child = $this->makePost($this->makePost(0, $image));
        $this->setMeetingTimes($child, '2026-03-05 09:00', '2026-03-05 10:00');   // Five days ago.

        $this->assertSame($image, $this->imageOf($child));
    }

    public function test_aMeetingWithNoEndIsArchivedByItsStart(): void
    {
        $image = $this->makeImage();
        $child = $this->makePost($this->makePost(0, $image));
        update_post_meta($child, Meeting::MEETING_START_META_KEY, strtotime('2026-02-10 09:00 UTC'));

        $this->assertSame(0, $this->imageOf($child));
    }

    public function test_aFilterCanStopAPostFromInheriting(): void
    {
        $image  = $this->makeImage();
        $parent = $this->makePost(0, $image);
        $child  = $this->makePost($parent);

        $received = null;
        add_filter('tp_inherit_thumbnail', function ($inherit, $post, $ancestorId, $ancestorImage) use (&$received) {
            $received = [$post->ID, $ancestorId, $ancestorImage];
            return false;
        }, 10, 4);

        $this->assertSame(0, $this->imageOf($child));
        $this->assertSame([$child, $parent, $image], $received);
    }

    public function test_aFilterCanBeSelective(): void
    {
        $image   = $this->makeImage();
        $parent  = $this->makePost(0, $image);
        $keeps   = $this->makePost($parent);
        $doesNot = $this->makePost($parent);
        add_filter('tp_inherit_thumbnail', fn($inherit, $post) => $post->ID !== $doesNot, 10, 2);

        $this->assertSame($image, $this->imageOf($keeps));
        $this->assertSame(0, $this->imageOf($doesNot));
    }

    public function test_theImageIsInheritedByWordPressesOwnFunction(): void
    {
        $image = $this->makeImage();
        $child = $this->makePost($this->makePost(0, $image));
        add_filter('post_thumbnail_id', [Involvement::class, 'filterThumbnailId'], 10, 2);

        $this->assertSame($image, intval(get_post_thumbnail_id($child)));
    }
}
