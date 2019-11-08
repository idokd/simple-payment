<?php
/**
 * Plugin Name: Simple Payment
 * Plugin URI: https://simple-payment.yalla-ya.com
 * Description: Simple Payment enables integration with multiple payment gateways, and customize multiple payment forms.
 * Version: 1.5.1
 * Author: Ido Kobelkowsky / yalla ya!
 * Author URI: https://github.com/idokd
 * License: GPLv2
 */

if (!defined("ABSPATH")) {
  exit; // Exit if accessed directly
}

define('SPWP_PLUGIN_FILE', __FILE__);
define('SPWP_PLUGIN_DIR', dirname(SPWP_PLUGIN_FILE));

require_once(SPWP_PLUGIN_DIR . '/vendor/autoload.php');

if (file_exists(SPWP_PLUGIN_DIR .'/vendor/leewillis77/WpListTableExportable/bootstrap.php')) require_once(SPWP_PLUGIN_DIR .'/vendor/leewillis77/WpListTableExportable/bootstrap.php');

class SimplePaymentPlugin extends SimplePayment\SimplePayment {

  const OP = 'op';
  const TYPE_FORM = 'form'; const TYPE_BUTTON = 'button'; const TYPE_TEMPLATE = 'template'; const TYPE_HIDDEN = 'hidden';
  
  const OPERATION_CSS = 'css';
  const OPERATION_PCSS = 'pcss';

  const USERNAME = 'username';
  
  public $version;
  public static $instance;
  protected $option_name = 'sp';
  protected $payment_page = null;
  public $payment_id = null;
  static protected $table_name = 'sp_transactions';
  static $engines = ['PayPal', 'Cardcom', 'iCount', 'Custom'];
  static $interactives = ['Cardcom'];

  protected $secrets = [];

  protected $test_shortcodes = [
    'button' => [
        'title' => 'Standard Button Shortcode',
        'description' => 'Show standard button',
        'shortcode' => '[simple_payment product="Test Product" amount="99.00" type="button" target="_blank"]'
    ],
    'paypal' => [
        'title' => 'Paypal Button Shortcode',
        'description' => 'Show standard button',
        'shortcode' => '[simple_payment product="Test Product" amount="99.00" title="Buy via Paypal" type="button" target="_blank" method="paypal"]'
    ]
  ];

  protected $defaults = [
      'form_type' => 'legacy',
      'amount_field' => 'amount',
      'engine' => 'PayPal',
      'mode' => 'sandbox',
      'currency' => 'USD'
  ];

  public function __construct($params = []) {
    $option = get_option('sp') ? : [];
    parent::__construct(array_merge(array_merge($this->defaults, $params), $option));
    $this->license = get_option('sp_license');
    $plugin = get_file_data(__FILE__, array('Version' => 'Version'), false);
    $this->version = $plugin['Version'];
    $this->load();
  }

  public static function instance($params = []) {
      if (!self::$instance) self::$instance = new self($params);
      return(self::$instance);
  }

  public function setEngine($engine) {
    if ($this->engine && $this->engine->name == $engine) return;
    if ($this->param('mode') == 'live' && $this->license) {
      $this->sandbox = false;
    }
    parent::setEngine($engine);
    if ($this->engine) $this->engine->setCallback(strpos($this->callback, '://') ? $this->callback : get_bloginfo('url') . $this->callback);
  }

  public function load() {
    add_action('plugins_loaded', [$this, 'load_textdomain']);
    if (self::is_gutenberg_active()) add_action('init', [$this, 'gutenberg_assets']);

    add_action('wp_loaded', [$this, 'init']);
    add_action('sp_cron', [$this, 'cron']);
    if (!wp_next_scheduled('sp_cron')) {
      wp_schedule_event(time(), 'daily', 'sp_cron') ;
    }
    if (is_admin()) {
      register_activation_hook(__FILE__, [$this, 'activate']);
      register_deactivation_hook(__FILE__, [$this, 'deactivate']);

      //if (isset($_REQUEST['action'])) {
      //  do_action("admin_post_{$_REQUEST['action']}", [$this, 'archive']);
      //}

      add_action( 'plugin_action_links_' . plugin_basename( __FILE__ ), [$this, 'plugin_action_links']);

      add_filter('display_post_states', [$this, 'add_custom_post_states']);
      add_action('admin_menu', [$this, 'add_plugin_options_page']);
      if (!empty($GLOBALS['page'])) {
          switch ($GLOBALS['page']) {
              case 'simple-payments':
                add_filter('set-screen-option', [$this, 'screen_option'], 10, 3);
                break;
              default:
                break;
          }
      }
      add_action('admin_menu', [$this, 'add_plugin_options_page']);
      if (!empty($GLOBALS['pagenow'])) {
          switch ($GLOBALS['pagenow']) {
              case 'options-general.php':
              case 'options.php':
              case 'options-reading.php':
                add_action('admin_init', [$this, 'add_plugin_settings']);
                break;
              default:
                break;
          }
      }
    }
    add_action('parse_request', [$this, 'callback']);
    add_shortcode('simple_payment', [$this, 'shortcode'] );
  }

  function plugin_action_links($links) {
  	$links = array_merge( array(
  		'<a href="' . esc_url( admin_url( '/options-general.php?page=sp' ) ) . '">' . __( 'Settings', 'simple_payment' ) . '</a>'
  	), $links );
  	return($links);
  }

  public function init() {
    $this->payment_page = self::param('payment_page');
    $this->callback = $this->payment_page();
  }

  public function payment_page() {
      if ($this->payment_page) $this->callback = get_page_link($this->payment_page);
      else $this->callback = self::param('callback_url');
      if (!$this->callback) $this->callback = $_SERVER["REQUEST_URI"];
      if (!$this->callback) $this->callback = get_bloginfo('url');
      return($this->callback);
  }

  function activate() {}

