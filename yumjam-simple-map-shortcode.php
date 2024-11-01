<?php
/**
 * YumJam Simple Map Shortcode
 *
 * @package     YumJamSimpleMap
 * @author      Matt Burnett
 * @copyright   2018 YumJam
 * @license     GPL-2.0+
 *
 * @wordpress-plugin
 * Plugin Name: YumJam Simple Map Shortcode
 * Plugin URI: http://www.yumjam.co.uk/yumjam-wordpress-plugins/yumjam-simple-map-shortcode/
 * Description: Display a simple Maps via a WordPress shortcode posts, pages or widgets.
 * Version: 1.0.3
 * Author: YumJam
 * Author URI: http://www.yumjam.co.uk
 * Text Domain: yumjam-simple-map-shortcode
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Tags: comments
 * Requires at least: 4.0
 * Tested up to: 5.0.0
 * Stable tag: 5.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!class_exists('YumJamSimpleMap')) {

    class YumJamSimpleMap {

        public function __construct() {
            define('YJSM_PLUGIN_PATH', __DIR__);
            define('YJSM_PLUGIN_URL', plugin_dir_url(__FILE__));

            //Frontend Scripts
            add_action('wp_enqueue_scripts', array($this, 'sm_frontend_scripts'));

            //Admin 
            if (is_admin()) {
                $this->admin_hooks();
            }

            //install and uninstall
            register_activation_hook(__FILE__, array($this, 'sm_activate'));
            register_deactivation_hook(__FILE__, array($this, 'sm_deactive'));

            //Do plugin actions
            add_action('init', array($this, 'hooks'));
        }

        public function admin_hooks() {
            if (is_admin()) {
                add_action('admin_init', array($this, 'sm_admin_init'));
                add_action('admin_enqueue_scripts', array($this, 'sm_backend_scripts'));
                add_action('admin_menu', array($this, 'sm_register_menu_page'));
            }
        }

        public function hooks() {
            if (get_option('sm_enable') == 1) {
                add_shortcode('yj_map', array($this, 'yj_map_shortcode'));
            }
        }

        /**
         * Displays the map shortcode
         * @param type $atts
         * @return type
         */
        public function yj_map_shortcode($atts) {

            $atts = shortcode_atts(
                array(
                    'address' => false,
                    'width' => '100%',
                    'height' => '400px',
                    'enablescrollwheel' => 'true',
                    'zoom' => 15,
                    'disablecontrols' => 'false',
                    'maptype' => 'roadmap', // roadmap, satellite, hybrid, terrain 
                    'infowindow' => '', 
                    'infoopen' => 'click'
                ), $atts
            );

            $address = $atts['address'];

            if ($address) {
                $coords = $this->yj_get_coords($address, false);
                
                if (!is_array($coords)) {
                    return;
                }

                // unique map ID and js function for multiple maps per page
                $map_id = uniqid('yjm_'); 

                ob_start();
                ?>
                <div class="yj_map_canvas" id="<?php echo esc_attr($map_id); ?>" style="height: <?php echo esc_attr($atts['height']); ?>; width: <?php echo esc_attr($atts['width']); ?>"></div>
                <script type="text/javascript">
                    var map_<?php echo $map_id; ?>;

                    function yj_domap_<?php echo $map_id; ?>() {
                        var location = new google.maps.LatLng("<?php echo $coords['lat']; ?>", "<?php echo $coords['lng']; ?>");
                        var map_options = {
                            zoom: <?php echo $atts['zoom']; ?>,
                            center: location,
                            scrollwheel: <?php echo 'true' === strtolower($atts['enablescrollwheel']) ? '1' : '0'; ?>,
                            disableDefaultUI: <?php echo 'true' === strtolower($atts['disablecontrols']) ? '1' : '0'; ?>,
                            mapTypeId: google.maps.MapTypeId.ROADMAP
                        }

                        map_<?php echo $map_id; ?> = new google.maps.Map(document.getElementById("<?php echo $map_id; ?>"), map_options);

<?php
                        if (!empty($atts['infowindow'])) {
                            ?>
                            var contentString = '<?php echo $atts['infowindow']; ?>';
                            var infowindow = new google.maps.InfoWindow({
                              content: contentString
                            });                            
                            <?php
                        }
?>

                        var marker = new google.maps.Marker({
                            position: location,
                            map: map_<?php echo $map_id; ?>
                        });
                        
<?php
                        if (!empty($atts['infowindow'])) {
                            if (!empty($atts['infoopen']) && $atts['infoopen'] == 'load') {
                                ?>
                                infowindow.open(map_<?php echo $map_id; ?>, marker);
                                <?php                                
                            } else {
                                ?>
                                marker.addListener('click', function() {
                                    infowindow.open(map_<?php echo $map_id; ?>, marker);
                                });
                                <?php
                            }
                        }
?>
                    }

                    yj_domap_<?php echo $map_id; ?>();
                </script>
                <?php
                return ob_get_clean();
            } else {
                return __('Google Maps API not loaded', 'yumjam-simple-map-shortcode');
            }
        }

        /**
         * Get coordinates for an address using Google Maps API
         * 
         * @param type $address
         * @param type $force_refresh
         * @return type
         */
        public function yj_get_coords($address, $force_refresh = false) {
            $apikey = get_option('sm_google_maps_api_key');

            //check for cached result
            $coords = get_transient(hash('md5', $address));

            if ($force_refresh || $coords === false) {

                //allow hooking of url variables
                $args = apply_filters('yj_map_query_args', array('key' => $apikey, 'address' => urlencode($address), 'sensor' => 'false'));
                
                $url = add_query_arg($args, 'https://maps.googleapis.com/maps/api/geocode/json');
                $response = wp_remote_get($url);

                if (is_wp_error($response)) { return; }
                $data = wp_remote_retrieve_body($response);
                if (is_wp_error($data)) { return; }

                if ($response['response']['code'] == 200) {
                    $data = json_decode($data);
                    switch ($data->status) {
                        case 'OK':
                            $coords = $data->results[0]->geometry->location;
                            $cache_value = array('lat' => $coords->lat, 'lng' => $coords->lng, 'address' => (string) $data->results[0]->formatted_address);

                            //cache result to reduce api calls
                            set_transient(hash('md5', $address), $cache_value, 7776000); //~ 90 days
                            $data = $cache_value;
                            break;
                        case 'ZERO_RESULTS':
                            return __('location not found for this address', 'yumjam-simple-map-shortcode');
                            break;
                        case 'INVALID_REQUEST':
                            return __('Invalid address request', 'yumjam-simple-map-shortcode');
                            break;
                        default:
                            return __('Something went wrong, check your shortcode settings.', 'yumjam-simple-map-shortcode');
                    }
                } else {
                    return __('Unable to use Google API services', 'yumjam-simple-map-shortcode');
                }
            } else {
                $data = $coords;
            }

            return $data;
        }

        /**
         * Doing admin stuff - initialise
         */
        public function sm_admin_init() {
            $this->configure_settings_options();
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));
        }

        /**
         * Add extra links to plugins page, by active/deactivate link
         * @param type $links
         * @return string
         */
        public function plugin_action_links($links) {
            $links[] = '<a href="' . esc_url(get_admin_url(null, 'options-general.php?page=sm_options')) . '">Settings</a>';
            $links[] = '<a href="https://www.yumjam.co.uk" target="_blank">More by YumJam</a>';

            return $links;
        }

        public function configure_settings_options() {
            $section = array('id' => 'sm_options_group1', 'name' => 'Configurable Settings');

            /* Array of Setting to add to the settings page */
            $settings = array(
                array('id' => 'sm_enable', 'type' => 'checkbox', 'name' => 'Enable YumJam Simple', 'desc' => 'Enable/Disable Plugin'),
                array('id' => 'sm_google_maps_api_key', 'type' => 'textbox', 'name' => 'Google Maps API Key', 'desc' => '<a href="https://developers.google.com/maps/documentation/javascript/get-api-key" ><i>Get an API Key</i> </a>'),
                array('id' => 'sm_load_google_script', 'type' => 'checkbox', 'name' => 'Enqueue Google Maps API Script', 'desc' => '*Required (disable this if already loaded by another plugin)'),
            );

            add_settings_section($section['id'], $section['name'], '', 'sm_options');
            foreach ($settings as $s) {
                register_setting($section['id'], $s['id']);
                add_settings_field(
                        $s['id'], 
                        $s['name'], 
                        array($this, 'sm_output_settings_field'), 
                        'sm_options', 
                        $section['id'], 
                        array('id' => $s['id'], 'type' => $s['type'], 'values' => (!empty($s['values']) ? $s['values'] : false), 'desc' => $s['desc'])
                    );
            }
        }

        /**
         * Output the HTML to genterate setting/options input boxes
         * @param type $args
         */
        public function sm_output_settings_field($args) {
            if (!empty($args['values'])) {
                if ($args['values'] == 'callback') {
                    $values = call_user_func(array($this, $args['id'] . '_values'));
                } else if (is_array($args['values'])) {
                    $values = $args['values'];
                }
            }

            switch ($args['type']) {
                case 'break':
                    $html = "<hr />";
                    break;
                case 'textbox':
                    $html = "<input type='text' id='{$args['id']}' name='{$args['id']}' value='" . get_option($args['id']) . "' style='width:80%' />";
                    break;
                case 'checkbox':
                    $html = "<input type='checkbox' id='{$args['id']}' name='{$args['id']}' value='1'" . checked(1, get_option($args['id']), false) . "/>";
                    //$html .= "<label for='{$args['id']}'></label>";                    
                    break;
                case 'radio':
                    $option = get_option($args['id']);
                    if (is_array($values)) {
                        $html = '';
                        foreach ($values as $value => $label) {
                            $html .= "<div id='radio-{$value}' class='{$args['id']}'> <input type='radio' id='{$args['id']}-{$value}' name='{$args['id']}' value='{$value}' " . checked($option, $value, false) . " />{$label}</div>";
                        }
                    }
                    break;
            }
            
            $html .= "<p class='description' id='tagline-description'>";
            if (!empty($args['desc'])) {
                $html .= "{$args['desc']}";
            }
            $html .= "</p>";
            
            echo $html;
        }

        /**
         * Plugin activated perform installation and setup 
         */
        public function sm_activate() {
            //populte for each setting/option that requires a default
            add_option('sm_enable', '1');
            add_option('sm_google_maps_api_key', '');
            add_option('sm_load_google_script', '1');
        }

        /**
         * Plugin deactivated perform de-activation tasks
         */
        public function sm_deactive() {

            //tidy up options
            $settings = array(
                array('id' => 'sm_enable'),
                array('id' => 'sm_google_maps_api_key'),
                array('id' => 'sm_load_google_script'),
            );

            foreach ($settings as $option) {
                delete_option($option['id']);
            }
        }

        /**
         * Load plugins CSS and JSS on site frontend view
         */
        public function sm_frontend_scripts() {
            if (get_option('sm_enable') == 1) {
                wp_enqueue_style('sm-front-style', YJSM_PLUGIN_URL . 'css/front.css');                
                wp_enqueue_script('sm-front', YJSM_PLUGIN_URL . 'js/front.js', array('jquery'), '1.0.0', true);      

                $apikey = get_option('sm_google_maps_api_key');
                if (!empty($apikey) && get_option('sm_load_google_script') == 1) {
                    wp_enqueue_script('google-maps-api', '//maps.google.com/maps/api/js?key=' . sanitize_text_field($apikey));                    
                }
            }
        }

        /**
         * Load plugins CSS and JSS on site backend/admin view
         * 
         * @param type $hook
         * @return type
         */
        public function sm_backend_scripts($hook) {
            wp_enqueue_style('sm-back-style', YJSM_PLUGIN_URL . 'css/admin.css');
            wp_enqueue_script('sm-front', YJSM_PLUGIN_URL . 'js/admin.js', array('jquery'), '1.0.0', true);
        }

        /**
         * register new setting page under Dashboard->Settings->
         */
        public function sm_register_menu_page() {
            add_options_page(
                    __('YumJam Map', 'textdomain'), __('YumJam Map', 'textdomain'), 'manage_options', 'sm_options', array($this, 'sm_options')
            );
        }

        /**
         * include the setting page
         * 
         */
        public function sm_options() {
            if (current_user_can('manage_options')) {
                include(YJSM_PLUGIN_PATH . '/options.php');
            }
        }

    }

}

return new YumJamSimpleMap();
