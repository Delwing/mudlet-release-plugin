<?php

/**
 * Plugin Name:       Mudlet release
 * Description:       Adds support for automatic Mudlet release posts creation
 * Version:           @version@
 * Author:            Piotr Wilczynski
 */

defined('ABSPATH') || exit;

require("vendor/autoload.php");

const GITHUB_API_URL = "https://api.github.com/repos/Mudlet/Mudlet/";

class MudletRelease
{

    const SHORTCODE = "MudletRelease";
    const RELEASE_CATEGORY = 173;

    private $parsedown;
    private $version;

    function __construct()
    {
        $this->version = '@version@';
        $this->parsedown = new Parsedown();

        add_shortcode(self::SHORTCODE, array($this, 'mudlet_release'));
        add_action('wp_ajax_post_newest_release', array($this, 'mudlet_post_release'));
        add_action('wp_ajax_nopriv_post_newest_release', array($this, 'mudlet_post_release'));
        add_action('save_post', array($this, 'invalidate_transient'), 10, 2);
        new MudletUpdateCheck($this->version);
    }

    function mudlet_release($atts, $content)
    {
        $transient_name = $this->get_transient_name($content);
        $body = get_transient($transient_name);
        if (!$body) {
            $result = GetHttpWrapper::get(GITHUB_API_URL . "releases/tags/Mudlet-$content");
            if ($result) {
                $body = $this->parsedown->text($result->body);
                set_transient($transient_name, $body, MONTH_IN_SECONDS);
            } else {
                $body = "Can't get releases post for $content";
                if (is_user_logged_in()) {
                    $body .= '<br>Message: ' . $result->message;
                }
            }
        }
        return $body;
    }

    function invalidate_transient($post_ID, WP_Post $post)
    {
        if (has_shortcode($post->post_content, self::SHORTCODE)) {
            $regex = '/\[' . self::SHORTCODE . '](.*?)\[\/' . self::SHORTCODE . ']/';
            if (preg_match($regex, $post->post_content, $matches)) {
                $version = $matches[1];
                delete_transient($this->get_transient_name($version));
                $this->mudlet_release(null, $version);
            }
        }
    }

    private function get_transient_name($version)
    {
        return "mudlet-release-$version";
    }

    private function has_release_post_already($version)
    {
        $args = array(
            'meta_key' => 'release-post',
            'value' => $version,
        );
        $query = new WP_Query($args);
        return $query->have_posts();
    }

    function mudlet_post_release()
    {
        $result = $this->api->get('releases/latest')->decode_response();
        if ($result->tag_name) {
            $tag_name = preg_replace('/Mudlet\-/', '', $result->tag_name);
            if ($this->has_release_post_already($tag_name)) {
                die();
            }
            $languages = pll_languages_list();
            $translations = array();
            foreach ($languages as $code) {
                $release_category = pll_get_term(self::RELEASE_CATEGORY, $code);
                $post_id = wp_insert_post(array(
                    'post_title' => $result->name,
                    'post_content' => '[' . self::SHORTCODE . ']' . $tag_name . '[/' . self::SHORTCODE . ']',
                    'post_status' => 'draft',
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

class MudletUpdateCheck
{

    private $version;
    private $cache_key;

    function __construct($version)
    {
        $this->version = $version;
        $this->slug = plugin_basename(__DIR__);
        $this->cache_key = 'mudlet_release';
        add_filter('site_transient_update_plugins', array($this, 'push_update'));
        add_filter('plugins_api', array($this, 'info'), 20, 3);
    }

    public function request_info()
    {
        return GetHttpWrapper::get('https://github.com/Delwing/mudlet-release-plugin/releases/latest/download/info.json', $this->cache_key);
    }

    function info($res, $action, $args)
    {

        if ('plugin_information' !== $action) {
            return $res;
        }


        if ($this->slug !== $args->slug) {
            return $res;
        }

        $remote = $this->request_info();
        if (!$remote) {
            return $res;
        }

        $res = new stdClass();
        $res->name = $remote->name;
        $res->plugin = plugin_basename(__FILE__);
        $res->slug = $this->slug;
        $res->author = $remote->author;
        $res->author_profile = $remote->author_profile;
        $res->version = $remote->version;
        //$res->tested = $remote->tested;
        $res->requires = $remote->requires;
        $res->requires_php = $remote->requires_php;
        $res->download_link = $remote->download_url;
        $res->trunk = $remote->download_url;
        $res->last_updated = $remote->last_updated;
        $res->sections = array(
            'description' => $remote->sections->description
        );

        return $res;
    }


    function push_update($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = $this->request_info();

        $res = new stdClass();
        $res->slug = $remote->slug;
        $res->plugin = plugin_basename(__FILE__);
        $res->package = $remote->download_url;

        if ($remote && version_compare($this->version, $remote->version, '<')) {
            $res->new_version = $remote->version;
            $transient->response[$res->plugin] = $res;
            $transient->checked[$res->plugin] = $remote->version;
        } else {
            $res->new_version = $this->version;
            $transient->no_update[$res->plugin] = $res;
        }

        return $transient;
    }
}

class GetHttpWrapper
{

    private $response;

    function __construct($url, $transient = false)
    {
        $this->response = $transient ? get_transient($transient) : false;
        if (!$this->response) {
            $remote = wp_remote_get(
                $url,
                array(
                    'timeout' => 10,
                    'headers' => array(
                        'Accept' => 'application/json'
                    )
                )
            );
            if ($this->is_ok($remote)) {
                $this->response = json_decode(wp_remote_retrieve_body($remote));
                if ($transient && $this->response) {
                    set_transient($transient, $this->response, DAY_IN_SECONDS);
                }
            }
        }
    }

    static function get($url, $transient = false)
    {
        $wrapper = new GetHttpWrapper($url, $transient);
        return $wrapper->get_body();
    }

    private function is_ok($remote)
    {
        return !is_wp_error($remote) && 200 == wp_remote_retrieve_response_code($remote) && !empty(wp_remote_retrieve_body($remote));
    }

    function get_body()
    {
        return $this->response;
    }
}

new MudletRelease();