  function deactivate() {
    global $wp_rewrite;
    $timestamp = wp_next_scheduled('sp_cron');
    wp_unschedule_event($timestamp, 'sp_cron');
    $wp_rewrite->flush_rules();
  }

  public static function cron() {
    $archive_purge = self::param('auto_purge');
    $period = absint(self::param('purge_period'));
    if (!$period || !$archive_purge || $archive_purge == 'disabled') return;
    switch ($archive_purge) {
      case 'archive_purge': 
      case 'archive':
        self::process_archive($period);
        if ($archive_purge == 'archive') break;
      case 'purge':
        self::process_purge($archive_purge == 'purge' ? $period : $period * 2);
        break;
    }
    do_action('sp_payment_cron');
  }

  protected static function process_archive($days) {
    global $wpdb;
    $sql = $wpdb->prepare('UPDATE '.$wpdb->prefix.self::$table_name.' SET `archived` = 1 WHERE `created` < DATE_SUB(CURDATE(),INTERVAL %d DAY)', $days);
    $wpdb->query($sql);
    do_action('sp_payment_process_archive', $days);
  }

  protected static function process_purge($days) {
    global $wpdb;
    $sql = $wpdb->prepare('DELETE FROM '.$wpdb->prefix.'sp_history'.' WHERE `transaction_id` IN (SELECT `transaction_id` FROM '.$wpdb->prefix.self::$table_name.' WHERE `created` < DATE_SUB(CURDATE(),INTERVAL %d DAY))', $days);
    $wpdb->query($sql);
    $sql = $wpdb->prepare('DELETE FROM '.$wpdb->prefix.'sp_history'.' WHERE `payment_id` IN (SELECT `id` FROM '.$wpdb->prefix.self::$table_name.' WHERE `created` < DATE_SUB(CURDATE(),INTERVAL %d DAY))', $days);
    $wpdb->query($sql);
    $sql = $wpdb->prepare('DELETE FROM '.$wpdb->prefix.self::$table_name.' WHERE `created` < DATE_SUB(CURDATE(),INTERVAL %d DAY)', $days);
    $wpdb->query($sql);
    do_action('sp_payment_process_purge', $days);
  }

