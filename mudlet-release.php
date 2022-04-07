<?php

/**
 * Plugin Name:       Mudlet releasees
 * Description:       Adds support for automatic Mudlet release posts creation
 * Version:           1.0.0
 * Author:            Piotr Wilczynski
 */

require("vendor/autoload.php");

const GITHUB_API_URL = "https://api.github.com/repos/Mudlet/Mudlet/";

class MudletRelease {

    const SHORTCODE = "MudletRelease";
    const RELEASE_CATEGORY = 173;

    private $api;
    private $parsedown;

    function __construct() {
        $this->api = new RestClient([
            'base_url' => GITHUB_API_URL
        ]);
        $this->parsedown = new Parsedown();

        add_shortcode( self::SHORTCODE, array($this, 'mudlet_release'));
        add_action( 'wp_ajax_post_newest_release', array($this, 'mudlet_post_release'));  
        add_action( 'wp_ajax_nopriv_post_newest_release', array($this, 'mudlet_post_release')); 
        add_action( 'save_post', array($this, 'invalidate_transient'), 10, 2);
    }

    function mudlet_release( $atts, $content ) {
        $transient_name = $this->get_transient_name($content);
        $body = get_transient($transient_name);
        if (true || !$body) {
            $response = $this->api->get("releases/tags/Mudlet-$content");
            $result = $response->decode_response();
            if($response->info->http_code == 200) {
               $body = $this->parsedown->text($result->body);
               set_transient($transient_name, $body, MONTH_IN_SECONDS);
            } else {
                $body = "Can't get releases post for $content";
                if(is_user_logged_in()) {
                    $body .= '<br>Message: ' . $result->message;
                }
            }
        }
        return $body;
    }

    function invalidate_transient( $post_ID, WP_Post $post ) {
        if (has_shortcode($post->post_content, self::SHORTCODE)) {
            $regex = '/\[' . self::SHORTCODE . '](.*?)\[\/' . self::SHORTCODE . ']/';
            if( preg_match( $regex, $post->post_content, $matches ) ) {
                $version = $matches[1];
                delete_transient($this->get_transient_name($version));
                $this->mudlet_release(null, $version);
            }
        }
    }

    private function get_transient_name($version) {
        return "mudlet-release-$version";
    }

    private function has_release_post_already($version) {
        $args = array(
            'meta_key' => 'release-post',
            'value' => $version,
         );
         $query = new WP_Query($args);
         return $query->have_posts();
    }

    function mudlet_post_release() {
        $result = $this->api->get('releases/latest')->decode_response();
        if ($result->tag_name) {
            $tag_name = preg_replace('/Mudlet\-/', '', $result->tag_name);
            if ($this->has_release_post_already($tag_name)) {
                die();
            }
            $default_language = pll_default_language();
            $languages = pll_languages_list();
            $translations = array();
            foreach($languages as $code) {
                $release_category = pll_get_term(self::RELEASE_CATEGORY, $code);
                $post_id = wp_insert_post(array(
                    'post_title' => $result->name,
                    'post_content' => '['. self::SHORTCODE .']' . $tag_name . '[/'. self::SHORTCODE .']',
                    'post_status' => 'publish',
                    'post_category' => array($release_category)
                ));
                add_post_meta($post_id, 'release-post', $tag_name, true);
                pll_set_post_language($post_id, $code);
                $translations[$code] = $post_id;
            }
            pll_save_post_translations($translations);
            set_transient($this->get_transient_name($tag_name), $this->parsedown->text($result->body), MONTH_IN_SECONDS);
            die();
        }
    }

}

new MudletRelease();