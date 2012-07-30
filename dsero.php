<?php
/*
Plugin Name: dSero Anti AdBlock for Google AdSense
Plugin URI: http://wordpress.org/extend/plugins/dsero-anti-adblock/
Description: AdBlock steals your revenue from Google AdSense. dSero Anti AdBlock will gain it back. Help us keep the internet free!
Author: <a href="http://dsero.com">dSero</a>
Version: 1.4
Author URI: http://www.dSero.com
*/

if (!class_exists("dseroCache")) {
	class dseroCache {
		public static $BlockingPath = '';
		public static $NonBlockingPath = '';
		public static $CodeGenerationUrl = 'http://mds.dsero.com/adblocker.site.setup.php?';
		public static $CodeRefreshUrl = 'http://mds.dsero.com/adblocker.site.refresh.php?s=';
		public static $SitePrivateCode = '';
		public static $SitePublicCode = '';
		public static $dSeroIsEnabled = "true";
	}
}

if (!class_exists("dSero")) {
	class dSero {
		const RAND_MAX = 10000;

		const agent = 2;

		const sitePrivateCodeOptionName = "dSeroPrivateSiteCode";
		const sitePublicCodeOptionName = "dSeroPublicSiteCode";
		const isEnabledName = "dSeroEnabledOption";

		const statusSuccess = "success";
		const statusBadResponse = "Temporary error, please try again";
		const statusNoTry = "Key already exists";
		const statusBadCode = "Bad API Key, please enter a valid key";

		function dSero() {}
		
		function dSeroPluginInstall() {
			// automatically enable the plugin on first install,
			// if value already exists does nothin
			add_option(dSero::isEnabledName, "true", 'param1231');
			$this->installSiteCode();
			$this->getIsEnabled();
		}
		
		function getIsEnabled() {
			dseroCache::$dSeroIsEnabled = get_option(dSero::isEnabledName);
		}
		
		function shouldRefresh() {
			return (dSeroCache::$BlockingPath == '') || 
				(dSeroCache::$NonBlockingPath == '') ||
				(rand(1, self::RAND_MAX) < 2);
		}
		
		function refreshCodeFromRemote($forceRefresh = false, $siteCode = NULL) {
			if ($forceRefresh == false && $this->shouldRefresh() == false) return dSero::statusNoTry;

			if (empty($siteCode)) $siteCode = dSeroCache::$SitePrivateCode;

			// get the site code from the servers
			
			try {
				$codeData = file_get_contents(dseroCache::$CodeRefreshUrl . $siteCode);
			} catch (Exception $e) {
				$codeData = false;
			}

			if (!$codeData) return dSero::statusBadResponse;
			
			$codeJson = json_decode($codeData);
			if ($codeJson->{"status"} != 1) return dSero::statusBadCode;
			if (empty($codeJson->{"message"})) return dSero::statusBadResponse;
				
			dSeroCache::$BlockingPath = $codeJson->{"message"}->{"blockingPath"};
			dSeroCache::$NonBlockingPath = $codeJson->{"message"}->{"nonblockingPath"};
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
		
			if (!empty(dSeroCache::$SitePrivateCode) && !empty(dSeroCache::$SitePublicCode)) return;

			dSeroCache::$SitePrivateCode = get_option(dSero::sitePrivateCodeOptionName);
			dSeroCache::$SitePublicCode = get_option(dSero::sitePublicCodeOptionName);
			if (dSeroCache::$SitePrivateCode != '' && dSeroCache::$SitePublicCode != '') return;

			$this->generateSiteCode();
		}

		function generateSiteCode() {
			// get the site code from the servers
			try {
				$codeData = file_get_contents(dseroCache::$CodeGenerationUrl
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
			$publicCode = $codeJson->{"message"}->{"trackerId"};
			try {
				add_option(dSero::sitePrivateCodeOptionName, $privateCode, 'param1233');
				add_option(dSero::sitePublicCodeOptionName, $publicCode, 'param1232');
				dSeroCache::$SitePrivateCode = $privateCode;
				dSeroCache::$SitePublicCode = $publicCode;
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
				// enable/disable plugin
				if (isset($_POST['dSeroAddContent'])) {
					$statusCaption = ($_POST['dSeroAddContent'] == "true" ? 'Enabled' : 'Disabled');
					update_option(dSero::isEnabledName, $_POST['dSeroAddContent']);
					dseroCache::$dSeroIsEnabled  = $_POST['dSeroAddContent'];
					?>
						<div class="updated"><p><strong><?php echo $statusCaption; ?> dSero AdBooster system successfully.</strong></p></div>
					<?php
				}
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
<!--div id="enablePluginSection" class="optionSectionClosed">
<div class="optionSectionMarker">
<a href="javascript:void(0)" onclick="toggleSectionState(this)" class="blockTitle">Do You Want dSero AdBooster System Keep Working for You?</a>
</div>
<div class="sectionContent">
<form method="post" action="<?php echo $_SERVER["REQUEST_URI"]; ?>">
<p>"No" selection will disable the dSero AdBooster system (not recommended).</p>
<p><label for="dSeroAddContent_yes"><input type="radio" id="dSeroAddContent_yes" name="dSeroAddContent" value="true" <?php if (dseroCache::$dSeroIsEnabled == "true") { _e('checked="checked"', "dSero"); }?> /> Yes</label>&nbsp;&nbsp;&nbsp;&nbsp;<label for="dSeroAddContent_no"><input type="radio" id="dSeroAddContent_no" name="dSeroAddContent" value="false" <?php if (dseroCache::$dSeroIsEnabled == "false") { _e('checked="checked"', "dSero"); }?>/> No</label></p>
<div class="submit">
<input type="submit" name="update_dSeroSettings" value="<?php _e('Update Settings', 'dSero') ?>" />
</div>
</form>
</div>
</div-->
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