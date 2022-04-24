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
        new MudletUpdateCheck($this->version);
    }

    function mudlet_release($atts, $content)
    {
        $transient_name = $this->get_transient_name($content);
        $body = get_transient($transient_name);
        if ($body) {
            $body = base64_decode($body);
        } else {
            $result = GetHttpWrapper::get(GITHUB_API_URL . "releases/$content");
            if ($result) {
                $body = $this->parsedown->text($result->body);
                set_transient($transient_name, base64_encode($body));
            } else {
                $body = "Can't get releases post for $content<br>You can view it on <a href=\"https://github.com/Mudlet/Mudlet/releases/$content\">Github</a>.";
            }
        }
        return $body;
    }

    private function get_transient_name($id)
    {
        return sanitize_title("mudlet-release-$id");
    }

    private function get_release_posts($id)
    {
        $args = array(
            'meta_query' => array(
                array(
                    'key' => 'release-post',
                    'value' => $id
                )
            ),
            'post_status' => 'any'
        );
        return get_posts($args);
    }

    function mudlet_post_release()
    {
        if (isset($_POST['payload'])) {
            $payload = json_decode(stripslashes($_POST['payload']));
            if (isset($payload->release)) {
                $result = $payload->release;
            } else {
                wp_die('Releasse hook not applicable.');
            }
        } else {
            $result = GetHttpWrapper::get('https://api.github.com/repos/Mudlet/Mudlet/releases/latest');
        }

        if ($result->id) {
            $release_posts = $this->get_release_posts($result->id);
            $languages = pll_languages_list();
            if (count($release_posts) == 0) {
                $translations = array();
                foreach ($languages as $code) {
                    $release_category = pll_get_term(self::RELEASE_CATEGORY, $code);
                    $post_id = wp_insert_post(array(
                        'post_title' => $result->name,
                        'post_content' => '[' . self::SHORTCODE . ']' . $result->id . '[/' . self::SHORTCODE . ']',
                        'post_status' => 'draft',// $result->draft ? 'draft' : 'publish',
                        'post_category' => array($release_category)
                    ));
                    add_post_meta($post_id, 'release-post', $result->id, true);
                    pll_set_post_language($post_id, $code);
                    $translations[$code] = $post_id;
                }
                pll_save_post_translations($translations);
            } else {
                foreach ($languages as $code) {
                    $post_in_lang = pll_get_post($release_posts[0]->ID, $code);
                    wp_update_post(array(
                        'ID' => $post_in_lang,
                        'post_title' => $result->name,
                        'post_status' => 'draft',// $result->draft ? 'draft' : 'publish',
                    ));
                }
                foreach ($release_posts as $post) {
                }
            }
            set_transient($this->get_transient_name($result->id), base64_encode($this->parsedown->text($result->body)));
            wp_die();
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
    public $error;

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
                    set_transient($transient, $this->response, HOUR_IN_SECONDS);
                }
            } else {
                error_log($remote->get_error_message());
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
