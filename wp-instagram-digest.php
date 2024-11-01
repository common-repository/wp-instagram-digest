<?php
/*
  Plugin Name: WP Instagram Digest
  Plugin URI: http://wordpress.org/extend/plugins/wp-instagram-digest/
  Description: This plugin creates daily posts with gallery of latest Instagram photos.
  Author: Spectraweb s.r.o.
  Author URI: http://www.spectraweb.cz
  Version: 1.0.2
 */

// load plugin translation
load_plugin_textdomain('wp-instadigest', false, dirname(plugin_basename(__FILE__)) . '/languages');

// activation hook
register_activation_hook(__FILE__, 'wp_instadigest_activation');
// deactivation hook
register_deactivation_hook(__FILE__, 'wp_instadigest_deactivation');

//
add_action('init', 'wp_instadigest_on_init');

//
add_action('wp_instadigest_event', 'wp_instadigest_event');

/**
 *
 */
function wp_instadigest_on_init()
{
	if (is_admin())
	{
		add_action('admin_init', 'wp_instadigest_on_admin_init');
		add_action('admin_menu', 'wp_instadigest_on_admin_menu');
	}
}

/**
 *
 */
function wp_instadigest_on_admin_init()
{
	register_setting('wp_instadigest', 'wp_instadigest_client_id');
	//
	register_setting('wp_instadigest', 'wp_instadigest_access_token');
	// post parameters
	register_setting('wp_instadigest', 'wp_instadigest_post_title');
	register_setting('wp_instadigest', 'wp_instadigest_post_template');
	register_setting('wp_instadigest', 'wp_instadigest_post_category');
	register_setting('wp_instadigest', 'wp_instadigest_post_tags');
	register_setting('wp_instadigest', 'wp_instadigest_post_time');
	//
	register_setting('wp_instadigest', 'wp_instadigest_gallery_min_size');
	//
	//register_setting('wp_instadigest', 'wp_instadigest_last_update');
}

/**
 * Add menu item
 */
function wp_instadigest_on_admin_menu()
{
	add_options_page(__('Instagram Digest', 'wp-instadigest'), __('Instagram Digest', 'wp-instadigest'), 'manage_options', basename(__FILE__), 'wp_instadigest_on_settings');
}

/**
 * Plugin activation routine
 *
 */
function wp_instadigest_activation()
{
	// schedule next fetch rss event
	wp_schedule_event(time() + 60, 'daily', 'wp_instadigest_event');

	//
	$option_name = 'wp_instadigest_timestamp';
	$newvalue = date('Y-m-d H:i:s');

	if (get_option($option_name) != $newvalue)
	{
		update_option($option_name, $newvalue);
	}
	else
	{
		$deprecated = '';
		$autoload = 'no';
		add_option($option_name, $newvalue, $deprecated, $autoload);
	}
}

/**
 * Plugin deactivation routine
 *
 */
function wp_instadigest_deactivation()
{
	wp_clear_scheduled_hook('wp_instadigest_event');
}

/**
 * Cron task - image processing
 *
 */
