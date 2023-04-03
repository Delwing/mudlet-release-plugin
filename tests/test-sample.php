<?php
/**
 * Class SampleTest
 *
 * @package Mudlet_Release
 */

/**
 * Sample test case.
 */
class SampleTest extends WP_UnitTestCase {

	
	public function test_sample() {
		$id = wp_insert_post(array(
			'post_content' => "TEST"
		));

		$post = get_post($id); 
		
		$this->assertSame($post->post_content, "TEST");
	}
}
