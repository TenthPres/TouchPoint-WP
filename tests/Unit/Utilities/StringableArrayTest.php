<?php
/**
 * Tests for the StringableArray class
 *
 * @package TouchPointWP\Tests\Unit\Utilities
 */

namespace tp\TouchPointWP\Tests\Unit\Utilities;

use tp\TouchPointWP\Utilities\StringableArray;
use tp\TouchPointWP\Tests\TestCase;

/**
 * Test case for the StringableArray class.
 *
 * @covers \tp\TouchPointWP\Utilities\StringableArray
 */
class StringableArrayTest extends TestCase
{
    /**
     * Test that StringableArray can be instantiated with default separator.
     */
    public function test_instantiation_with_default_separator(): void
    {
        $array = new StringableArray();
        
        $this->assertInstanceOf(StringableArray::class, $array);
        $this->assertSame(0, $array->count());
    }

    /**
     * Test that StringableArray can be instantiated with initial data.
     */
    public function test_instantiation_with_initial_data(): void
    {
        $data = ['apple', 'banana', 'cherry'];
        $array = new StringableArray($data);
        
        $this->assertSame(3, $array->count());
    }

    /**
     * Test count method returns correct count.
     */
    public function test_count_returns_correct_count(): void
    {
        $data = ['one', 'two', 'three'];
        $array = new StringableArray($data);
        
        $this->assertSame(3, $array->count());
        $this->assertCount(3, $array);
    }

    /**
     * Test append adds items to the end.
     */
    public function test_append_adds_items_to_end(): void
    {
        $array = new StringableArray(['first']);
        $array->append('second');
        $array->append('third');
        
        $this->assertSame(3, $array->count());
        $this->assertSame('first, second, third', $array->join(', '));
    }

    /**
     * Test prepend adds items to the beginning.
     */
    public function test_prepend_adds_items_to_beginning(): void
    {
        $array = new StringableArray(['second']);
        $array->prepend('first');
        
        $this->assertSame(2, $array->count());
        $this->assertSame('first, second', $array->join(', '));
    }

    /**
     * Test prepend with key.
     */
    public function test_prepend_with_key(): void
    {
        $array = new StringableArray(['b' => 'second']);
        $array->prepend('first', 'a');
        
        $this->assertSame(2, $array->count());
        $this->assertSame('first, second', $array->join(', '));
    }

    /**
     * Test contains returns true for existing items.
     */
    public function test_contains_returns_true_for_existing_items(): void
    {
        $array = new StringableArray(['apple', 'banana', 'cherry']);
        
        $this->assertTrue($array->contains('apple'));
        $this->assertTrue($array->contains('banana'));
        $this->assertTrue($array->contains('cherry'));
    }

    /**
     * Test contains returns false for non-existing items.
     */
    public function test_contains_returns_false_for_non_existing_items(): void
    {
        $array = new StringableArray(['apple', 'banana', 'cherry']);
        
        $this->assertFalse($array->contains('orange'));
        $this->assertFalse($array->contains('grape'));
    }

    /**
     * Test join with default separator.
     */
    public function test_join_with_default_separator(): void
    {
        $array = new StringableArray(['apple', 'banana', 'cherry']);
        
        $this->assertSame("apple\nbanana\ncherry", $array->join());
    }

    /**
     * Test join with custom separator.
     */
    public function test_join_with_custom_separator(): void
    {
        $array = new StringableArray(['apple', 'banana', 'cherry']);
        
        $this->assertSame('apple | banana | cherry', $array->join(' | '));
    }

    /**
     * Test single item array.
     */
    public function test_single_item_array(): void
    {
        $array = new StringableArray(['only']);
        
        $this->assertSame('only', $array->join());
        $this->assertSame(1, $array->count());
    }

    /**
     * Test array with numeric values.
     */
    public function test_array_with_numeric_values(): void
    {
        $array = new StringableArray([1, 2, 3, 4, 5]);
        
        $this->assertSame('1, 2, 3, 4, 5', $array->join(', '));
    }

    /**
     * Test array operations don't affect original separator.
     */
    public function test_operations_preserve_original_separator(): void
    {
        $array = new StringableArray(['a', 'b']);

        $this->assertSame('a | b', $array->join(' | '));
        $this->assertSame("a\nb", (string)$array);
    }

    /**
     * Test StringableArray extends ArrayObject.
     */
    public function test_extends_array_object(): void
    {
        $array = new StringableArray();
        
        $this->assertInstanceOf(\ArrayObject::class, $array);
    }

    /**
     * Test array access operations.
     */
    public function test_array_access_operations(): void
    {
        $array = new StringableArray(['a', 'b', 'c']);
        
        // Access
        $this->assertSame('a', $array[0]);
        $this->assertSame('b', $array[1]);
        $this->assertSame('c', $array[2]);
        
        // Modification
        $array[1] = 'modified';
        $this->assertSame('a, modified, c', $array->join(', '));
    }
}
