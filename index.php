<?php 
/**
 * Plugin Name: Strava club import
 * Plugin URI: http://www.igorkiselev.com/wp-plugins/stravaimport
 * Description: Плагин импортирует данные из стравы.
 * Version: 0.0.1
 * Author: Igor Kiselev
 * Author URI: http://www.igorkiselev.com/
 * Copyright: Igor Kiselev
 * License: A "JustBeNice" license name e.g. GPL2.
 */

if( ! defined( 'ABSPATH' ) ) exit;

require_once 'stravaapi.php';
require_once 'convertor.php';

add_action('admin_init', function () {
	register_setting('justbenice-strava', 'jbn-strava-clientID' );
	register_setting('justbenice-strava', 'jbn-strava-clientSecret');
	register_setting('justbenice-strava', 'jbn-strava-redirect');
	register_setting('justbenice-strava', 'jbn-strava-accessToken');
	register_setting('justbenice-strava', 'jbn-strava-clubID');
});

add_action( 'plugins_loaded', function(){
	add_action('init', function () {
		register_post_type('team', array(
			'label' => __('Team','jbn-additional'),
			'public' => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
		
			'query_var' => true,
			'exclude_from_search' => true,
			'rewrite' => array('slug' => 'team'),
			'capability_type' => 'post',
		
			'menu_position' => 7,
			'menu_icon' => 'dashicons-groups',
		
			'supports' => array('title','editor'),
			'description' => __('List of all people we ride with','jbn-additional'),
			)
		);
		
		register_post_type('activity', array(
			'label' => __('Activities', 'jbn-strava'),
			'public' => false,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			
			'query_var' => true,
			'exclude_from_search' => true,
			'rewrite' => array('slug' => 'activity'),
			'capability_type' => 'post',
			
			'menu_position' => 7,
			'menu_icon' => 'dashicons-admin-plugins',
			
			'supports' => array('title','editor'),
			'description' => __('List of all activities fros Strava Club', 'jbn-strava'),
			)
		);
	});
	
	add_filter('manage_posts_columns', function($defaults){
		if(get_post_type() == 'team'){
			$defaults['athlete_id'] = 'ID';
		}
		return $defaults;
	});

	add_action('manage_posts_custom_column', function($column_name, $post_ID){
		if(get_post_type() == 'team'){
			if ($column_name == 'athlete_id') {
				$post = get_post($post_ID); 
				echo $post->post_name;
			}
		}
	}, 10, 2);
	
});

add_action('wp', function(){
	if( !wp_next_scheduled( 'mycronjob' ) ) {  
		wp_schedule_event( time(), 'hourly', 'mycronjob' );  
	}
});

register_deactivation_hook (__FILE__, function(){
	$timestamp = wp_next_scheduled ('mycronjob');
	wp_unschedule_event ($timestamp, 'mycronjob');
});


function update_strava() {
	$clientId = get_option( 'jbn-strava-clientID' );
	$clientSecret = get_option( 'jbn-strava-clientSecret' );
	$accessToken = get_option( 'jbn-strava-accessToken' );
	$redirect = get_option( 'jbn-strava-redirect' );
	$clubID = get_option( 'jbn-strava-clubID' );
	
	if(!empty($clientId) && !empty($clientSecret) && !empty($accessToken) && !empty($redirect) && !empty($clubID)){
		$api = new Iamstuartwilson\StravaApi(
			$clientId,
			$clientSecret
		);

		$api->authenticationUrl($redirect, $approvalPrompt = 'auto', $scope = null, $state = null);
		$api->tokenExchange($accessToken);
		$api->setAccessToken($accessToken);

		$activities = $api->get('clubs/'.$clubID.'/activities', ['per_page' => 200]);
		
		
		
		foreach ($activities as &$activity) {
			global $wpdb;
		
			$team = $wpdb->get_row("SELECT post_name FROM jbncc_posts WHERE post_type = 'team' AND post_name = '" .$activity->athlete->id . "'", 'ARRAY_A');
			
			if($team) {
				
				$query = $wpdb->get_row("SELECT post_name FROM jbncc_posts WHERE post_name = '" .$activity->id . "'", 'ARRAY_A');
			
				if(!$query) {
					$my_post = array(
						'post_type' => 'activity',
						'post_title'    => $activity->id." / ".$activity->athlete->firstname." ".$activity->athlete->lastname,
						'post_name'    => $activity->id,
						'post_status'   => 'publish',
						'post_author'   => 2,
						'post_content' => print_r($activity, true)
					);
					$post_ID = wp_insert_post($my_post);
				
					$distance = update_field( 'distance', $activity->distance, $post_ID );
					$moving_time = update_field( 'moving_time', $activity->moving_time, $post_ID );
					$value = update_field( 'total_elevation_gain', $activity->total_elevation_gain, $post_ID );
				}
				
			}
		}
	}
	
	$recepients = 'igor@justbenice.ru';
	$subject = 'Strava imported';
	$message = 'This is a test mail sent by WordPress automatically as per your schedule.';
	
	$global_overal_distance = 'global_strava_distance';
	$global_overal_time = 'global_strava_time';
	$global_overal_elevation = 'global_strava_elevation';

	$posts = get_posts( array('numberposts' => -1,'post_type' => 'activity'));
	
	$overal_distance = 0;
	$overal_time = 0;
	$overal_elevation = 0;
	
	foreach($posts as $post){ setup_postdata($post);
		$overal_distance += get_field('distance', $post->ID);
		$overal_time += get_field('moving_time', $post->ID);
		$overal_elevation += get_field('total_elevation_gain', $post->ID);
	}

	if ( get_option($global_overal_distance) ) { update_option($global_overal_distance, $overal_distance);
		} else {add_option($global_overal_distance, $overal_distance, '', 'no');
	}
	if ( get_option($global_overal_time) ) { update_option($global_overal_time, $overal_time);
		} else {add_option($global_overal_time, $overal_time, '', 'no');
	}
	if ( get_option($global_overal_elevation) ) { update_option($global_overal_elevation, $overal_elevation);
		} else {add_option($global_overal_elevation, $overal_elevation, '', 'no');
	}
	
	//mail($recepients, $subject, $message);
}

