<?php
/*
Plugin Name: Merlion API
Description: Merlion API for Woocommerce.
Version: 0.1
Author: Wortep
Author URI: http://vk.com/worteepz
*/

defined( 'ABSPATH' ) or die( 'Plugin file cannot be accessed directly.' );
require ("merlion_db.php");
add_action('admin_init', 'merlion_admin_init' );

function merlion_admin_init() {
	wp_register_script('jquery-ui', '//code.jquery.com/ui/1.11.4/jquery-ui.js');
	wp_register_script('jquery-tree', plugins_url('/src/js/jQuery.Tree.js', __FILE__));
	wp_register_script('start', plugins_url('/src/js/start.js', __FILE__));
	wp_register_style('jquery-ui', '//code.jquery.com/ui/1.11.4/themes/ui-lightness/jquery-ui.css');
	wp_register_style('jquery-tree', plugins_url('/src/css/jQuery.Tree.css', __FILE__));
	wp_register_style('merlion_style', plugins_url('/src/css/merlion_style.css', __FILE__));
	if (!get_option('merlion_cat')) add_option('merlion_cat', '', '', 'no');
	if (!get_option('merlion_subcat')) add_option('merlion_subcat', '', '', 'no');
	if (!get_option('merlion_group')) add_option('merlion_group', '', '', 'no');
	if (!get_option('merlion_shipment')) add_option('merlion_shipment', '','','no');
	if (!get_option('merlion_selected_shipment')) add_option('merlion_selected_shipment', '','','no');
	if (!get_option('merlion_article')) add_option('merlion_article', '', '', 'no');
	if (!get_option('merlion_update')) add_option('merlion_update', '', '', 'no');
	if (!get_option('merlion_last_update')) add_option('merlion_last_update', '', '', 'no');
	if (!get_option('merlion_shipment_dates')) add_option('merlion_shipment_dates', '', '', 'no');
	if (!get_option('merlion_load_categories')) add_option('merlion_load_categories', '', '', 'no');
	if (!get_option('merlion_current_download')) add_option('merlion_current_download', '', '', 'no');
	if (!get_option('merlion_update_avail')) add_option('merlion_update_avail', '', '', 'no');
	if (!get_option('merlion_image_error')) add_option('merlion_image_error', '', '', 'no');
}

add_action('admin_menu', 'merlion_menu', 12);

function merlion_menu() {
	$page = add_menu_page('Merlion', 'Merlion', 'manage_options', __FILE__, 'merlion_page'); 
	add_action('admin_print_scripts-'.$page, 'merlion_admin_scripts');
}

function merlion_admin_scripts() {
	wp_enqueue_script('jquery');
	wp_enqueue_script('jquery-ui');
	wp_enqueue_script('jquery-tree');
	wp_enqueue_script('start');
	wp_enqueue_style('jquery-ui');
	wp_enqueue_style('jquery-tree');
	wp_enqueue_style('merlion_style');
}

add_action('merlion_update_hook', 'load_items_by_cat');
add_action('merlion_download_hook', 'load_items');
add_action('merlion_properties_hook', 'load_properties');
add_action('merlion_images_hook', 'load_images');
add_action('merlion_add_avail_hook', 'load_more_information');
add_action('merlion_set_avail_hook', 'set_available');

add_filter( 'cron_schedules', 'cron_add_weekly' );
function cron_add_weekly( $schedules ) {
	//echo "Add schedules <br/>";
	$schedules['weekly'] = array(
		'interval' => 604800,
		'display' => 'Еженедельно'
	);
	/*$schedules['download'] = array(
		'interval' => 60,
		'display' => 'Minute'
	);
	$schedules['ten'] = array (
		'interval' => 10,
		'display' => 'Ten seconds'
	);*/
	return $schedules;
}
register_activation_hook(__FILE__, 'merlion_update_activation');
function merlion_update_activation() { 
	wp_schedule_event(time(), 'hourly', 'merlion_update_hook'); 
}

register_deactivation_hook( __FILE__, 'merlion_deactivate' );
function merlion_deactivate() {
  wp_clear_scheduled_hook('merlion_update_hook'); 
}