  function register_reading_setting() {
    register_setting(
      'reading',
      'sp',
      [ 'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => NULL ]
    );
    add_settings_field(
        'sp_payment_page',
        __('Payment Page', 'simple-payment'),
        [$this, 'setting_callback_function'],
        'reading',
        'default',
        array( 'label_for' => 'sp_payment_page' )
    );
  }

  function sanitize_text_field($args) {
    return(sanitize_text_field($args));
  }
  
  function setting_callback_function($args){
      $project_page_id = self::param('payment_page');
      $args = array(
          'posts_per_page'   => -1,
          'orderby'          => 'name',
          'order'            => 'ASC',
          'post_type'        => 'page',
      );
      $items = get_posts( $args );
      echo '<select id="sp_payment_page" name="sp[payment_page]">';
      echo '<option value="0">'.__('— Select —', 'wordpress').'</option>';
      foreach ($items as $item) {
          echo '<option value="'.$item->ID.'" '.($project_page_id == $item->ID ? 'selected="selected"' : '').'>'.$item->post_title.'</option>';
      }
      echo '</select>';
  }

  function add_custom_post_states($states) {
      global $post;
      $payment_page_id = self::param('payment_page');
      if( 'page' == get_post_type($post->ID) && $post->ID == $payment_page_id && $payment_page_id != '0') {
          $states[] = __('Payment Page', 'simple-payment');
      }
      return($states);
  }

  public function add_plugin_options_page() {
    add_options_page(
      __('Simple Payment', 'simple-payment'),
      __('Simple Payment', 'simple-payment'),
      'manage_options',
      'sp',
      [$this, 'render_admin_page']
    );

    $hook = add_menu_page(
      __('Payments', 'simple-payment'),
      __('Payments', 'simple-payment'),
      'manage_options',
      'simple-payments',
      [$this, 'render_transactions'],
      plugin_dir_url( __FILE__ ).'assets/simple-payment-icon.png',
      30
    );
    add_action( "load-$hook", [$this, 'transactions'] );

    $hook = add_submenu_page( null,
      __('Transaction Details', 'simple-payment'),
      null,
      'manage_options',
      'simple-payments-details',
      [$this, 'render_transaction_log']
    );
    add_action( "load-$hook", [$this, 'info'] );
  }

  public function screen_option($status, $option, $value) {
      if ( 'sp_per_page' == $option ) return $value;
      return $status;
  }

  // Render our plugin's option page.
  public function render_admin_page() {
    if (!current_user_can('manage_options')) return;

    $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'sp';
    $section = $tab;
    $tabs = ['General', 'PayPal', 'Cardcom', 'License', 'Shortcode', 'Instructions'];
    ?>
    <div class="wrap">
      <h1><?php _e('Simple Payment Settings', 'simple-payment'); ?></h1>
      <h2 class="nav-tab-wrapper">
            <a id="sp" href="options-general.php?page=sp" class="nav-tab <?php echo $tab == 'sp' ? 'nav-tab-active' : ''; ?>"><?php _e('General', 'simple-payment'); ?></a>
            <a id="paypal" href="options-general.php?page=sp&tab=paypal" class="nav-tab <?php echo $tab == 'paypal' ? 'nav-tab-active' : ''; ?>"><?php _e('PayPal', 'simple-payment'); ?></a>
            <a id="cardcom" href="options-general.php?page=sp&tab=cardcom" class="nav-tab <?php echo $tab == 'cardcom' ? 'nav-tab-active' : ''; ?>"><?php _e('Cardcom', 'simple-payment'); ?></a>
            <a id="icount" href="options-general.php?page=sp&tab=icount" class="nav-tab <?php echo $tab == 'icount' ? 'nav-tab-active' : ''; ?>"><?php _e('iCount', 'simple-payment'); ?></a>
            <a id="license" href="options-general.php?page=sp&tab=license" class="nav-tab <?php echo $tab == 'license' ? 'nav-tab-active' : ''; ?>"><?php _e('License', 'simple-payment'); ?></a>
            <a id="shortcode" href="options-general.php?page=sp&tab=shortcode" class="nav-tab <?php echo $tab == 'shortcode' ? 'nav-tab-active' : ''; ?>"><?php _e('Shortcode', 'simple-payment'); ?></a>
            <a id="instructions" href="options-general.php?page=sp&tab=instructions" class="nav-tab <?php echo $tab == 'instructions' ? 'nav-tab-active' : ''; ?>"><?php _e('Instructions', 'simple-payment'); ?></a>
        </h2>
      <?php
      switch ($tab) {
        case 'instructions':
          require(SPWP_PLUGIN_DIR.'/admin/instructions.php');
          break;
        case 'shortcode':
          require(SPWP_PLUGIN_DIR.'/admin/shortcode.php');
          foreach ($this->test_shortcodes as $key => $shortcode) {
              if (isset($shortcode['title'])) echo '<div>'.$shortcode['title'].'</div>';
              if (isset($shortcode['description'])) echo '<div>'.$shortcode['description'].'</div>';
              echo '<pre>'.$shortcode['shortcode'].'</pre>';
              echo do_shortcode($shortcode['shortcode']);
          }
          break;
        default:
          echo '<form method="post" action="options.php">';
          settings_fields('sp');
          do_settings_sections($section);
          submit_button();
          echo '</form>';
    }
    echo "</div>";
  }

  public function register_license_settings() {
    register_setting('sp', 'sp_license', ['type' => 'string', 'sanitize_callback' => [$this, 'license_key_callback']]);
    add_settings_field(
      'sp_license',
      __('License Key', 'simple-payment'),
      [$this, 'render_license_key_field'],
      'license',
      'licensing',
      array('label_for' => 'sp_license')
    );
  }
  // Initialize our plugin's settings.
  public function add_plugin_settings() {
    $this->register_reading_setting();
    $this->register_license_settings();

    require_once('settings.php');
    $this->sections = $sp_sections;

    foreach ($sp_sections as $key => $section) {
        add_settings_section(
          $key,
          $section['title'],
          [$this, isset($section['render_function']) ? $section['render_function'] : 'render_section'],
          isset($section['section']) ? $section['section'] : 'sp'
        );
    }
    register_setting('sp', 'sp', ['sanitize_callback' => [$this, 'validate_options'], 'default' => []]);

    foreach ($sp_settings as $key => $value) {
        add_settings_field(
          $key,
          $value['title'],
          [$this, isset($value['render_function']) ? $value['render_function'] : 'render_setting_field'],
          isset($value['section']) && isset($this->sections[$value['section']]) ? $this->sections[$value['section']]['section'] : 'sp',
          isset($value['section']) ? $value['section'] : 'settings',
          ['option' => $key, 'params' => $value, 'default' => NULL],
          array('label_for' => $key)
        );

        if (isset($value['sanitize_callback'])) register_setting('sp', $key, ['sanitize_callback' => [$this, $value['sanitize_callback']], 'default' => []]);

    }
  }

  protected function validate_single($options) {
    foreach($options as $key => $value) $options[$key] = is_array($value) ? $this->validate_single($value) : sanitize_text_field($value);
    return($options);
  }

  public function validate_options($options) {
    if (!is_array($options)) $options = isset($_REQUEST['sp']) ? $_REQUEST['sp'] : [];
    if (is_array($options)) $options = $this->validate_single($options);
    else $options = sanitize_text_field($options);
    $options = array_merge(self::$params, $options);
    return($options);
  }

  public function render_section($params = null) {
    $section_id = $params['id'];
    //if (isset($params['title'])) print $params['title'].'<br/ >';
    if (isset($this->sections[$section_id]['description'])) print $this->sections[$section_id]['description'].'<br/ >';
  }

  // Render the license key field.
  public function render_license_key_field() {
    printf(
      '<input type="text" id="key" size="40" name="sp_license[key]" value="%s" />',
      isset($this->license['key']) ? esc_attr($this->license['key']) : ''
    );

    if (isset($this->license['status'])) {
      printf(
        '&nbsp;<span class="description">License %s</span>',
        isset($this->license['status']) ? esc_attr($this->license['status']) : 'is missing'
      );
    }
  }

  // Sanitize input from our plugin's option form and validate the provided key.
  public function license_key_callback($options) {
    if (!isset($options['key'])) return($this->license);
    if (isset($options['key']) && !$options['key']) {
      add_settings_error('sp_license', esc_attr('settings_updated'), __('License key is required', 'simple-payment'), 'error');
      return;
    }
    // Detect multiple sanitizing passes.
    // Workaround for: https://core.trac.wordpress.org/ticket/21989
    static $cache = null;
    if ($cache !== null) return $cache;

    // Get the current domain. This example validates keys against a node-locked
    // license policy, allowing us to lock our plugin to a specific domain.
    $domain = parse_url(get_bloginfo('url'), PHP_URL_HOST);

    // Validate the license key within the scope of the current domain.
    $key = sanitize_text_field($options['key']);
    try {
      $license = $this->validate_key($key);//, $domain);
    } catch (Exception $e) {
      $message = $e->getMessage();
      $code = $e->getCode();
      switch ($message) {
        // When the license has been activated, but the current domain is not
        // associated with it, return an error.
        case 'FINGERPRINT_SCOPE_MISMATCH': {
          add_settings_error('sp_license', esc_attr('settings_updated'), __('License is not valid on the current domain', 'simple-payment'), 'error');
          break;
        }
        // When the license has not been activated yet, return an error. This
        // shouldn't happen, since we should be activating the customer's domain
        // upon purchase - around the time we create their license.
        case 'NO_MACHINES':
        case 'NO_MACHINE': {
          add_settings_error('sp_license', esc_attr('settings_updated'), __('License has not been activated', 'simple-payment'), 'error');
          break;
        }
        // When the license key does not exist, return an error.
        case 'NOT_FOUND': {
          add_settings_error('sp_license', esc_attr('settings_updated'), __('License key was not found', 'simple-payment'), 'error');
          break;
        }
        default: {
          add_settings_error('sp_license', esc_attr('settings_updated'), __("Unhandled error:", 'simple-payment') . " {$message} ({$code})", 'error');
          break;
        }
        // Clear any options that were previously stored in the database.
      }
      return([]);
    }

    // Save result to local cache.
    $cache = [
      'policy' => $license['data']['relationships']['policy']['data']['id'],
      'key' => $license['data']['attributes']['key'],
      'expiry' => $license['data']['attributes']['expiry'],
      'valid' => $license['meta']['valid'],
      'status' => $license['meta']['detail'],
      'domain' => $domain,
      'meta' => []
    ];
    foreach ($license['data']['attributes']['metadata'] as $key => $value) {
      $cache['meta'][$key] = $value;
    }
    return($cache);
  }

  function render_setting_field($options) {
      $type = isset($options['params']['type']) ? $options['params']['type'] : 'string';
      switch ($type) {
        case 'select':
          if (!isset($options['params']['options'])) {
              $items = [];
              for ($i = $options['params']['min']; $i <= $options['params']['max']; $i++) {
                  $items[$i] = $i;
              }
              $options['params']['options'] = $items;
          }
          $this->setting_select_fn($options['option'], $options['params']);
          break;
        case 'check':
          $this->setting_check_fn($options['option'], $options['params']);
          break;
        case 'radio':
          $this->setting_radio_fn($options['option'], $options['params']);
          break;
        case 'textarea':
          $this->setting_textarea_fn($options['option'], $options['params']);
          break;
        case 'password':
          $this->setting_password_fn($options['option'], $options['params']);
          break;
        case 'string':
        default:
          $this->setting_text_fn($options['option'], $options['params']);
          break;
      }
  }

  function param_name($key) {
    $keys = explode('.', $key);
    $keys = join('][', $keys);
    return('['.$keys.']');
  }

  function setting_select_fn($key, $params = null) {
    $option = isset($params['legacy']) ? get_option($key) : self::param($key);
  	$items = $params['options'];
    $field = isset($params['legacy']) ? $key : $this->option_name.$this->param_name($key);
  	echo "<select id='$key' name='{$field}'>";
    $auto = isset($params['auto']) && $params['auto'];
    if ($auto) echo "<option value=''>".($auto ? __('Auto', 'simple-payment') : '')."</option>";
  	foreach ($items as $value => $title) {
  		$selected = ($option != '' && $option == $value) ? ' selected="selected"' : '';
      if (isset($params['display']) && $params['display'] == 'both') $title = $value.' - '.$title;
  		echo "<option value='$value'$selected>$title</option>";
  	}
  	echo "</select>";
  }

  function setting_radio_fn($key, $params = null) {
    $option = self::param($key);
    $items = $params['options'];
    $field = $this->option_name.$this->param_name($key);
    foreach($items as $value => $title) {
      $checked = ($option != '' && $option == $value) ? ' checked="checked"' : '';
      echo "<label><input ".$checked." value='$value' name='{$field}' type='radio' /> $title</label><br />";
    }
  }

  function setting_text_fn($key, $params = null) {
    $option = self::param($key);
    $field = $this->option_name.$this->param_name($key);
    echo "<input id='$key' name='{$field}' size='40' type='text' value='{$option}' />";
  }

  function setting_check_fn($key, $params = null) {
  	$option = self::param($key);
    $field = $this->option_name.$this->param_name($key);
  	echo "<input ".($option ? ' checked="checked" ' : '')." id='$key' value='true' name='{$field}' type='checkbox' />";
  }

  function setting_textarea_fn($key, $params = null) {
  	$option = self::param($key);
    $field = $this->option_name.$this->param_name($key);
  	echo "<textarea id='".$key."' name='{$field}' rows='7' cols='50' type='textarea'>{$option}</textarea>";
  }

  function setting_password_fn($key, $params = null) {
  	$option = self::param($key);
    $field = $this->option_name.$this->param_name($key);
  	echo "<input id='".$key."' name='{$field}' size='40' type='password' value='{$option}' />";
  }

/*
  // CHECKBOX - Name: plugin_options[chkbox2]
  function setting_chk2_fn() {
  	$options = get_option('plugin_options');
  	if($options['chkbox2']) { $checked = ' checked="checked" '; }
  	echo "<input ".$checked." id='plugin_chk2' name='plugin_options[chkbox2]' type='checkbox' />";
  }
  */

  function process($params = []) {
    $params = apply_filters('sp_payment_process_filter', $params);
    $status = parent::process($params);
    do_action('sp_payment_process', $params);
    return($status);
  }

  function status($params = []) {
    $params = apply_filters('sp_payment_status_filter', $params);
    $data = [];
    if (isset($params['payment_id'])) $data = $this->fetch($params['payment_id']);
    $status = parent::status(array_merge($data, $params));
    if ($this->engine->confirmation_code) {
        $this->update($this->engine->transaction, [
          'confirmation_code' => $this->engine->confirmation_code,
        ], true);
    }
    do_action('sp_payment_status', $params);
    return($status);
  }

  function post_process($params = [], $engine = null) {
    $this->setEngine($engine ? : $this->param('engine'));
    $params = apply_filters('sp_payment_post_process_filter', $params);
    if (parent::post_process($params)) {
      $this->update($this->engine->transaction, [
        'status' => self::TRANSACTION_SUCCESS,
        'confirmation_code' => $this->engine->confirmation_code,
      ], true);
      if ($this->param('user_create_step') == 'post' && !get_current_user_id()) $this->create_user($params);
      do_action('sp_payment_post_process', $params);
      return(true);
    }
    return(false);
  }

  function pre_process($pre_params = []) {
    $method = isset($pre_params[self::METHOD]) ? strtolower(sanitize_text_field($pre_params[self::METHOD])) : null;
    $fields = ['concept', 'redirect_url', self::ENGINE, self::AMOUNT, self::PRODUCT, self::PRODUCT_CODE, self::METHOD, self::FIRST_NAME, self::LAST_NAME, self::PHONE, self::MOBILE, self::ADDRESS, self::ADDRESS2, self::EMAIL, self::COUNTRY, self::STATE, self::ZIPCODE, self::PAYMENTS, self::INSTALLMENTS, self::CARD_CVV, self::CARD_EXPIRY_MONTH, self::CARD_EXPIRY_YEAR, self::CARD_NUMBER, self::CURRENCY, self::COMMENT, self::CITY, self::TAX_ID, self::CARD_OWNER, self::CARD_OWNER_ID, self::LANGUAGE];
    foreach ($fields as $field) if (isset($pre_params[$field]) && $pre_params[$field]) $params[$field] = sanitize_text_field($pre_params[$field]);
    
    $params[self::AMOUNT] = self::tofloat($params[self::AMOUNT]);
    $secrets = [ self::CARD_NUMBER, self::CARD_CVV ];
    foreach ($secrets as $field) if (isset($params[$field])) $this->secrets[$field] = $params[$field];
    if (isset($this->engine->password)) $this->secrets['engine.password'] = $this->engine->password;

    if (!isset($params[self::LANGUAGE])) {
      $parts = explode('-', get_bloginfo('language'));
      $params[self::LANGUAGE] = $parts[0];
    }
    if (!isset($params['concept']) && isset($params[self::PRODUCT])) $params['concept'] = $params[self::PRODUCT];
    if ($method) $params[self::METHOD] = $method;
    if (!isset($pre_params[self::FULL_NAME]) && (isset($params[self::FIRST_NAME]) || isset($params[self::LAST_NAME]))) $params[self::FULL_NAME] = (isset($params[self::FIRST_NAME]) ? $params[self::FIRST_NAME] : '').' '.(isset($params[self::LAST_NAME]) ? $params[self::LAST_NAME] : '');
    if (!isset($pre_params[self::CARD_OWNER]) && isset($pre_params[self::FULL_NAME])) $params[self::CARD_OWNER] = $params[self::FULL_NAME];
    $params['payment_id'] = $this->register($params);
    $this->payment_id = $params['payment_id'];
    try {
      $params = apply_filters('sp_payment_pre_process_filter', $params);
      $process = parent::pre_process($params);
      $this->update($params['payment_id'], ['status' => self::TRANSACTION_PENDING, 'transaction_id' => $this->engine->transaction]);
    } catch (Exception $e) {
      $this->update($params['payment_id'], ['status' => self::TRANSACTION_FAILED, 'transaction_id' => $this->engine->transaction]);
      throw $e;
    }
    if ($this->param('user_create_step') == 'pre' && !get_current_user_id()) $this->create_user($params);
    do_action('sp_payment_pre_process', $params);
    return($process);
  }

  function recur($params = []) {
    $params = apply_filters('sp_payment_recur_filter', $params);
    if (parent::recur($params)) {
      do_action('sp_payment_recur', $params);
      return(true);
    }
    return(false);
  }

  function callback() {
    $info = parse_url($_SERVER["REQUEST_URI"]);
    $callback = parse_url($this->callback);
    if (isset($info['path']) && isset($callback['path']) && $info['path'] != $callback['path']) return;
    if (!isset($_REQUEST[self::OP])) return;
    $url = null;
    $engine = isset($_REQUEST['engine']) ? sanitize_text_field($_REQUEST['engine']) : self::param('engine');
    $op = strtolower(sanitize_text_field($_REQUEST[self::OP]));
    try {
      switch ($op) {
          case self::OPERATION_SUCCESS:
            $rmop = false;
            $url = isset($_REQUEST['redirect_url']) && $_REQUEST['redirect_url'] ? esc_url_raw($_REQUEST['redirect_url']) : self::param('redirect_url');
            if (!$url) $url = $this->payment_page();
            if (!$url) $url = get_bloginfo('url');
            $url .= (strpos($url, '?') ? '&' : '?').http_build_query($_REQUEST);
            $url = remove_query_arg(self::OP, $url);
            try {
              if (isset($_REQUEST['payment_id']) && $_REQUEST['payment_id']) $params = array_merge($this->fetch($_REQUEST['payment_id']), $_REQUEST);
              else $params = $_REQUEST;
              $this->post_process($params, $engine);
            } catch (Exception $e) {
              $status[self::OP] = self::OPERATION_ERROR;
              $status['status'] = $e->getCode();
              $status['message'] = $e->getMessage();
              $url = $this->error(isset($status) ? $status : $_REQUEST, (isset($status['status']) ? $status['status']  : null), (isset($status['message']) ? $status['message'] : null));
              break;
            } 
            break;
          case 'purchase':
          case 'payment':
          case 'redirect':
            try {
              //if (isset($_REQUEST['target']) && $_REQUEST['target'] && $_REQUEST['target'] != '_blank') $target = $_REQUEST['target'];
              $url = $this->payment($_REQUEST, $engine);
              if ($url === true) $url = isset($_REQUEST['redirect_url']) && $_REQUEST['redirect_url'] ? esc_url_raw($_REQUEST['redirect_url']) : self::param('redirect_url');
              if (!$url) {
                $url = $this->payment_page();
                $rmop = true;
              }
              if (!$url) $url = get_bloginfo('url');
              if (isset($rmop) && $rmop) $url = remove_query_arg(self::OP, $url);
              break;
            } catch (Exception $e) {
              $status['payment_id'] = $this->payment_id;
              $status['status'] = $e->getCode();
              $status['message'] = $e->getMessage();
            }
          case self::OPERATION_ERROR:
            $url = $this->error(isset($status) ? $status : $_REQUEST, (isset($status['status']) ? $status['status']  : null), (isset($status['message']) ? $status['message'] : null));
            break;
          case self::OPERATION_STATUS:
            $this->setEngine($engine);
            $this->status($_REQUEST); 
            die; break;
            break;
          case self::OPERATION_CANCEL:
            $url = $this->cancel($_REQUEST);
            break;
          case self::OPERATION_ZAPIER:
            $this->zapier();
            die; break;
            break;
          case self::OPERATION_PCSS:
            header('Content-Type: text/css');
            echo self::param(strtolower($engine).'.css');
            die; break;
          case self::OPERATION_CSS:
            header('Content-Type: text/css');
            echo self::param('css');
            die; break;
          case 'recur':

            die; break;
      }
    } catch (Exception $e) {
      $url = $this->payment_page();
      $status[self::OP] = self::OPERATION_ERROR;
      $status['status'] = $e->getCode();
      $status['message'] = $e->getMessage();
      $url .= (strpos($url, '?') ? '&' : '?').http_build_query($status);
    } 
    if ($url) {
      if ($op == 'purchase') $target = '';
      else $target = isset($target) && $target ? $target : (isset($_REQUEST['target']) ? $_REQUEST['target'] : null);
      $targets = explode(':', $target);
      $target = $targets[0];
      switch ($target) {
          case '_self':
          case '_blank':
            wp_redirect($url); 
            break;
          case '_top':
            echo '<html><head><script type="text/javascript"> top.location.replace("'.$url.'"); </script></head><body></body</html>'; 
            break;
          case '_parent':
            echo '<html><head><script type="text/javascript"> parent.location.replace("'.$url.'"); </script></head><body></body</html>'; 
            break;
          case 'javascript':
            $script = $targets[1];
            echo '<html><head><script type="text/javascript"> '.$script.' </script></head><body></body</html>'; 
            break;
          default:
            wp_redirect($url); 
            echo '<html><head><script type="text/javascript"> location.replace("'.$url.'"); </script></head><body></body</html>'; 
            break;
      }
      die(); 
    }
  }

  function payment($params = [], $engine = null) {
    $return = false;
    $engine = $engine ? : $this->param('engine');
    $this->setEngine($engine);
    if ($process = $this->pre_process($params)) {
      $process = $this->process($process);
      if ($process === true) return(true);
      if (!$process) return(false);

      $return = is_array($process) ? $this->post_process($process, $engine) : $process;
    }
    return($return);
  }

  function error($params = [], $code = null, $description = null) {
    $url = $this->payment_page();
    if (!$url) $url = get_bloginfo('url');
    $url .= (strpos($url, '?') ? '&' : '?').http_build_query($params);
    $url = remove_query_arg(self::OP, $url);
    $payment_id = isset($params['payment_id']) ? $params['payment_id']: null;
    $data = [
      'status' => self::TRANSACTION_FAILED
    ];
    if ($code) $data['error_code'] = $code;
    if ($code) $data['error_description'] = $description;
    if ($this->engine->transaction) $data['transaction_id'] = $this->engine->transaction;
    $this->update($payment_id ? $payment_id : $this->engine->transaction, $data, $payment_id > 0);
    return($url);
  }

  function cancel($params = []) {
    $url = $this->payment_page();
    $url .= (strpos($url, '?') ? '&' : '?').http_build_query($params);
    $url = remove_query_arg(self::OP, $url);
    $payment_id = isset($params['payment_id']) ? $params['payment_id']: null;
    $this->update($payment_id ? : $this->engine->transaction, [
        'status' => self::TRANSACTION_CANCEL
    ], !$payment_id);
    return($url);
  }

  function create_user($params) {
    $email = isset($params[self::EMAIL]) ? $params[self::EMAIL] : false;
    if (!$email) return(false);
    $user_id = email_exists($email);
    if (!$user_id) {
        $username = isset($params[self::USERNAME]) ? $params[self::USERNAME] : false;
        $user_id = $username ? username_exists($username) : false;
        if (!$user_id) {
          $username = isset($params[self::FIRST_NAME]) ? $params[self::FIRST_NAME] : false;
          if (!$username) $username = isset($params[self::LAST_NAME]) ? $params[self::LAST_NAME] : false;
          if (!$username) $username = isset($params[self::FULL_NAME]) ? explode(' ', $params[self::FULL_NAME])[0] : false;
          if (!$username) $username = wp_generate_password(12, false);
          $username = $this->generate_unique_username(strtolower($username));
          if ($this->param('user_create_step') == 'register') $user_id = register_new_user($username, $email);
          else $user_id = wp_create_user($username, wp_generate_password(12, false), $email);
        }
    }
    if ($user_id) wp_set_auth_cookie($user_id);
    return($user_id);
  }

  function generate_unique_username($username) {
    $username = sanitize_user($username);
    static $i;
    if ( null === $i ) $i = 1;
    else $i ++;
    if (! username_exists($username)) return($username);
    $new_username = sprintf('%s-%s', $username, $i);
    if (!username_exists($new_username)) return($new_username);
    else return($this->generate_unique_username($username));
  }

  function zapier($params = []) {
    global $wpdb;
    header('Content-Type: application/json');
    $method = isset($_REQUEST['method']) ? $_REQUEST['method'] : 'default';
    $api_key = isset($_REQUEST['api_key']) ? $_REQUEST['api_key'] : null;
    if (!$api_key) {
      print json_encode(['error' => 401, 'description' => __('API KEY Invalid', 'simple-payment')]);
      die;
    }
    switch ($method) {
        case 'transactions':
          $sql = 'SELECT * FROM '.$wpdb->prefix.self::$table_name.' WHERE `archived` = 0 ORDER BY `created` DESC';
          $zapier = $wpdb->get_results( $sql , 'ARRAY_A' );
          break;
        default:
          $zapier = [
            'site' => get_bloginfo('url'), 
            'version' => self::VERSION,
            'name' => get_bloginfo('name'),
            'platform' => 'Wordpress',
            'license' => $this->license,
            'initiator' => get_class($this),
            'platform_version' => get_bloginfo('version'), 
            'plugin_versoin' => $this->version
          ];
          break;
    }
    print json_encode($zapier);
    die;
  }
  function shortcode($atts) {
      extract( shortcode_atts( array(
            'id' => null,
            'amount' => null,
            'currency' => null,
            'product' => null,
            'fixed' => false,
            'title' => null,
            'type' => self::TYPE_FORM,
            'enable_query' => false,
            'target' => null,
            'engine' => null,
            'redirect_url' => null,
            'method' => null,
            'form' => self::param('form_type'),
            'template' => null,
            'installments' => 1,
            'amount_field' => self::param('amount_field'),
            'product_field' => null,
      ), $atts ) );
      if (!$amount || !$product) {
          $id = $id ? $id : get_the_ID();
          if (!$amount) $amount = get_post_meta($id, $amount_field, true);
          if (!$product) $product = $product_field ? get_post_meta($id, $product_field, true) : get_the_title($id);
      }
      $params = [
          'amount' => $amount,
          'product' => $product,
          'engine' => $engine ? $engine : self::param('engine'),
          'method' => $method,
          'target' => $target,
          'type' => $type,
          'redirect_url' => $redirect_url,
          'installments' => $installments,
          'currency' => $currency ? $currency : null,
          'callback' => $this->callback
      ];
      if ($enable_query) {
        if (isset($_REQUEST[self::FULL_NAME])) $params[self::FULL_NAME] = sanitize_text_field($_REQUEST[self::FULL_NAME]);
        if (isset($_REQUEST[self::PHONE])) $params[self::PHONE] = sanitize_text_field($_REQUEST[self::PHONE]);
        if (isset($_REQUEST[self::EMAIL])) $params[self::EMAIL] = sanitize_email($_REQUEST[self::EMAIL]);
      }
      switch ($type) {
          case self::TYPE_BUTTON:
            $url = $this->callback;
            $params[self::OP] = 'redirect';
            return sprintf('<a class="btn" href="%1$s"'.($target ? ' target="'.$target.'"' : '').'>%2$s</a>',
                $url.'?'.http_build_query($params),
                esc_html( $title ? $title : 'Buy' ));
            break;
          default:
          case self::TYPE_HIDDEN:
              $form = 'hidden';
          case self::TYPE_FORM:
              $template = 'form-'.$form;
              $plugin = get_file_data(__FILE__, array('Version' => 'Version'), false);
          case self::TYPE_TEMPLATE:
            wp_enqueue_script( 'simple-payment-js', plugin_dir_url( __FILE__ ).'assets/js/simple-payment.js', [], $plugin['Version'], true );
            if ($params['target']) $params['callback'] .= (strpos($params['callback'], '?') ? '&' : '?').http_build_query(['target' => $params['target']]);
            if (self::param('css')) wp_enqueue_style( 'simple-payment-css', $this->callback.'?'.http_build_query([self::OP => self::OPERATION_CSS]), [], md5(self::param('css')), 'all' );
            foreach ($params as $key => $value) set_query_var($key, $value);
            ob_start();
            if (!locate_template($template.'.php', true) && file_exists(SPWP_PLUGIN_DIR.'/templates/'.$template.'.php')) load_template(SPWP_PLUGIN_DIR.'/templates/'.$template.'.php');
            return ob_get_clean();
            break;
      }
  }

  protected function register($params) {
    global $wpdb;
    $values = [
      'engine' => $this->engine->name,
      'currency' => isset($params[self::CURRENCY]) && $params[self::CURRENCY] ? $params[self::CURRENCY] : $this->param('currency'),
      'amount' => self::tofloat($params[self::AMOUNT]),
      'concept' => $params['product'],
      'payments' => isset($params[self::PAYMENTS]) ? $params[self::PAYMENTS] : null,
      'parameters' => $this->sanitize_pci_dss(json_encode($params)),
      'url' => $this->sanitize_pci_dss($_SERVER['HTTP_REFERER']),
      'status' => self::TRANSACTION_NEW,
      'sandbox' => $this->sandbox,
      'user_id' => get_current_user_id() ? : null,
      'ip_address' => $_SERVER['REMOTE_ADDR'],
      'user_agent' => $_SERVER['HTTP_USER_AGENT']
    ];
    $result = $wpdb->insert($wpdb->prefix.self::$table_name, $values);
    return($wpdb->insert_id ?  : false);
  }

  protected static function update($id, $params, $transaction_id = false) {
    global $wpdb;
    $table_name = $wpdb->prefix.self::$table_name;
    if (!isset($params['modified'])) $params['modified'] = current_time('mysql');
    $user_id = get_current_user_id();
    if ($user_id) $params['user_id'] = $user_id;
    $result = $wpdb->update($table_name, $params, [($transaction_id ? 'transaction_id' : 'id') => $id]);
    if ($result === false) throw new Exception(__("Couldn't update transaction: ") . $wpdb->last_error);
    return($result);
  }

  public function transactions() {
      global $wpdb, $list;
      add_screen_option('per_page', [
         'default' => 20,
         'option' => 'sp_per_page'
      ]);
      require(SPWP_PLUGIN_DIR.'/admin/transaction-list-table.php');
      $list = new Transaction_List();
  }

  public function fetch($id, $engine = null) {
    global $wpdb;
    $table_name = $wpdb->prefix.self::$table_name;
    if (!$engine) {
        $sql = "SELECT * FROM ".$table_name." WHERE `id` = %d LIMIT 1";
        $sql = sprintf($sql, absint($id));
    } else {
        $sql = "SELECT * FROM ".$table_name." WHERE `engine` = '%s' `transaction_id` = %d LIMIT 1";
        $sql = sprintf($sql, esc_sql($engine), esc_sql($id));
    }
    $result = $wpdb->get_results( $sql , 'ARRAY_A' );
    return(count($result) ? $result[0] : null);
  }

  public function info() {
      global $wpdb, $list;
      add_screen_option('per_page', [
         'default' => 20,
         'option' => 'sp_per_page'
      ]);
      if (!isset($_REQUEST['transaction_id']) || !isset($_REQUEST['engine'])) throw new Exception(__('Error fetching transaction'), 500);
      $id = sanitize_text_field($_REQUEST['transaction_id']);
      $engine = sanitize_text_field($_REQUEST['engine']);

      require(SPWP_PLUGIN_DIR.'/admin/transaction-list-table.php');
      $list = new Transaction_List($engine);
  }

  public function render_transactions() {
    global $list;
    require(SPWP_PLUGIN_DIR.'/admin/transactions.php');
  }

  public function render_transaction_log() {
    global $list;
    require(SPWP_PLUGIN_DIR.'/admin/transaction-log.php');
  }

  /**
   * Check if Gutenberg is active
   *
   * @since 1.0.0
   *
   * @return boolean
   */
    public static function is_gutenberg_active() {
      return function_exists('register_block_type');
    }

    function gutenberg_assets() { // phpcs:ignore
      wp_register_style(
        'simple-payment-gb-style-css', 
        plugin_dir_url( __FILE__ ).'/addons/gutenberg/blocks.style.build.css', // Block style CSS.
        array( 'wp-editor' ), 
        null // filemtime( plugin_dir_path( __DIR__ ) . 'dist/blocks.style.build.css' ) // Version: 1.5.1File modification time.
      );
      wp_register_script(
        'simple-payment-gb-block-js',
        plugin_dir_url( __FILE__ ).'/addons/gutenberg/blocks.build.js',
        array( 'wp-blocks', 'wp-i18n', 'wp-element', 'wp-shortcode', 'wp-editor' ), 
        null, // filemtime( plugin_dir_path( __DIR__ ) . 'dist/blocks.build.js' ), // Version: 1.5.1filemtime — Gets file modification time.
        true 
      );
      wp_register_style(
        'simple-payment-gb-editor-css', 
        plugin_dir_url( __FILE__ ).'/addons/gutenberg/blocks.editor.build.css', 
        array( 'wp-edit-blocks' ), 
        null // filemtime( plugin_dir_path( __DIR__ ) . 'dist/blocks.editor.build.css' ) // Version: 1.5.1File modification time.
      );
      wp_localize_script(
        'simple-payment-gb-block-js',
        'spGlobal', // Array containing dynamic data for a JS Global.
        [
          'pluginDirPath' => plugin_dir_path( __DIR__ ),
          'pluginDirUrl'  => plugin_dir_url( __DIR__ ),
          // Add more data here that you want to access from `cgbGlobal` object.
        ]
      );
      register_block_type(
        'simple-payment/simple-payment', array(
          'style'         => 'simple-payment-gb-style-css',
          'editor_script' => 'simple-payment-gb-block-js',
          'editor_style'  => 'simple-payment-gb-editor-css',
        )
      );
    }

    /**
     * Load Simple Payment Text Domain.
     * This will load the translation textdomain depending on the file priorities.
     *      1. Global Languages /wp-content/languages/simple-payment/ folder
     *      2. Local directory /wp-content/plugins/simple-payment/languages/ folder
     *
     * @since  1.0.0
     * @return void
     */
    public function load_textdomain() {
      $lang_dir = apply_filters( 'sp_languages_directory', SPWP_PLUGIN_DIR . '/languages/' );
      load_plugin_textdomain( 'simple-payment', $lang_dir, str_replace(WP_PLUGIN_DIR, '', $lang_dir) );
    }

    public static function archive($id = null) {
      self::update($id ? : $_REQUEST['transaction'], [
        'archived' => true
      ]);
      do_action('sp_payment_archive', $id);
      //wp_redirect( wp_get_referer() );
    }

    public function sanitize_pci_dss($value) {
      $count = 0;
      foreach ($this->secrets as $key => $secret) {
        if (!$secret) continue;
        switch ($key) {
          case self::CARD_NUMBER:
            $first = substr($secret, 0, 4);
            $last = substr($secret, - 4);
            if ($cnt = preg_match_all('/('.$first.'.*)('.$last.')/', $value, $matches, PREG_SET_ORDER)) {
              foreach ($matches as $match) {
                $santized = preg_replace('/\d/', 'X', $match[1]).$match[2];
                $value = str_replace($match[0], $santized, $value);
              }
            }
            break;
          case self::CARD_CVV:
            $value = preg_replace('/([^\d])('.$secret.')([^\d.])/', '\1'.str_repeat('X', strlen($secret)).'\3', $value);
            break;
          default:
            $value = str_replace($secret, 'xxx', $value, $cnt);
        }
        $count += $cnt;
      }
      return($value);
    }

    public function save($params, $tablename = null, $id = null) {
      global $wpdb;
      $tablename = 'history';
      foreach ($params as $field => $value) $params[$field] = $this->sanitize_pci_dss($value);
      if (!isset($params['payment_id'])) {
        $payment_id = $this->payment_id ? : (isset($_REQUEST['payment_id']) ? $_REQUEST['payment_id'] : null);
        if ($payment_id) $params['payment_id'] = $payment_id;
      }
      if (!isset($params['ip_address'])) $params['ip_address'] = $_SERVER['REMOTE_ADDR'];
      if (!isset($params['user_agent'])) $params['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
      // TODO: if id update instead of insert
      $result = $wpdb->insert($wpdb->prefix . 'sp_' . $tablename, $params);
      return($result != null ? $wpdb->insert_id : false);
    }
}

require_once('db/simple-payment-database.php');

global $SPWP;
$SPWP = SimplePaymentPlugin::instance();

require_once('addons/woocommerce/woo-simple-payment.php');