function wp_instadigest_event()
{
	// get request properties
	$access_token = get_option('wp_instadigest_access_token');
	$timestamp = intval(get_option('wp_instadigest_last_update', time() - 86400 * 7));
	$now = time();

	//
	if ($access_token == '')
	{
		return;
	}

	//
	if ($timestamp == '')
	{
		$timestamp = mktime(date('H'), date('i'), 0, date('n'), date('j') - 1, date('Y'));
	}
	else
	{
		//$timestamp = strtotime($timestamp);
	}

	// debug, fetch all data for last two days
	//$timestamp = mktime(date('H'), date('i'), 0, date('n'), date('j') - 2, date('Y'));

	// array of images
	$images = array();

	$url = 'https://api.instagram.com/v1/users/self/media/recent/?access_token=' . $access_token . '&min_timestamp=' . $timestamp;

	while (true)
	{
		// issue request to instagram api
		$response = wp_instadigest_get($url);

		//echo '<pre>'; var_dump($response); echo '</pre>';

		// check response for errors
		if ($response->meta->code != 200)
		{
			break;
		}

		// collect images
		foreach ($response->data as $image)
		{
			$image_url = $image->images->standard_resolution->url;
			$caption = $image->caption->text;
			$link = $image->link;

			if ($link != '' && $image_url != '')
			{
				$images[] = (object) array(
						'url' => $image_url,
						'caption' => $caption,
						'link' => $link
				);
			}
		}

		// check if we have next page
		if (!property_exists($response->pagination, 'next_url'))
		{
			break;
		}

		$url = $result->pagination->next_url;
	}

	// create gallery
	if (!empty($images) && count($images) >= get_option('wp_instadigest_gallery_min_size'))
	{
		// create post
		$post = array(
			'post_type' => 'post',
			'post_name' => 'instagram-digest-' . date('Ymd'),
			'post_title' => get_option('wp_instadigest_post_title'),
			'post_content' => get_option('wp_instadigest_post_template'),
			'post_date' => date('Y-m-d H:i:s'),
			'post_status' => 'draft',
		);

		// insert post
		$post_id = wp_insert_post($post);

		// post tags
		if (get_option('wp_instadigest_post_tags'))
		{
			wp_set_post_tags($post_id, get_option('wp_instadigest_post_tags'));
		}

		// post category
		if (get_option('wp_instadigest_post_category') != '')
		{
			wp_set_post_terms($post_id, array(intval(get_option('wp_instadigest_post_category'))), 'category');
		}

		// create dest dir
		$uploads = wp_upload_dir();
		$dest_dir = $uploads['path'];

		//
		$image_num = 1;
		foreach ($images as $image)
		{
			$image_file = wp_instadigest_download($image->url);

			if ($image_file != '')
			{
				$fname = $post['post_name'] . '-' . $image_num . '.jpg';

				$attach_name = $post['post_name'] . '-' . $image_num;

				// full path to image
				$image_full = $dest_dir . '/' . $fname;

				// copy image
				copy($image_file, $image_full);

				// attach it to the post
				$attach_id = wp_instadigest_attach_image($post_id, $image_full, array(
					'title' => $image->caption,
					'name' => $attach_name
					));

				update_post_meta($attach_id, 'link', $image->link);

				unlink($image_file);

				$image_num++;
			}
		}

		// publish post
		$update_post = array(
			'ID' => $post_id,
			'post_status' => 'publish',
		);

		// Update the post into the database
		wp_update_post($update_post);

		// update last request time
		update_option('wp_instadigest_last_update', $now);
	}
}

/**
 *
 * @param type $url
 * @param type $attachment
 * @return object
 */
function wp_instadigest_get($url)
{
	$ch = curl_init();

	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HEADER, false);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
	curl_setopt($ch, CURLOPT_USERAGENT, 'Wordpress Instagram Gallery plugin, ' . get_bloginfo('url'));

	$result = curl_exec($ch);

	$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

	curl_close($ch);

	if ($http_code != 200)
	{
		// error
		return false;
	}

	return json_decode($result);
}

/**
 *
 * @param type $url
 * @return boolean
 */
function wp_instadigest_download($url)
{
	// init CURL
	$ch = curl_init();

	// setup CURL
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HEADER, false);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
	curl_setopt($ch, CURLOPT_USERAGENT, 'Wordpress Instagram Gallery plugin, ' . get_bloginfo('url'));

	// get file contents
	$file_contents = curl_exec($ch);

	// check for errors
	if (curl_errno($ch) != 0 || $file_contents === false)
	{
		// error
		return false;
	}

	// get http code
	$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

	curl_close($ch);

	if ($http_code != 200)
	{
		// error
		return false;
	}

	// save file
	$tmpfname = tempnam("/tmp", "WPFTM-");

	$temp = fopen($tmpfname, "w");
	fwrite($temp, $file_contents);
	fclose($temp);

	return $tmpfname;
}

/**
 * Attach image file to post
 *
 * @param int $post_id ID of post
 * @param string $image_file Image file in upload directory
 * @param string $params parameters
 * @return attachement ID
 */
function wp_instadigest_attach_image($post_id, $image_file, $params = array())
{
	// create attachment
	$wp_filetype = wp_check_filetype(basename($image_file), null);

	$attachment = array(
		'post_mime_type' => $wp_filetype['type'],
		'post_title' => $params['title'],
		'post_name' => $params['name'],
		'post_status' => 'inherit',
	);

	$attach_id = wp_insert_attachment($attachment, $image_file, $post_id);

	// you must first include the image.php file for the function wp_generate_attachment_metadata() to work
	require_once(ABSPATH . 'wp-admin/includes/image.php');
	$attach_data = wp_generate_attachment_metadata($attach_id, $image_file);
	wp_update_attachment_metadata($attach_id, $attach_data);

	return $attach_id;
}

/**
 *
 */