register_uninstall_hook(__FILE__, 'merlion_uninstall');
function merlion_uninstall() {
	if (get_option('merlion_cat')) delete_option('merlion_cat');
	if (get_option('merlion_subcat')) delete_option('merlion_subcat');
	if (get_option('merlion_group')) delete_option('merlion_group');
	if (get_option('merlion_shipment')) delete_option('merlion_shipment');
	if (get_option('merlion_selected_shipment')) delete_option('merlion_selected_shipment');
	if (get_option('merlion_article')) delete_option('merlion_article');
	if (get_option('merlion_update')) delete_option('merlion_update');
	if (get_option('merlion_last_update')) delete_option('merlion_last_update');
	if (get_option('merlion_shipment_dates')) delete_option('merlion_shipment_dates');
	if (get_option('merlion_load_categories')) delete_option('merlion_load_categories');
	if (get_option('merlion_current_download')) delete_option('merlion_current_download');
	if (get_option('merlion_update_avail')) delete_option('merlion_update_avail');
	if (get_option('merlion_image_error')) delete_option('merlion_image_error');
	wp_clear_scheduled_hook('merlion_update_hook');
}

/*function load_properties_by_cat() {
	$next = wp_next_scheduled('merlion_properties_hook');
	if ($next) wp_unschedule_event($next, 'merlion_properties_hook');
	
	$cats = get_option('merlion_cat');
	$subcats = get_option('merlion_subcat');
	$groups = get_option('merlion_group');
	
	$categories = array();
	
	if ($groups) {
		foreach ($groups as $key=>$value) {
			$categories[] = $value; //load_properties($value, $prod_id, $date_mod);
		}
	}
	if ($subcats) {
		foreach ($subcats as $key=>$value) {
			$child = get_categories(array('taxonomy' => 'product_cat', 'hide_empty' => false,'parent' => $key));
			if (!$child)  {
				$categories[] = $value; //load_properties($value, $prod_id, $date_mod);
			}
		}
	}
	if ($cats) {
		foreach ($cats as $key=>$value) {
			$child = get_categories(array('taxonomy' => 'product_cat', 'hide_empty' => false,'parent' => $key));
			if (!$child) $categories[] = $value; //load_properties($value, $prod_id, $date_mod);
		}
	}
	if ($categories) {
	update_option('merlion_load_categories', array('cats' => json_encode($categories), 'index' => 0, 'page' => 1, 'rows_on_page' => 200));
	update_option('merlion_current_download', 'характеристик');
	wp_schedule_event(time(), 'download', 'merlion_properties_hook');
	echo "<div id='message' class='notice notice-success'><p>Загрузка характеристик началась. Она может занять продолжительное время и будет продолжаться в фоновом режиме.</p></div>";
return 1;
}
else { update_option('merlion_image_error', "Категории не выбраны!"); return 0;}
	
}

function load_images_by_cat() {
	$next = wp_next_scheduled('merlion_images_hook');
	if ($next) wp_unschedule_event($next, 'merlion_images_hook');
	
	$cats = get_option('merlion_cat');
	$subcats = get_option('merlion_subcat');
	$groups = get_option('merlion_group');
	
	$categories = array();
	
	if ($groups) {
		foreach ($groups as $key=>$value) {
			$categories[] = $value; //load_images($value, $prod_id, $date_mod);
		}
	}
	if ($subcats) {
		foreach ($subcats as $key=>$value) {
			$child = get_categories(array('taxonomy' => 'product_cat', 'hide_empty' => false,'parent' => $key));
			if (!$child)  {
				$categories[] = $value; //load_images($value, $prod_id, $date_mod);
			}
		}
	}
	if ($cats) {
		foreach ($cats as $key=>$value) {
			$child = get_categories(array('taxonomy' => 'product_cat', 'hide_empty' => false,'parent' => $key));
			if (!$child) $categories[] = $value; //load_images($value, $prod_id, $date_mod);
		}
	}
	if ($categories) {
		update_option('merlion_load_categories', array('cats' => json_encode($categories), 'index' => 0, 'page' => 1, 'rows_on_page' => 150));
		update_option('merlion_current_download', 'изображений');
		wp_schedule_event(time(), 'download', 'merlion_images_hook');
		echo "<div id='message' class='notice notice-success'><p>Загрузка изображений началась. Она может занять продолжительное время и будет продолжаться в фоновом режиме.</p></div>";
	return 1;
	}
	else { update_option('merlion_image_error', "Категории не выбраны!"); return 0;}
	//	
}*/

