<?php
/*
  Plugin Name: Brafton Feeds
  Plugin URI: https://github.com/markparolisi/brafton-feeds
  Description: Grabs Brafton RSS feeds and creates posts
  Author: markparolisi
  Version: 1.1
  Author URI: http://markparolisi.com/
 */

if (!class_exists('Brafton_Feeds')) {

    class Brafton_Feeds {

        private static $instance;
        const FEEDS = 'brafton_feeds';
        const AUTHOR = 'brafton_author';
        const URL = 'brafton_url';
        const META_KEY = 'brafton_guid';
        public $feeds = array();
        public $author;
        public $url;
        public $rss;

        private function __construct() {
            $this->setup();
        }

        public static function init() {
            if (!isset(self::$instance)) {
                $className = __CLASS__;
                self::$instance = new $className;
            }
            return self::$instance;
        }

        private function setup() {
            register_activation_hook(__FILE__, array($this, 'activation'));
            add_action('brafton_schedule_event', array($this, 'brafton_post'));
            add_action('admin_menu', array($this, 'add_menu'));
            register_deactivation_hook(__FILE__, array($this, 'deactivation'));
            $this->get_options();
        }

        /**
         * Schedule RSS fetching and set default values on activation
         */
        public function activation() {
            wp_schedule_event(time(), 'daily', 'brafton_schedule_event', array());
            add_option(self::FEEDS, '');
            add_option(self::AUTHOR, '1');
            add_option(self::URL, 'http://domain.com/feed.xml');
        }

        /**
         * Create admin panel and new category for our new posts
         */
        public function add_menu() {
            add_submenu_page('options-general.php', 'Brafton Feeds', 'Brafton Feeds', 'manage_options', __FILE__, array($this, 'admin_page'));
        }

        public function get_options($options = array('feeds', 'author', 'url')) {
            foreach ($options as $option) {
                $value = get_option('brafton_' . $option);
                if ($value)
                    $this->$option = $value;
            }
        }

        /**
         * Build the admin page
         */
        public function admin_page() {
            if (isset($_POST["update"])) {
                update_option('brafton_author', $_POST["user"]);
                update_option('brafton_url', esc_url_raw($_POST["brafton_url"]));
                if (isset($_POST["brafton_cat"])) {
                    $feeds = array();
                    foreach ($_POST["brafton_cat"] as $k => $v) {
                        if ($v)
                            $feeds[] = array('brafton_cat' => $v, 'wp_cat' => $_POST["wp_cat"][$k]);
                    }
                }
                update_option('brafton_feeds', $feeds);
                $this->get_options();
                echo '<div class="updated"><p><strong>Settings Updated!</strong></p></div>';
            }
            if (isset($_POST["refresh"])) {
                self::init()->brafton_post();
            }
            ?>
            <script>
                jQuery(document).ready(function($){
                    $('.delete').click(function(){
                        $(this).parent('fieldset').remove();
                    })
                });
            </script>
            <div class="wrap" id="brafton-admin">
                <h2>Configure your Brafton Feed options</h2>
                <form method="POST" action="">
                    <label>Assign to which author?</label>
                    <?php wp_dropdown_users('selected=' . $this->author); ?>
                    <br />
                    <label>Brafton Feed URL</label>
                    <input type="text" name="brafton_url" id="brafton_url" size="60" value="<?php echo $this->url; ?>" />
                    <h3>Add new category definition</h3>
                    <label>Brafton Category ID</label>
                    <input type="text" name="brafton_cat[]" size="10">
                    <label>Assigned to category</label>
                    <?php wp_dropdown_categories('name=wp_cat[]&hide_empty=0'); ?>
                    <?php if ($this->feeds) { ?>
                        <h3>Manage Feeds</h3>
                        <?php foreach ($this->feeds as $feed) { ?>
                            <fieldset>
                                <label>Brafton Category ID</label>
                                <input type="text" name="brafton_cat[]" size="10" value="<?php echo $feed["brafton_cat"]; ?>">
                                <label>Assigned to category</label>
                                <?php wp_dropdown_categories("name=wp_cat[]&hide_empty=0&selected=" . $feed['wp_cat']); ?>
                                <a class="delete">Delete</a>
                            </fieldset>
                        <?php } ?>
                    <?php } ?>
                    <div class="clear"></div>
                    <input type="submit" name="update" value="Update Options" class="button-primary" id="brafton-submit" />
                </form>
                <form method="POST">
                    <input type="submit" name="refresh" value="Refresh Feed" class="button-secondary" id="brafton-submit" />
                </form>
            </div>
            <?php
        }

        /**
         * Get all posts with the Brafton GUID set 
         * @global type $wpdb
         * @return array of the GUIDs
         */
        private function get_existing_guids() {
            global $wpdb;
            $guids = array();
            $guid_metas = $wpdb->get_results("SELECT * FROM $wpdb->postmeta WHERE meta_key ='" . self::META_KEY . "'");
            foreach ($guid_metas as $guid_meta) {
                $guids[] = $guid_meta->meta_value;
            }
            return $guids;
        }

        /**
         * Get the matching WP category for the Brafton ID
         * @param type $brafton_cat
         * @return int|bool The category ID attached to the Brafton ID or false
         */
        private function get_category_match($brafton_id) {
            foreach ($this->feeds as $feed) {
                if (in_array($brafton_id, $feed)) {
                    return $feed['wp_cat'];
                }
            }
            return false;
        }

        /**
         * Set post values from Brafton RSS Object
         * @param object $article
         * @param int $wp_cat
         * @return array All of the post data in an associative array
         */
        private function set_post_data($article, $wp_cat) {
            $post_data = array();
            $post_data['post_title'] = $article->Heading;
            $post_data['post_content'] = $article->Contents;
            $post_data['post_excerpt'] = $article->extract;
            $post_data['post_status'] = 'publish';
            $post_data['post_author'] = $this->author;
            $post_data['post_category'] = $wp_cat;
            return $post_data;
        }

        /**
         * Get the post ID from a Brafton GUID
         * @global object $wpdb
         * @param int $guid
         * @return int|bool Return the post ID or false 
         */
        private function get_existing_post_id($guid) {
            global $wpdb;
            $guid_metas = $wpdb->get_results("SELECT * FROM $wpdb->postmeta WHERE meta_value = '$guid'");
            if ($guid_metas) {
                foreach ($guid_metas as $guid_meta) {
                    return $update_post_id = $guid_meta->post_id;
                }
            } else {
                return false;
            }
        }

        /**
         * Check if the original post time matches the modified time.
         * @param int $post_id
         * @return bool 
         */
        public function was_modified($post_id) {
            if (get_post_time('U', false, $post_id) != get_post_modified_time('U', false, $post_id)) {
                return true;
            } else {
                return false;
            }
        }

        /**
         * Just sets the feed object as a class attribute
         */
        public function set_brafton_feed() {
            return $this->rss = new SimpleXMLElement($this->url, NULL, TRUE);
        }

        /**
         * Grab the Brafton Feed, loop through the articles while adding 
         * new ones and updating existing ones
         * @return int|bool ID of the post or false 
         */
        public function brafton_post() {
            if (!empty($this->feeds)) {
                if (!is_object($this->rss))
                    $this->set_brafton_feed();
                $brafton_rss = $this->rss;
                if (is_object($brafton_rss)) {
                    foreach ($brafton_rss->Article as $article) {
                        $atts = $article->attributes();
                        $guid = strval($atts->ID);
                        $brafton_cat_atts = $article->Categories->Category->attributes();
                        $brafton_cat = strval($brafton_cat_atts->ID);
                        $wp_cat = array($this->get_category_match($brafton_cat));
                        $post_data = $this->set_post_data($article, $wp_cat);
                        if (in_array($guid, $this->get_existing_guids(), false)) {
                            $post_data['ID'] = $this->get_existing_post_id($guid);
                            if (false === $this->was_modified($post_data['ID']))
                                return wp_update_post($post_data);
                        } else {
                            $postID = wp_insert_post($post_data);
                            //adding a unique custom meta field to check against duplicates
                            add_post_meta($postID, self::META_KEY, $guid);
                            return $postID;
                        }
                    }
                }
            }
        }

        /**
         * Stop the automated RSS fetching on deactivation
         */
        public function deactivation() {
            wp_clear_scheduled_hook('brafton_schedule_event');
        }

    }

// end Brafton Feeds class

    Brafton_Feeds::init();
}

