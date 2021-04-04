<?php
// Prohibit direct script loading.
defined('ABSPATH') || die('No direct script access allowed!');

/**
 * @package Scand_osTicket_Connector
 *
 * SC_OST_HttpApi class
 */
class SC_OST_HttpApi {
	private $config = array(
		'url' => SCAND_OST_CONNECTOR_CONFIG_DEFAULT_URL,
		'key' => SCAND_OST_CONNECTOR_CONFIG_DEFAULT_KEY,
		'topic' => '',
		'email' => '',
		'name' => '',
		'phone' => '',
		'phone_ext' => '',
		'subject' => '',
		'message' => '',
		'attachments' => '',
		'custom_text_field_osticket_name' => '',
		'custom_text_field_target_name' => '',
		'custom_dynamic_field_osticket_name' => '',
		'custom_dynamic_field_target_name' => '',
		'priority' => 2, // Normal
		'log_enable' => 0,
	);

	public function __construct() {
		if (is_admin()) {
			// Check WP version
			add_action('admin_init', array($this, 'check_requirements'));
		}

		// Plugin initialization
		add_action('plugins_loaded', array($this, 'init'));
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			SCAND_OST_CONNECTOR_TEXTDOMAIN,
			false,
			dirname( plugin_basename( __FILE__ ) )  . '/languages/'
		);
	}

	public function getConfig($key=null) {
		if (empty($key)) {
			$result = $this->config;
		} else {
			$result = isset($this->config[$key]) ? $this->config[$key] : '';
		}

		return $result;
	}

	public function check_requirements() {
		$wp_version = get_bloginfo('version');
		if (version_compare($wp_version, '4.3', '<')) {
			$this->deactivePlugin('getAdminVersionNotice');
		}

		if (!function_exists('curl_version') || !function_exists('json_encode')) {
			$this->deactivePlugin('getAdminSupportNotice');
		}
	}

	private function deactivePlugin($func) {
		if (is_plugin_active(plugin_basename(SCAND_OST_CONNECTOR_FILE))) {
			deactivate_plugins(plugin_basename(SCAND_OST_CONNECTOR_FILE));
			add_action('admin_notices', array($this, $func));
			if (isset($_GET['activate'])) {
				unset($_GET['activate']);
			}
		}
	}

	public function init() {
		if (is_admin()) {
			// admin settings
			add_action('init', array($this, 'load_textdomain'));
			add_action('admin_menu', array($this, 'adminMenu'));
			add_action('admin_enqueue_scripts', array($this, 'adminEnqueueScripts'));
		} else {
			add_action('wp_mail', array($this, 'createTicket'));
			add_filter('wpcf7_form_tag', array($this, 'cf7FormTagFilter'));
			add_filter('wpcf7_form_tag', array($this, 'cf7FormTagFilter2'));
		}
	}

	public function cf7FormTagFilter($scanned_tag) {
		$this->config = get_option(SCAND_OST_CONNECTOR_CONFIG_NAME, $this->config);
		if ($scanned_tag['name'] == $this->config['topic']) {
			$pipes = $scanned_tag['pipes'];
			$scanned_tag['values'] = $pipes->collect_afters();
		}
		return $scanned_tag;
	}

	public function cf7FormTagFilter2($scanned_tag) {
		$this->config = get_option(SCAND_OST_CONNECTOR_CONFIG_NAME, $this->config);
		if ($scanned_tag['name'] == $this->config['custom_dynamic_field_target_name']) {
			$pipes = $scanned_tag['pipes'];
			$scanned_tag['values'] = $pipes->collect_afters();
		}
		return $scanned_tag;
	}

	public function adminMenu() {
		add_options_page(SCAND_OST_CONNECTOR_TITLE, SCAND_OST_CONNECTOR_TITLE, 'manage_options', 'scand-osticket-settings', array($this, 'adminMenuOptions'));
	}

	public function adminEnqueueScripts() {
		wp_enqueue_script('wp-ajax-response');

		wp_register_style(SCAND_OST_CONNECTOR_NAME, plugins_url(SCAND_OST_CONNECTOR_NAME . '/css/scand-osticket-connector.css'));
		wp_enqueue_style(SCAND_OST_CONNECTOR_NAME);
	}

	protected function validateNameAttribute($value)
	{
		return (bool) preg_match("/^[a-zA-Z0-9\[\]\-_\|\s]+$/u", $value);
	}

	protected function validateInput($data)
	{
		$msg_invalid_attribute = __('%s: invalid attribute name. Valid characters: a-z, A-Z, 0-9, [, ], \\, -, _, | and space.', SCAND_OST_CONNECTOR_TEXTDOMAIN);
		$flag = 0;
		//url
		if ( ! filter_var($data['url'], FILTER_VALIDATE_URL) ) {
			add_settings_error('general', 'settings_updated', __('Invalid URL.', SCAND_OST_CONNECTOR_TEXTDOMAIN), 'error');
			$flag++;
		}
		//key
		if ( strlen($data['key']) > 32 || strlen($data['key']) < 1 ) {
			add_settings_error('general', 'settings_updated', __('Invalid key format.', SCAND_OST_CONNECTOR_TEXTDOMAIN), 'error');
			$flag++;
		}
		//form name
		if ( ! $this->validateNameAttribute($data['form_name']) ) {
			add_settings_error('general', 'settings_updated', sprintf( $msg_invalid_attribute, __('Form Identifier', SCAND_OST_CONNECTOR_TEXTDOMAIN)), 'error');
			$flag++;
		}
		if ( strlen($data['topic']) > 0 && ! $this->validateNameAttribute($data['topic']) ) {
			add_settings_error('general', 'settings_updated', sprintf( $msg_invalid_attribute, __('Help Topic', SCAND_OST_CONNECTOR_TEXTDOMAIN)), 'error');
			$flag++;
		}

		if ( ! $this->validateNameAttribute($data['email']) ) {
			add_settings_error('general', 'settings_updated', sprintf( $msg_invalid_attribute, __('Email', SCAND_OST_CONNECTOR_TEXTDOMAIN)), 'error');
			$flag++;
		}

		if ( ! $this->validateNameAttribute($data['name']) ) {
			add_settings_error('general', 'settings_updated', sprintf( $msg_invalid_attribute, __('Full Name', SCAND_OST_CONNECTOR_TEXTDOMAIN)), 'error');
			$flag++;
		}

		if ( strlen($data['phone']) > 0 && ! $this->validateNameAttribute($data['phone']) ) {
			add_settings_error('general', 'settings_updated', sprintf( $msg_invalid_attribute, __('Phone', SCAND_OST_CONNECTOR_TEXTDOMAIN)), 'error');
			$flag++;
		}

		if ( strlen($data['phone_ext']) > 0 && ! $this->validateNameAttribute($data['phone_ext']) ) {
			add_settings_error('general', 'settings_updated', sprintf( $msg_invalid_attribute, __('Phone Ext', SCAND_OST_CONNECTOR_TEXTDOMAIN)), 'error');
			$flag++;
		}

		if ( ! $this->validateNameAttribute($data['subject']) ) {
			add_settings_error('general', 'settings_updated', sprintf( $msg_invalid_attribute, __('Subject', SCAND_OST_CONNECTOR_TEXTDOMAIN)), 'error');
			$flag++;
		}

		if ( ! $this->validateNameAttribute($data['message']) ) {
			add_settings_error('general', 'settings_updated', sprintf( $msg_invalid_attribute, __('Message', SCAND_OST_CONNECTOR_TEXTDOMAIN)), 'error');
			$flag++;
		}

		if ( strlen($data['attachments']) > 0 && ! $this->validateNameAttribute($data['attachments']) ) {
			add_settings_error('general', 'settings_updated', sprintf( $msg_invalid_attribute, __('Attachments', SCAND_OST_CONNECTOR_TEXTDOMAIN)), 'error');
			$flag++;
		}

		if ( strlen($data['custom_text_field_osticket_name']) > 0 && ! $this->validateNameAttribute($data['custom_text_field_osticket_name']) ) {
			add_settings_error('general', 'settings_updated', sprintf( $msg_invalid_attribute, __('Custom Text Field osTicket Name', SCAND_OST_CONNECTOR_TEXTDOMAIN)), 'error');
			$flag++;
		}

		if ( strlen($data['custom_text_field_target_name']) > 0 && ! $this->validateNameAttribute($data['custom_text_field_target_name']) ) {
			add_settings_error('general', 'settings_updated', sprintf( $msg_invalid_attribute, __('Custom Text Field Target Name', SCAND_OST_CONNECTOR_TEXTDOMAIN)), 'error');
			$flag++;
		}

		if ( strlen($data['custom_dynamic_field_osticket_name']) > 0 && ! $this->validateNameAttribute($data['custom_dynamic_field_osticket_name']) ) {
			add_settings_error('general', 'settings_updated', sprintf( $msg_invalid_attribute, __('Custom Dynamic Field osTicket Name', SCAND_OST_CONNECTOR_TEXTDOMAIN)), 'error');
			$flag++;
		}

		if ( strlen($data['custom_dynamic_field_target_name']) > 0 && ! $this->validateNameAttribute($data['custom_dynamic_field_target_name']) ) {
			add_settings_error('general', 'settings_updated', sprintf( $msg_invalid_attribute, __('Custom Dynamic Field Target Name', SCAND_OST_CONNECTOR_TEXTDOMAIN)), 'error');
			$flag++;
		}

		return $flag;
	}

	public function adminMenuOptions() {
		if (!current_user_can('manage_options')) {
			wp_die(__('Sorry, you are not allowed to manage options for this site.', SCAND_OST_CONNECTOR_TEXTDOMAIN));
		}

		$action = empty($_POST['action']) ? '' : $_POST['action'];
		if ('update' == $action && isset($_POST[SCAND_OST_CONNECTOR_CONFIG_NAME])) {
			check_admin_referer(SCAND_OST_CONNECTOR_OPTION_PAGE . '-options');
			$this->config = $_POST[SCAND_OST_CONNECTOR_CONFIG_NAME];
			if ($this->isDefaultConfigUrl() || $this->isDefaultConfigKey()) {
				$error_message = $this->isDefaultConfigUrl() ? 'your URL' : '';
				$error_message .= $this->isDefaultConfigKey() ? (($this->isDefaultConfigUrl() ? ' and ': '') . 'an API Key') : '';
				$message = sprintf(  __('You have not configured this script with %s!', SCAND_OST_CONNECTOR_TEXTDOMAIN), $error_message );
				add_settings_error('general', 'settings_error', $message, 'error');
			} else {
				// validate POST data
				$count = $this->validateInput( $_POST[SCAND_OST_CONNECTOR_CONFIG_NAME] );
				if ($count < 1) {
					if (update_option(SCAND_OST_CONNECTOR_CONFIG_NAME, $_POST[SCAND_OST_CONNECTOR_CONFIG_NAME])) {
						$this->config = get_option(SCAND_OST_CONNECTOR_CONFIG_NAME, $this->config);
						add_settings_error('general', 'settings_updated', __('Settings saved.', SCAND_OST_CONNECTOR_TEXTDOMAIN), 'updated');
					}
				}
			}
		} else {
			$this->config = get_option(SCAND_OST_CONNECTOR_CONFIG_NAME, $this->config);
		}

		include SCAND_OST_CONNECTOR_ABSPATH . 'admin-settings.php';
	}

	public function getAdminVersionNotice() {
		echo $this->getErrorNotice('The installed version of WordPress is old.
			Please <a href="' . esc_url(admin_url('update-core.php')) . '">update your WordPress</a>.');
	}

	public function getAdminSupportNotice() {
		$curl_info = function_exists('curl_version') ? '' : '<br /> - CURL support required';
		$json_info = function_exists('json_encode') ? '' : '<br /> - JSON support required';
		echo $this->getErrorNotice($curl_info . $json_info);
	}

	private function getErrorNotice($msg) {
		return '<div class="error notice"><p>' . SCAND_OST_CONNECTOR_TITLE . ': ' . $msg . '</p></div>';
	}

	private function getSuccessNotice($msg) {
		return '<div class="updated notice"><p>' . SCAND_OST_CONNECTOR_TITLE . ': ' . $msg . '</p></div>';
	}

	public function isDefaultConfigUrl() {
		return $this->config['url'] === SCAND_OST_CONNECTOR_CONFIG_DEFAULT_URL;
	}

	public function isDefaultConfigKey() {
		return $this->config['key'] === SCAND_OST_CONNECTOR_CONFIG_DEFAULT_KEY;
	}

	public function isEmptyTicketSettings() {
		$email = $this->getConfig('email');
		$name = $this->getConfig('name');
		$subject = $this->getConfig('subject');
		$message = $this->getConfig('message');
		if (empty($email) || empty($name) || empty($subject) || empty($message) ) {
			return true;
		}

		return false;
	}

	private function getTicketValue($key) {
		$value = '';
		$values = explode(',', $this->getConfig($key));
		foreach ($values as $name) {
			$name = trim($name);
			$req_value = '';
			if (strpos($name, '|') !== false) {
				$arr_keys = explode('|', $name);
				if (isset($_REQUEST[$arr_keys[0]][$arr_keys[1]])) {
					$req_value = $_REQUEST[$arr_keys[0]][$arr_keys[1]];
				}
			} else if (isset($_REQUEST[$name])) {
				$req_value = $_REQUEST[$name];
			}
			$value .= ' ' . $req_value;
		}

		return trim($value);
	}

	private function getData($attachments) {
		$phone = $this->getTicketValue('phone');
		$phone_ext = $this->getTicketValue('phone_ext');
		if (!empty($phone_ext)) {
			$phone .= 'X' . $phone_ext;
		}

		$data = array(
			'name' => $this->getTicketValue('name'),  // from name aka User/Client Name
			'email' => $this->getTicketValue('email'),  // from email aka User/Client Email
			'phone' => $phone, // phone number aka User/Client Phone Number
			'subject' => $this->getTicketValue('subject'),  // test subject, aka Issue Summary
			'message' => $this->getTicketValue('message'),  // test ticket body, aka Issue Details.
			'ip' => $_SERVER['REMOTE_ADDR'], // should be IP address of the machine that trying to open the ticket.
			$this->config['custom_text_field_osticket_name'] => $this->getTicketValue('custom_text_field_target_name'),
			'attachments' => array(),
			'priority' => $this->getConfig('priority'),
		);

		$topic = $this->getTicketValue('topic');
		if (!empty($topic)) {
			// cast to int in case of '1 - General Inquiry' value
			$data['topicId'] = (int)$topic;
		}

		//Adding custom field setting
		$custom_dynamic_field_target_name = $this->getTicketValue('custom_dynamic_field_target_name');
		if (!empty($custom_dynamic_field_target_name) && !empty($this->config['custom_dynamic_field_osticket_name'])) {
			// cast to int in case of '1 - General Inquiry' value
			$data[$this->config['custom_dynamic_field_osticket_name']] = (int)$custom_dynamic_field_target_name;
		}

		if (!empty($attachments)) {
			$names = explode(',', $this->getConfig('attachments'));
			foreach ($names as $name) {
				if (isset($_FILES[$name])) {
					if (is_array($_FILES[$name]['name'])) {
						for ($i = 0; $i < count($_FILES[$name]['name']); $i++) {
							$data['attachments'][] = array(
								$_FILES[$name]['name'][$i] => 'data:' . $_FILES[$name]['type'][$i] . ';base64,'
									. base64_encode(file_get_contents($attachments[0]))
							);
						}
					} else {
						$data['attachments'][] = array(
							$_FILES[$name]['name'] => 'data:' . $_FILES[$name]['type'] . ';base64,'
								. base64_encode(file_get_contents($attachments[0]))
						);
					}
				}
			}
		}

		$this->log("Ticket data:\n" . var_export($data, true));

		return $data;
	}

	public function createTicket($args) {
		$this->config = get_option(SCAND_OST_CONNECTOR_CONFIG_NAME, $this->config);

		if (!array_key_exists( strtolower($this->config['form_name']), $_POST)) {
			$this->log('Unable to create ticket: form is not found');
			return $args;
		}

		if ($this->isDefaultConfigUrl() || $this->isDefaultConfigKey()) {
			$this->log('Unable to create ticket: API settings are not configured');
			return $args;
		}

		if ($this->isEmptyTicketSettings()) {
			$this->log('Unable to create ticket: fields are empty');
			return $args;
		}

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->config['url']);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($this->getData($args['attachments'])));
		curl_setopt($ch, CURLOPT_USERAGENT, 'osTicket API Client v1.8');
		curl_setopt($ch, CURLOPT_HEADER, FALSE);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:', 'X-API-Key: ' . $this->config['key']));
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, FALSE);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		$result = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($code != 201) {
			$message = strpos($result, 'Unable to create') === 0 ? $result : 'Unable to create ticket: ' . $result;
			$this->log($message);

		} else {
			$this->log('Ticket #' . $result . ' has been created');
		}

		return $args;
	}

	private function log($message) {
		if ($this->config['log_enable']) {
			file_put_contents(SCAND_OST_CONNECTOR_ABSPATH . 'osticket-connector.log', date("[Y-m-d H:i:s]: ") . $message . "\n", FILE_APPEND);
		}
	}
}

new SC_OST_HttpApi();