add_action ('mycronjob', 'update_strava');

add_action('admin_menu', function () {
	add_options_page( 'Strava', 'Strava', 'manage_options', 'justbenice-strava', function(){
		update_strava();
		if (!current_user_can('manage_options')) {wp_die( __('You do not have sufficient permissions to access this page.') );} ?>
		<div class="wrap">
			<h2>Настройка базовых библиотек</h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'justbenice-strava' ); ?>
				<h2><? _e('Strava application information','jbn-strava')?></h2>
				<table class="form-table">
					<tr>
						<th scope="row">
							Client ID:
						</th>
						<td>
							<label for="jbn-strava-clientID">
								<input id="jbn-strava-clientID" name="jbn-strava-clientID" type="text" value="<? echo get_option( 'jbn-strava-clientID' ); ?>"  /></label>
								<p class="description"></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							Client Secret:
						</th>
						<td>
							<label for="jbn-strava-clientSecret">
								<input id="jbn-strava-clientSecret" name="jbn-strava-clientSecret" type="text" value="<? echo get_option( 'jbn-strava-clientSecret' ); ?>" size="40" /></label>
								<p class="description"></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							Your Access Token <a href="http://strava.github.io/api/v3/oauth/">(?) </a>
						</th>
						<td>
							<label for="jbn-strava-accessToken">
								<input id="jbn-strava-accessToken" name="jbn-strava-accessToken" type="text" value="<? echo get_option( 'jbn-strava-accessToken' ); ?>"  size="40"  /></label>
								<p class="description"></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							Authorization Callback Domain
						</th>
						<td>
							<label for="jbn-strava-redirect">
								<input id="jbn-strava-redirect" name="jbn-strava-redirect" type="text" value="<? echo get_option( 'jbn-strava-redirect' ); ?>"  /></label>
								<p class="description"></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							Club ID:
						</th>
						<td>
							<label for="jbn-strava-clubID">
								<input id="jbn-strava-clubID" name="jbn-strava-clubID" type="text" value="<? echo get_option( 'jbn-strava-clubID' ); ?>"  /></label>
								<p class="description"></p>
						</td>
					</tr>
				</table>
				
				<?php do_settings_sections("theme-options"); ?>
				<?php submit_button(); ?>
			</form>
			<p>Плагин для импорта данных из strava от <a href="http://www.justbenice.ru/">Just Be Nice</a></p>
		</div>
		<?php 
	});
});

add_shortcode( 'strava_distance', function($atts, $content = ""){
	$global_overal_distance = get_option('global_strava_distance');
	if($global_overal_distance){
		$global_overal_distance = new Convertor($global_overal_distance, "m");
		echo str_replace(".", ",", $global_overal_distance->to('mgm', 1, true)); 
	}
});

add_shortcode( 'strava_time', function($atts, $content = ""){
	$global_overal_time = get_option('global_strava_time');
	if($global_overal_time){
		echo gmdate("H:i:s", $global_overal_time);
	}
});

add_shortcode( 'strava_days', function($atts, $content = ""){
	$global_overal_time = get_option('global_strava_time');
	if($global_overal_time){
		$global_overal_time = new Convertor($global_overal_time, "s");
		echo $global_overal_time->to('day',0,true);
	}
});

add_shortcode( 'strava_elevation', function($atts, $content = ""){
	$global_overal_elevation = get_option('global_strava_elevation');
	if($global_overal_elevation){
		$global_overal_elevation = new Convertor($global_overal_elevation, "m");
		echo str_replace(".", ",", $global_overal_elevation->to('km', 1, true)); 
	}
});


add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), function($links){
	return array_merge( $links, array('<a href="' . admin_url( 'options-general.php?page=justbenice-strava' ) . '">Настройки</a>',) );
});

?>