function load_items_by_cat() {
	$next = wp_next_scheduled('merlion_download_hook');
	if ($next) wp_unschedule_event($next, 'merlion_download_hook');
	
	$cats = get_option('merlion_cat');
	$subcats = get_option('merlion_subcat');
	$groups = get_option('merlion_group');

	$categories = array();
	
	if ($groups) {
		foreach ($groups as $key=>$value) {
			//load_items($value, $prod_id, $date_mod);
			$categories[] = $value;
		}
	}
	if ($subcats) {
		foreach ($subcats as $key=>$value) {
			$child = get_categories(array('taxonomy' => 'product_cat', 'hide_empty' => false,'parent' => $key));
			if (!$child)  {
				//load_items($value, $prod_id, $date_mod);
				$categories[] = $value;
			}
		}
	}
	if ($cats) {
		foreach ($cats as $key=>$value) {
			$child = get_categories(array('taxonomy' => 'product_cat', 'hide_empty' => false,'parent' => $key));
			if (!$child) $categories[] = $value; //load_items($value, $prod_id, $date_mod);
		}
	}
	if ($categories) {
 		if (wp_next_scheduled('merlion_download_hook')) { update_option('merlion_current_download', 'товаров'); return 0;}
  		elseif (wp_next_scheduled('merlion_properties_hook')) { update_option('merlion_current_download', 'характеристик'); return 0;} 
		elseif (wp_next_scheduled('merlion_images_hook')) { update_option('merlion_current_download', 'изображений'); return 0;}
		else {
			update_option('merlion_load_categories', array('cats' => json_encode($categories), 'index' => 0, 'page' => 1, 'rows_on_page' => 150));
			update_option('merlion_current_download', 'товаров');
			update_option('merlion_start_update', current_time('mysql'));
			wp_schedule_single_event(time(), 'merlion_download_hook');
			echo "<div id='message' class='notice notice-success'><p>Загрузка товаров началась. Она может занять продолжительное время и будет продолжаться в фоновом режиме. Во время загрузки нельзя обновить или загрузить другие данные.</p></div>";
			return 1;
		}
	}
	else { update_option('merlion_image_error', "Категории не выбраны!"); return 0;}
	//
}

function set_shipments_methods() {
	$as_array = load_shipments_methods();
	update_option('merlion_shipment', $as_array);
}