function wp_instadigest_on_settings()
{
	$plugin_path = plugin_dir_url(__FILE__);

	$default_title = __('Instagram Daily Digest', 'wp-instadigest');
	$default_post_template = "[gallery]";
	$default_category = 1;
	$default_tags = 'instagram';
	$default_time = '09:00';
	$default_min_size = 6;
	$default_last_update = time() - 86400 * 7;

	// reschedule cron event
	$tm = explode(':', get_option('wp_instadigest_post_time'));
	$tm_today = mktime(intval($tm[0]), intval($tm[1]), 0, date('n'), date('j'), date('Y'));
	if ($tm_today < time())
	{
		// will run this time tomorrow
		$tm_next_run = mktime(intval($tm[0]), intval($tm[1]), 0, date('n'), date('j') + 1, date('Y'));
	}
	else
	{
		// can run this task today
		$tm_next_run = $tm_today;
	}

	// reschedule
	wp_clear_scheduled_hook('wp_instadigest_event');
	wp_schedule_event($tm_next_run, 'daily', 'wp_instadigest_event');
	?>
	<div class="wrap">
		<h2><?php _e('Instagram Digest Settings', 'wp-instadigest') ?></h2>

		<?php if ($message != '') : ?>
			<div class="updated fade below-h2">
				<p><?php echo $message ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields('wp_instadigest'); ?>

			<?php if (get_option('wp_instadigest_client_id') == '') : ?>

				<input type="hidden" name="wp_instadigest_post_title" value="<?php echo get_option('wp_instadigest_post_title', $default_title) ?>"/>
				<input type="hidden" name="wp_instadigest_post_template" value="<?php echo get_option('wp_instadigest_post_template', $default_post_template) ?>"/>
				<input type="hidden" name="wp_instadigest_post_category" value="<?php echo get_option('wp_instadigest_post_category', $default_category) ?>"/>
				<input type="hidden" name="wp_instadigest_post_tags" value="<?php echo get_option('wp_instadigest_post_tags', $default_tags) ?>"/>
				<input type="hidden" name="wp_instadigest_post_time" value="<?php echo get_option('wp_instadigest_post_time', $default_time) ?>"/>
				<input type="hidden" name="wp_instadigest_gallery_min_size" value="<?php echo get_option('wp_instadigest_gallery_min_size', $default_min_size) ?>"/>

				<div class="updated">
					<p><?php _e('Please complete Instagram authentication before using this plugin', 'wp-instadigest') ?></p>
				</div>

				<h3><?php _e('1. Register your application on Instagram', 'wp-instadigest') ?></h3>

				<p>
					<?php echo sprintf(__('Visit %s and register new application for your blog.', 'wp-instadigest'), '<a href="http://instagram.com/developer/" target="_blank">http://instagram.com/developer/</a>') ?>
				</p>

				<p>
					<?php echo sprintf(__('Set <strong>Website URL</strong> and <strong>Redirect URL</strong> to your blog url: %s', 'wp-instadigest'), get_bloginfo('url')) ?>
				</p>

				<h4><?php _e('Example:', 'wp-instadigest') ?></h4>

				<p>
					<img src="<?php echo $plugin_path ?>/i/example1.png" alt=""/>
				</p>

				<p>
					<?php _e('Enter Client ID value to form below:', 'wp-instadigest') ?>
				</p>

				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php _e('Instagram <strong>Client ID</strong>', 'wp-instadigest') ?></th>
						<td>
							<input type="text" name="wp_instadigest_client_id"
								   class="regular-text"
								   value="<?php echo get_option('wp_instadigest_client_id', ''); ?>" />
						</td>
					</tr>
				</table>

			<?php elseif (get_option('wp_instadigest_access_token') == '') : ?>
				<input type="hidden" name="wp_instadigest_client_id" value="<?php echo get_option('wp_instadigest_client_id') ?>"/>

				<input type="hidden" name="wp_instadigest_post_title" value="<?php echo get_option('wp_instadigest_post_title', $default_title) ?>"/>
				<input type="hidden" name="wp_instadigest_post_template" value="<?php echo get_option('wp_instadigest_post_template', $default_post_template) ?>"/>
				<input type="hidden" name="wp_instadigest_post_category" value="<?php echo get_option('wp_instadigest_post_category', $default_category) ?>"/>
				<input type="hidden" name="wp_instadigest_post_tags" value="<?php echo get_option('wp_instadigest_post_tags', $default_tags) ?>"/>
				<input type="hidden" name="wp_instadigest_post_time" value="<?php echo get_option('wp_instadigest_post_time', $default_time) ?>"/>
				<input type="hidden" name="wp_instadigest_gallery_min_size" value="<?php echo get_option('wp_instadigest_gallery_min_size', $default_min_size) ?>"/>

				<div class="updated">
					<p><?php _e('Please complete Instagram authentication before using this plugin', 'wp-instadigest') ?></p>
				</div>

				<h3><?php _e('2. Authenticate your application on Instagram', 'wp-instadigest') ?></h3>

				<p>
					<?php echo sprintf(__('Open <a href="%s" target="_blank">this link</a>.', 'wp-instadigest'), 'https://instagram.com/oauth/authorize/?client_id=' . get_option('wp_instadigest_client_id') . '&redirect_uri=' . get_bloginfo('url') . '&response_type=token') ?>
				</p>

				<p>
					<?php _e('After the authentication process you will be redirected back to your blog with the access_token in the url fragment.', 'wp-instadigest') ?>
				</p>

				<p>
					<?php _e('Simply grab the access_token off the URL fragment and paste it to form below:', 'wp-instadigest') ?>
				</p>

				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php _e('Instagram <strong>access_token</strong>', 'wp-instadigest') ?></th>
						<td>
							<input type="text" name="wp_instadigest_access_token"
								   class="regular-text"
								   value="<?php echo get_option('wp_instadigest_access_token', ''); ?>" />
						</td>
					</tr>
				</table>

			<?php else: ?>
				<input type="hidden" name="wp_instadigest_client_id" value="<?php echo get_option('wp_instadigest_client_id') ?>"/>
				<input type="hidden" name="wp_instadigest_access_token" value="<?php echo get_option('wp_instadigest_access_token') ?>"/>

				<h3><?php _e('Cron settings', 'wp-instadigest') ?></h3>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php _e('Run check task every day at', 'wp-instadigest') ?></th>
						<td>
							<input type="text" name="wp_instadigest_post_time"
								   class="small-text"
								   value="<?php echo get_option('wp_instadigest_post_time', $default_time); ?>" />
							<p class="description">
								<?php echo sprintf(__('Current server time is %s', 'wp-instadigest'), date('H:i')); ?>
							</p>
						</td>
					</tr>
				</table>

				<h3><?php _e('Post settings', 'wp-instadigest') ?></h3>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php _e('Minimal size of the gallery', 'wp-instadigest') ?></th>
						<td>
							<input type="text" name="wp_instadigest_gallery_min_size"
								   class="small-text"
								   value="<?php echo get_option('wp_instadigest_gallery_min_size', $default_min_size); ?>" />
							<p class="description">
								<?php _e('Minimal number of images to create gallery', 'wp-instadigest'); ?>
							</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php _e('Post title', 'wp-instadigest') ?></th>
						<td>
							<input type="text" name="wp_instadigest_post_title"
								   class="regular-text"
								   value="<?php echo get_option('wp_instadigest_post_title', $default_title); ?>" />
							<p class="description">
								<?php _e('Title for the post', 'wp-instadigest'); ?>
							</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php _e('Post template', 'wp-instadigest') ?></th>
						<td>
							<?php
							wp_editor(get_option('wp_instadigest_post_template', $default_post_template), 'wp_instadigest_post_template', array(
								'media_buttons' => false,
							));
							?>
							<p class="description">
								<?php _e('Template for the post content. Please use [gallery] shortcode somewhere in the content.', 'wp-instadigest'); ?>
							</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php _e('Post category', 'wp-instadigest') ?></th>
						<td>
							<?php
							$category = get_option('wp_instadigest_post_category', $default_category);
							$categories = get_terms('category', 'hide_empty=0');
							?>
							<select name="wp_instadigest_post_category">
								<?php foreach ($categories as $term) : ?>
									<option value="<?php echo $term->term_id ?>" <?php if ($term->term_id == $category) echo 'selected="selected"'; ?>><?php echo $term->name ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php _e('Category for the post', 'wp-instadigest'); ?>
							</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php _e('Post tags', 'wp-instadigest') ?></th>
						<td>
							<input type="text" name="wp_instadigest_post_tags"
								   class="regular-text"
								   value="<?php echo get_option('wp_instadigest_post_tags', $default_tags); ?>" />
							<p class="description">
								<?php _e('Comma separated list of tags', 'wp-instadigest'); ?>
							</p>
						</td>
					</tr>
				</table>

			<?php endif; ?>

			<!-- Submit form -->
			<p class="submit">
				<input type="submit" class="button-primary" value="<?php _e('Save Changes', 'wp-instadigest') ?>" />
			</p>

		</form>
	</div>

	<?php
	// DEBUG
	//wp_instadigest_event();
}
