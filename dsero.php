<?php
/*
Plugin Name: dSero Anti AdBlock for Google AdSense
Plugin URI: http://wordpress.org/extend/plugins/dsero-anti-adblock-for-google-adsense/
Description: AdBlock steals your revenue from Google AdSense. dSero will transform AdBlock users to your biggest supporters!
Author: <a href="http://dsero.com">dSero</a>
Version: 1.9.3
Author URI: http://www.dSero.com
*/

if (!class_exists("dseroCache")) {
	class dseroCache {
		public static $BlockingPath = '';
		public static $NonBlockingPath = '';
		public static $CodeGenerationUrl = 'http://mds.dsero.com/adblocker.site.setup.php?';
		public static $CodeRefreshUrl = 'http://mds.dsero.com/adblocker.site.refresh.php?s=';
		public static $SitePrivateCode = '';
		public static $LastRefresh = '1970-01-01 00:00:00';
	}
}

function dsero_get_contents($url) {
	try {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		$codeData = curl_exec($ch);
		curl_close($ch);
		if (!empty($codeData)) return $codeData;
	} catch (Exception $e) {
		$codeData = false;
	}

	try {
		$codeData = @file_get_contents(dseroCache::$CodeRefreshUrl . $siteCode);
	} catch (Exception $e) {
		$codeData = false;
	}

	return $codeData;
}

if (!class_exists("dSero")) {
	class dSero {
		const RAND_MAX = 1000;
		const TIMEOUT = 14400;
		const ZERO_DATE = '1970-01-01 00:00:00';

		const agent = 2;

		const sitePrivateCodeOptionName = "dSeroPrivateSiteCode";
		const siteBlockingPathOptionName = "dSeroBlockingPath";
		const siteNonBlockingPathOptionName = "dSeroNonBlockingPath";
		const siteLastRefreshOptionName = "dSeroLastRefresh";

		const statusSuccess = "success";
		const statusBadResponse = "Temporary error, please try again";
		const statusBadMessage = "Temporary error message, please try again";
		const statusNoTry = "Key already exists";
		const statusBadCode = "Bad API Key, please enter a valid key";

		function dSero() {}

		function dSeroPluginInstall() {
			$this->installSiteCode();
		}

		function shouldRefresh() {
			if (empty(dSeroCache::$BlockingPath) || empty(dSeroCache::$NonBlockingPath) || strtotime(gmdate('c')) - strtotime(dSeroCache::$LastRefresh) > self::TIMEOUT) {
				dSeroCache::$LastRefresh = get_option(dSero::siteLastRefreshOptionName);
				dSeroCache::$BlockingPath = get_option(dSero::siteBlockingPathOptionName);
				dSeroCache::$NonBlockingPath = get_option(dSero::siteNonBlockingPathOptionName);
			}

			if (empty(dSeroCache::$BlockingPath) || empty(dSeroCache::$NonBlockingPath) || strtotime(gmdate('c')) - strtotime(dSeroCache::$LastRefresh) > self::TIMEOUT) {
				add_option(dSero::siteLastRefreshOptionName, '', 'param1236');
				add_option(dSero::siteBlockingPathOptionName, '', 'param1235');
				add_option(dSero::siteNonBlockingPathOptionName, '', 'param1234');
				return true;
			}

			return (rand(1, self::RAND_MAX) < 2);
		}
		
		function refreshCodeFromRemote($forceRefresh = false, $siteCode = NULL) {
			if ($forceRefresh == false && $this->shouldRefresh() == false) return dSero::statusNoTry;

			if (empty($siteCode)) $siteCode = dSeroCache::$SitePrivateCode;

			// get the site code from the servers
			
			try {
				$codeData = @dsero_get_contents(dseroCache::$CodeRefreshUrl . $siteCode);
			} catch (Exception $e) {
				$codeData = false;
			}

			if (!$codeData) return dSero::statusBadResponse;
			
			$codeJson = json_decode($codeData);
			if ($codeJson->{"status"} != 1) return dSero::statusBadCode;
			if (empty($codeJson->{"message"})) return dSero::statusBadMessage;
				
			dSeroCache::$BlockingPath = $codeJson->{"message"}->{"blockingPath"};
			dSeroCache::$NonBlockingPath = $codeJson->{"message"}->{"nonblockingPath"};

			update_option(dSero::siteLastRefreshOptionName, gmdate('c')); 
			update_option(dSero::siteBlockingPathOptionName, dSeroCache::$BlockingPath); 
			update_option(dSero::siteNonBlockingPathOptionName, dSeroCache::$NonBlockingPath); 

			return dSero::statusSuccess;
		}
		
		function addHeaderCode() {
			$this->installSiteCode();
			$this->refreshCodeFromRemote();

			if (function_exists('wp_enqueue_script')) {
				wp_enqueue_script('blockingPixel', dseroCache::$BlockingPath, array(), '', true);
				wp_enqueue_script('nonblockingPixel', dseroCache::$NonBlockingPath, array(), '', true);
			}
		}

		function installSiteCode() {
			if (!empty(dSeroCache::$SitePrivateCode)) return;

			dSeroCache::$SitePrivateCode = get_option(dSero::sitePrivateCodeOptionName);
			if (!empty(dSeroCache::$SitePrivateCode)) return;

			$this->generateSiteCode();
		}

		function generateSiteCode() {
			// get the site code from the servers
			try {
				$codeData = @dsero_get_contents(dseroCache::$CodeGenerationUrl
					. "host=" . urlencode($_SERVER['SERVER_NAME'])
					. "&ua=" . urlencode($_SERVER['HTTP_USER_AGENT'])
					. "&ag=" . urlencode(dSero::agent)
					. "&client=" . urlencode($_SERVER['REMOTE_ADDR']));
			} catch (Exception $e) {
				$codeData = false;
			}

			if (!$codeData) return;

			$codeJson = json_decode($codeData);
			if ($codeJson->{"status"} != 1) return;
			if (empty($codeJson->{"message"})) return ;
				
			$privateCode = $codeJson->{"message"}->{"siteId"};
			try {
				add_option(dSero::sitePrivateCodeOptionName, $privateCode, 'param1233');
				dSeroCache::$SitePrivateCode = $privateCode;
			} catch (Exception $e) {
				return;
			}
		}

		function getDashboardPath() {
			$currentUser = wp_get_current_user();
			$emailParam = empty( $currentUser->user_email) ? '' : '&e=' . $currentUser->user_email;
			
			return "http://mds.dsero.com/adblock-report.html#p=" . dSeroCache::$SitePrivateCode . $emailParam;
		}

		//Prints out the admin page
		function printSettingsPage() {
			//Save the updated options to the database
			if (isset($_POST['update_dSeroSettings']) || isset($_POST['update_dSeroKey'])) { 
				// site private code
				if (isset($_POST[dSero::sitePrivateCodeOptionName]) && !empty($_POST[dSero::sitePrivateCodeOptionName])) {
					$statusCode = $this->refreshCodeFromRemote(true, $_POST[dSero::sitePrivateCodeOptionName]);
					if ($statusCode == dSero::statusSuccess) {
						update_option(dSero::sitePrivateCodeOptionName, $_POST[dSero::sitePrivateCodeOptionName]);
						dSeroCache::$SitePrivateCode = $_POST[dSero::sitePrivateCodeOptionName];
						?>
							<div class="updated"><p><strong>The API key was successfully updated.</strong></p></div>
						<?php
					} else {
						?>
							<div class="error"><p><strong>Failed to update API key - <?php echo $statusCode ?>.</strong></p></div>
						<?php
					}
				}
			}

			$this->installSiteCode();
			wp_enqueue_style( 'dSeroOptionsStyle', plugin_dir_url(__FILE__) . 'dsero.css', $deps, $ver, $media );
			?>

<script type="text/javascript">
	//take the fater and validate it
	function toggleSectionState(element){
		if (!element) 
			return;
		
		var parentElement = element.parentNode && element.parentNode.parentNode;
		if (!parentElement || parentElement.id <= 0) return; 
		
		if (parentElement.className == 'optionSectionClosed'){
			parentElement.className		= 'optionSectionOpened';
		} else{
			parentElement.className		= 'optionSectionClosed';		
		}
	}
</script>
<div class=wrap>
<iframe src="<?php echo $this->getDashboardPath(); ?>" height="450" width="800" marginheight="0" marginwidth="0" frameborder="0" border="0" scrolling="no" style="overflow: hidden">
</iframe>

<h2>Technical Settings</h2>
<div id="ChangeApiKeySection" class="optionSectionClosed">
<div class="optionSectionMarker">
<a href="javascript:void(0)" onclick="toggleSectionState(this)" class="blockTitle">Do You Want to Associate This Site with an Existing dSero API Key?</a>
</div>
<div class="sectionContent">
<form method="post" action="<?php echo $_SERVER["REQUEST_URI"]; ?>">
<p>
Your dSite API key: <input name="<?php echo dSero::sitePrivateCodeOptionName; ?>" id="<?php echo dSero::sitePrivateCodeOptionName; ?>" value="<?php echo dSeroCache::$SitePrivateCode; ?>" style="width:200px"/>
</p>
<p class="information"> * Should be changed only if this is a new installation of existing dSero site</p>
<div class="submit">
<input type="submit" name="update_dSeroKey" value="<?php _e('Modify Key', 'dSero') ?>" />
</div>
</form>
</div>
</div>
</div>
			<?php
		}//End function printSettingsPage()	
	}

} //End Class dSero