function merlion_page() {
	$mes = get_option('merlion_current_download');
	$last = time() - get_option('merlion_last_item_add');
	if ($last > 6000) $mes = '';
	if ($mes) {
		echo "<div id='message' class='error'><p>Идет загрузка $mes</p></div>";
	}
	
	$err = get_option('merlion_image_error');
	if ($err) {
		echo $err;
	}
	update_option('merlion_image_error', '');
	
	//echo '<pre>'; print_r(_get_cron_array());echo '</pre>';
	//$next_update = wp_next_scheduled( 'merlion_update_hook' );
	//echo date('d-m-Y H:i:s', $next_update);
	$saved_cats = get_option('merlion_cat');
	$saved_subcats = get_option('merlion_subcat');
	$saved_groups = get_option('merlion_group');
	
	
	if (isset($_POST["save_merlion"]) && $_POST["save_merlion"]) {
		if ($saved_cats != $_POST['cat']) {
			update_option('merlion_cat', $_POST['cat']);
			$saved_cats = $_POST['cat'];
		}
		if ($saved_subcats != $_POST['subcat']) {
			update_option('merlion_subcat', $_POST['subcat']);
			$saved_subcats = $_POST['subcat'];
		}
		if ($saved_groups != $_POST['group']) {
			update_option('merlion_group', $_POST['group']);
			$saved_groups = $_POST['group'];
		}
		
		update_option('merlion_selected_shipment', $_POST['ships']);
		
		update_option('merlion_article', array('prefix'=> $_POST['prefix'], 'suffix'=>$_POST['suffix']));
		
		$time_diff = current_time('timestamp') - current_time('timestamp',1); //текущее местное время блога - время UTC
		$time_update = mktime($_POST['update_hour'], $_POST['update_minute']);
		update_option('merlion_update', array('need' => isset($_POST["need_update"])? $_POST['need_update']:'','type'=>$_POST['time'], 'time' =>  $time_update));
		
		if (isset($_POST["need_update"]) && $_POST['need_update']) {
			
		
			if (isset($_POST["update_day"]) && $_POST['update_day']) {
				$dow = date('N', $time_update);
				$diff = $_POST['update_day'] - $dow;
				$time_update += $diff*24*60*60;
			}
		
			
			//echo date('d-m-Y H:i:s', $time_update);
		
		
			$next_update = wp_next_scheduled( 'merlion_update_hook' );
			if ( !$next_update ) {
				wp_schedule_event($time_update - $time_diff, $_POST['time'], 'merlion_update_hook');
			}
			else {
				wp_unschedule_event($next_update, 'merlion_update_hook');
				wp_schedule_event( $time_update - $time_diff, $_POST['time'], 'merlion_update_hook');
			}
		}
		else { 
			$next_update = wp_next_scheduled( 'merlion_update_hook' );
			if ($next_update) wp_unschedule_event($next_update, 'merlion_update_hook');
		}
	}
	if (isset($_POST["update_categories"]) && $_POST["update_categories"]) {
		load_categories('All');
	}
	
	/*if (isset($_POST["download_properties"]) && $_POST["download_properties"]) {
		$mes = load_properties_by_cat(); 
	}
	
	if (isset($_POST["download_images"]) && $_POST["download_images"]) {
		$mes = load_images_by_cat(); 
	}
	*/
	if (isset($_POST["download_items"]) && $_POST["download_items"]) {
		$mes = load_items_by_cat();
	}

	if (isset($_POST["shipment_download"]) && $_POST["shipment_download"]) {
		set_shipments_methods();
	}
	
	$categories = get_categories(array('taxonomy' => 'product_cat', 'hide_empty' => false,'parent' => 0));
?>

<form id='cat_choice' method='post'>
	<h3>Настройки</h3>
	<table class='form-table'>
		<tbody>
			<tr>
				<th>
					<label for='categories'>Категории</label>
					<span class='tip'>Выберите категории для загрузки данных о товарах</span>
				</th>
				<td>
					<div id="categories">
						<div>
							<ul id="tree">
<?php	
	foreach ($categories as $cat) {
		$child_cat = get_categories(array('taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => $cat->term_id));
		//echo "<li><pre>"; print_r($child_cat); echo "</li></pre>";
		$checked = ''; if ($saved_cats) if (array_key_exists($cat->term_id, $saved_cats)) $checked = 'checked = "true"'; 
		echo "<li><label><input type='checkbox'".$checked." name='cat[".$cat->term_id."]' value='".$cat->slug."'>".$cat->name."</label>";
		if ($child_cat) {
			echo "<ul>";
			foreach ($child_cat as $child) {
				$second_child = get_categories(array('taxonomy' => 'product_cat', 'hide_empty' => false,'parent' => $child->term_id));
				$checked = ''; if ($saved_subcats) if (array_key_exists($child->term_id, $saved_subcats)) $checked = 'checked = "true"'; 
				echo "<li><label><input type='checkbox' ".$checked." name='subcat[".$child->term_id."]' value='".$child->slug."'>".$child->name."</label>";
				if ($second_child) {
					echo "<ul>";
					foreach ($second_child as $second) {
						$checked = ''; if ($saved_groups) if (array_key_exists($second->term_id, $saved_groups)) $checked = 'checked = "true"';
						echo "<li><label><input type='checkbox' ".$checked." name='group[".$second->term_id."]' value='".$second->slug."'>".$second->name."</label></li>";
					}
					echo "</ul>";
				}
				echo "</li>";
			}
			echo "</ul>";
		}
		echo "</li>";
	}	
?>
							</ul>
						</div>
					</div>
				</td>
			</tr>
			<tr>
				<th>
					<label for='shipments'>Методы доставки</label>
					<span class='tip'>Если список методов не отображается, нажмите кнопку "Обновить методы" внизу страницы</span>
				</th>
				<td>
					<div id='shipments'>
						<ul>
<?php 
	$shipments = get_option('merlion_shipment');
	$selected_shipment = get_option('merlion_selected_shipment');
	if ($shipments) {
		foreach ($shipments as $key=>$value) {
			$checked = '';
			if ($selected_shipment) if (in_array($key,$selected_shipment)) $checked = 'checked="true"';
			echo "<li><input type='checkbox' ".$checked." name='ships[]' value='".$key."'><span>".$value."</span></li>";
		}		
	}
?>
						</ul>
					</div>
				</td>
			</tr>
			<tr>
<?php 
	$opt = get_option('merlion_article');
	if ($opt) {
		$pre = $opt['prefix'];
		$suf = $opt['suffix'];
	}
?>
				<th>
					<label for='article'>Артикул</label>
				</th>
				<td>
					<div id='article'>
						<label for='prefix'>Префикс</label><input type="text" name="prefix" value='<?php echo $pre ?>' id='prefix'><br />
						<label for='suffix'>Суффикс</label><input type="text" name="suffix" value='<?php echo $suf ?>' id='suffix'><br />
						<span>Пример: </span> <span id='exampleArticle'><?php echo $pre ?>123456<?php echo $suf ?></span> 
					</div>
				</td>
			</tr>
			<tr>
				<th>
					<label for='update'>Обновление товаров по времени</label>
				</th>
				<td>
					<div id='update'>
<?php 
	$update_opt = get_option('merlion_update');
	if ($update_opt) {
		$need_update = ($update_opt['need']) ? 'checked="true"' : '';
		$selected = ($update_opt['type'] == 'hourly') ? 'selected' : '';
	}
		
 ?>
						<input type="checkbox" <?php echo $need_update ?> name="need_update" id="need_update">
						<label for="need_update">Обновлять автоматически</label><br />
						<select name="time" id='time'>
							<option value='hourly' <?php echo $selected; ?> id='hourly'>Каждый час</option>
<?php 
	if ($update_opt) $selected = ($update_opt['type'] == 'twicedaily') ? 'selected' : ''; 
?>
							<option value='twicedaily' <?php echo $selected; ?> id='twicedaily'>Дважды в день</option>
<?php
	if ($update_opt) $selected = ($update_opt['type'] == 'daily') ? 'selected' : '';
?>	
							<option value='daily' <?php echo $selected; ?> id='daily'>Ежедневно</option>
<?php 
	if ($update_opt) $selected = ($update_opt['type'] == 'weekly') ? 'selected' : '';
?>
							<option value='weekly' <?php echo $selected; ?> id='weekly'>Еженедельно</option>
						</select>
					</div>
				</td>
			</tr>
			<tr>
				<th>
					<label for='update_time'>Время начала</label>
				</th>
				<td>
					<div id='update_time'>
						<select name='update_hour'>
<?php 
	if ($update_opt) {
		$hour = date('H',$update_opt['time']);
		$minute = date('i',$update_opt['time']);
		$day_of_week = date('N',$update_opt['time']);
	}
	for ($i=0; $i<24; $i++) {
		if ($hour) $selected = ($hour == $i) ? 'selected' : '';
		echo "<option value='".$i."' ".$selected.">".date("H",mktime($i))."</option>";
	} 
?>	
						</select>
						<select name='update_minute'>
<?php 
	for ($i=0; $i<60; $i++) {
		if ($minute) $selected = ($minute == $i) ? 'selected' : '';
		echo "<option value='".$i."' ".$selected.">".date("i",mktime(0, $i))."</option>";
	} 
?>
						</select>
					</div>
				</td>
			</tr>
			<tr>
<?php 
	if ($update_opt && $update_opt['type'] == 'weekly') $visibility = 'visible'; 
	else $visibility = 'hidden';
?>

				<th>
					<label for='update_day' style="visibility:<?php echo $visibility?>" id="ldow">День недели</label>
				</th>
				<td>
					<select name='update_day' id='update_day' style="visibility:<?php echo $visibility?>">
<?php 
	if ($day_of_week) $selected = ($day_of_week== 1) ? 'selected' : ''; 
?>
						<option value='1' <?php echo $selected; ?>>Понедельник</option>
<?php 
	if ($day_of_week) $selected = ($day_of_week == 2) ? 'selected' : ''; 
?>
						<option value='2' <?php echo $selected; ?>>Вторник</option>
<?php 
	if ($day_of_week) $selected = ($day_of_week == 3) ? 'selected' : ''; 
?>
						<option value='3' <?php echo $selected; ?>>Среда</option>
<?php 
	if ($day_of_week) $selected = ($day_of_week == 4) ? 'selected' : ''; 
?>
						<option value='4' <?php echo $selected; ?>>Четверг</option>
<?php 
	if ($day_of_week) $selected = ($day_of_week == 5) ? 'selected' : ''; 
?>
						<option value='5' <?php echo $selected; ?>>Пятница</option>
<?php 
	if ($day_of_week) $selected = ($day_of_week == 6) ? 'selected' : ''; 
?>
						<option value='6' <?php echo $selected; ?>>Суббота</option>
<?php 
	if ($day_of_week) $selected = ($day_of_week == 7) ? 'selected' : ''; 
?>
						<option value='7' <?php echo $selected; ?>>Воскресенье</option>
					</select>
					</div>
				</td>
			</tr>
			<tr>
				<th>
					<label>Время последнего обновления</label>
				</th>
				<td><?php echo get_option('merlion_last_update'); ?></td>
			</tr>
			<tr><th></th>
				<td>
					<input type='hidden' name='save_merlion' value='true'>
					<input type='submit' value='Сохранить' class='button button-primary button-submit'>
				</td>
			</tr>
		</tbody>
	</table>
</form>

<table class='form-table' style="display:<?php echo $mes ? 'none' : 'block'?>;" >
	<tr>
		<th>
			<form method='post'>
				<input type='hidden' name='download_items' value='true'>
				<input type='submit' value='Загрузить товары' class='button button-primary button-submit'>
			</form>
		</th>
		<td>
			<label>Загрузить данные о товарах</label>
			<span class='tip'>Убедитесь, что выбраны необходимые категории и методы отгрузки товара, а также, что все изменения сохранены</span>
		</td>
	</tr>
	<!--<tr>
		<th>
			<form method='post'>
				<input type='hidden' name='download_properties' value='true'>
				<input type='submit' value='Загрузить характеристики' class='button button-primary button-submit'>
			</form>
		</th>
		<td>
			<label>Загрузить характеристики товаров</label>
			<span class='tip'>Убедитесь, что выбраны необходимые категории, а также, что все изменения сохранены</span>
		</td>
	</tr>
	<tr>
		<th>
			<form method='post'>
				<input type='hidden' name='download_images' value='true'>
				<input type='submit' value='Загрузить изображения' class='button button-primary button-submit'>
			</form>
		</th>
		<td>
			<label>Загрузить изображения</label>
			<span class='tip'>Убедитесь, что выбраны необходимые категории, а также, что все изменения сохранены</span>
		</td>
	</tr>-->
	<tr>
		<th>
			<form method='post'>
				<input type='hidden' name='update_categories' value='true'>
				<input type='submit' value='Обновить категории' class='button button-primary button-submit'>
			</form>
		</th>
		<td>
			<label>Обновить список категорий</label>
		</td>
	</tr>
	<tr>
		<th>
			<form method='post'>
				<input type='hidden' name='shipment_download' value='true'>
				<input type='submit' value='Обновить методы' class='button button-primary button-submit'>
			</form>
		</th>
		<td>
			<label>Обновить список доступных методов доставки</label>
		</td>
	</tr>
</table>
<?php

//echo "<pre>"; print_r($saved_cats); echo "</pre>";
//echo "<pre>"; print_r($saved_subcats); echo "</pre>";
//echo "<pre>"; print_r($saved_groups); echo "</pre>";
}			
?>