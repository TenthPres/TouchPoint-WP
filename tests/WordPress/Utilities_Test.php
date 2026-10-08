<?php
/**
 * Tests for the parts of Utilities that work with WordPress's database and media library
 *
 * @package TouchPointWP\Tests\WordPress
 */

namespace tp\TouchPointWP\Tests\WordPress;

use tp\TouchPointWP\Utilities;

/**
 * Test case for the helpers in Utilities that read and write posts and images.  The tests in tests/Unit cover the ones
 * that only work on values.
 *
 * Nothing here downloads an image, so the tests don't need the internet.
 *
 * @covers \tp\TouchPointWP\Utilities
 */
class Utilities_Test extends WPTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        // WordPress only sets an attachment as a featured image if it can make an image from it, which needs a file.
        // These tests don't have files.
        add_filter('wp_get_attachment_image_src', fn() => ['http://example.org/image.jpg', 10, 10, false]);
    }

    /**
     * An image in the media library.
     *
     * @param ?string $sourceUrl Where the image came from, which is how the plugin recognizes it later.
     *
     * @return int The attachment's ID.
     */
    private function makeImage(?string $sourceUrl = null): int
    {
        $id = self::factory()->attachment->create(['post_mime_type' => 'image/jpeg']);
        if ($sourceUrl !== null) {
            update_post_meta($id, '_source_url', $sourceUrl);
        }

        return $id;
    }

    private function makePost(): int
    {
        return self::factory()->post->create(['post_type' => 'page']);
    }

    ///////////////////////
    // forceSlugUpdate   //
    ///////////////////////

    public function test_forceSlugUpdate_storesTheSlugExactly(): void
    {
        $id = $this->makePost();

        // WordPress would add "-2" to a slug that's only a number.
        Utilities::forceSlugUpdate($id, '11');

        $this->assertSame('11', $this->storedSlug($id));
    }

    public function test_forceSlugUpdate_doesNotChangeOtherPosts(): void
    {
        $id    = $this->makePost();
        $other = self::factory()->post->create(['post_type' => 'page', 'post_name' => 'leave-me-alone']);

        Utilities::forceSlugUpdate($id, 'changed');

        $this->assertSame('leave-me-alone', $this->storedSlug($other));
    }

    /**
     * @group known-issue
     */
    public function test_forceSlugUpdate_isSeenByWordPressRightAway(): void
    {
        $id = $this->makePost();
        get_post($id);   // WordPress remembers the post.

        Utilities::forceSlugUpdate($id, 'new-slug');

        $this->assertSame('new-slug', get_post($id)->post_name);
        $this->assertSame('new-slug', get_post_field('post_name', $id));
    }

    //////////////////////////////////
    // getPostContentWithShortcode  //
    //////////////////////////////////

    public function test_getPostContentWithShortcode_findsPostsThatUseTheShortcode(): void
    {
        self::factory()->post->create(['post_content' => 'Before [tp_list type="x"] after']);
        self::factory()->post->create(['post_content' => 'No shortcode here']);
        self::factory()->post->create(['post_content' => '[tp_list]', 'post_status' => 'draft']);

        $content = array_map(fn($r) => $r->post_content, Utilities::getPostContentWithShortcode('[tp_list'));
        sort($content);

        $this->assertSame(['Before [tp_list type="x"] after', '[tp_list]'], $content);
    }

    public function test_getPostContentWithShortcode_ignoresRevisionsAndAttachments(): void
    {
        $post = self::factory()->post->create(['post_content' => 'Current [tp_list]']);
        self::factory()->post->create([
            'post_type'    => 'revision',
            'post_status'  => 'inherit',
            'post_parent'  => $post,
            'post_content' => 'Earlier [tp_list]',
        ]);

        $content = array_map(fn($r) => $r->post_content, Utilities::getPostContentWithShortcode('[tp_list'));

        $this->assertSame(['Current [tp_list]'], $content);
    }

    public function test_getPostContentWithShortcode_noneFoundIsAnEmptyList(): void
    {
        self::factory()->post->create(['post_content' => 'Nothing']);

        $this->assertSame([], Utilities::getPostContentWithShortcode('[tp_missing]'));
    }

    /**
     * @group known-issue
     */
    public function test_getPostContentWithShortcode_aQuotationMarkInTheSearchIsJustText(): void
    {
        self::factory()->post->create(['post_content' => "A [tp_it's] shortcode"]);
        self::factory()->post->create(['post_content' => 'Something else']);

        $content = array_map(fn($r) => $r->post_content, Utilities::getPostContentWithShortcode("[tp_it's]"));

        $this->assertSame(["A [tp_it's] shortcode"], $content);
    }

    /////////////////////////////
    // Featured image helpers  //
    /////////////////////////////

    public function test_ownThumbnailId_isThePostsOwnImage(): void
    {
        $post  = $this->makePost();
        $image = $this->makeImage();
        update_post_meta($post, '_thumbnail_id', $image);

        $this->assertSame($image, Utilities::ownThumbnailId($post));
    }

    public function test_ownThumbnailId_isZeroWithoutAnImage(): void
    {
        $this->assertSame(0, Utilities::ownThumbnailId($this->makePost()));
    }

    public function test_ownThumbnailId_ignoresAnImageThatWouldBeInheritedFromAnAncestor(): void
    {
        $parent = $this->makePost();
        $child  = self::factory()->post->create(['post_type' => 'page', 'post_parent' => $parent]);
        update_post_meta($parent, '_thumbnail_id', $this->makeImage());
        add_filter('post_thumbnail_id', fn($id, $post = null) => 12345, 10, 2);

        $this->assertSame(0, Utilities::ownThumbnailId($child));
    }

    public function test_deleteAttachmentIfUnused_deletesAnImageNothingUses(): void
    {
        $image = $this->makeImage();

        $this->assertTrue(Utilities::deleteAttachmentIfUnused($image));
        $this->assertNull(get_post($image));
    }

    public function test_deleteAttachmentIfUnused_keepsAnImageThatIsStillAFeaturedImage(): void
    {
        $image = $this->makeImage();
        $post  = $this->makePost();
        update_post_meta($post, '_thumbnail_id', $image);

        $this->assertFalse(Utilities::deleteAttachmentIfUnused($image));
        $this->assertNotNull(get_post($image));
    }

    public function test_deleteAttachmentIfUnused_deletesTheImageOnceNothingUsesItAnymore(): void
    {
        $image = $this->makeImage();
        $post  = $this->makePost();
        update_post_meta($post, '_thumbnail_id', $image);
        $this->assertFalse(Utilities::deleteAttachmentIfUnused($image));

        delete_post_meta($post, '_thumbnail_id');

        $this->assertTrue(Utilities::deleteAttachmentIfUnused($image));
    }

    public function test_deleteAttachmentIfUnused_explainsItselfWhenVerbose(): void
    {
        $image = $this->makeImage();
        $post  = $this->makePost();
        update_post_meta($post, '_thumbnail_id', $image);

        ob_start();
        Utilities::deleteAttachmentIfUnused($image, true);
        $output = ob_get_clean();

        $this->assertStringContainsString("is still used by Post $post", $output);
    }

    //////////////////////////////
    // updatePostImageFromUrl   //
    //////////////////////////////

    public function test_updatePostImageFromUrl_usesAnImageThatIsAlreadyInTheMediaLibrary(): void
    {
        $post  = $this->makePost();
        $image = $this->makeImage('https://example.org/photo.jpg');

        $result = Utilities::updatePostImageFromUrl($post, 'https://example.org/photo.jpg', 'Title');

        $this->assertSame($image, $result);
        $this->assertSame($image, Utilities::ownThumbnailId($post));
    }

    public function test_updatePostImageFromUrl_doesNothingWhenThePostAlreadyHasTheImage(): void
    {
        $post  = $this->makePost();
        $image = $this->makeImage('https://example.org/photo.jpg');
        update_post_meta($post, '_thumbnail_id', $image);

        $replaced = [];
        $result   = Utilities::updatePostImageFromUrl($post, 'https://example.org/photo.jpg', 'Title', false, $replaced);

        $this->assertSame($image, $result);
        $this->assertSame($image, Utilities::ownThumbnailId($post));
        $this->assertSame([], $replaced);
        $this->assertNotNull(get_post($image));
    }

    public function test_updatePostImageFromUrl_deletesTheImageItReplaces(): void
    {
        $post = $this->makePost();
        $old  = $this->makeImage('https://example.org/old.jpg');
        $new  = $this->makeImage('https://example.org/new.jpg');
        update_post_meta($post, '_thumbnail_id', $old);

        $result = Utilities::updatePostImageFromUrl($post, 'https://example.org/new.jpg', 'Title');

        $this->assertSame($new, $result);
        $this->assertSame($new, Utilities::ownThumbnailId($post));
        $this->assertNull(get_post($old));
    }

    public function test_updatePostImageFromUrl_canLeaveTheReplacedImageForTheCallerToDelete(): void
    {
        $post = $this->makePost();
        $old  = $this->makeImage('https://example.org/old.jpg');
        $this->makeImage('https://example.org/new.jpg');
        update_post_meta($post, '_thumbnail_id', $old);

        $replaced = [];
        Utilities::updatePostImageFromUrl($post, 'https://example.org/new.jpg', 'Title', false, $replaced);

        $this->assertSame([$old], $replaced);
        $this->assertNotNull(get_post($old), 'It is not deleted yet.');
    }

    public function test_updatePostImageFromUrl_aBlankAddressRemovesTheImage(): void
    {
        $post = $this->makePost();
        $old  = $this->makeImage('https://example.org/old.jpg');
        update_post_meta($post, '_thumbnail_id', $old);

        $result = Utilities::updatePostImageFromUrl($post, null, 'Title');

        $this->assertSame(0, $result);
        $this->assertSame(0, Utilities::ownThumbnailId($post));
        $this->assertNull(get_post($old));
    }

    public function test_updatePostImageFromUrl_aBlankAddressWithNoImageChangesNothing(): void
    {
        $post = $this->makePost();

        $this->assertSame(0, Utilities::updatePostImageFromUrl($post, '   ', 'Title'));
        $this->assertSame(0, Utilities::ownThumbnailId($post));
    }
}