if (class_exists("dSero")) $dSero = new dSero();

//Initialize the admin and users panel
if (!function_exists("dSero_ap")) {
	function dSero_ap() {
		global $dSero;
		if (!isset($dSero)) return;
		if (function_exists('add_options_page')) add_options_page('dSero AdBooster', 'dSero AdBooster', 9, basename(__FILE__), array(&$dSero, 'printSettingsPage'));
	}
}

if (isset($dSero)) {
	register_activation_hook( __FILE__, array(&$dSero, 'dSeroPluginInstall') );
	$plugin = plugin_basename(__FILE__); 
	add_action('admin_menu', 'dSero_ap');
	add_action('wp_head', array(&$dSero, 'addHeaderCode'), 1);
	add_filter("plugin_action_links_$plugin", 'dSero_settings_link');
	add_filter('script_loader_src', 'dSero_script_loader_filter');
}

// Add settings link on plugin page
function dSero_settings_link($links) {
	$settings_link = '<a href="options-general.php?page=dsero.php">Settings</a>'; 
	array_unshift($links, $settings_link);
	return $links;
}

function dSero_script_loader_filter($src)
{
	if (empty($src) || empty(dseroCache::$BlockingPath) || empty(dseroCache::$NonBlockingPath)) return $src;
	
	if (strpos($src, dseroCache::$BlockingPath) !== false) return dseroCache::$BlockingPath;
	if (strpos($src, dseroCache::$NonBlockingPath) !== false) return dseroCache::$NonBlockingPath;

	return $src;
}
?>