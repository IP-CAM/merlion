<?php 
require_once(ABSPATH . "wp-admin" . '/includes/image.php');
require_once(ABSPATH . "wp-admin" . '/includes/file.php');
require_once(ABSPATH . "wp-admin" . '/includes/media.php');
	function get_api_client() {
		ini_set('soap.wsdl_cache_enabled', 0);
		ini_set('soap.wsdl_cache_ttl', 0);
		$wsdl_url = "https://apitest.merlion.com/dl/mlservice3?wsdl";
		$params = array('login' => "MC000143|API",
			'password' => "123456",
			'encoding' => "UTF-8",
			'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
			'cache_wsdl' => WSDL_CACHE_MEMORY,
			'trace'=>true
			);
	return new SoapClient($wsdl_url, $params);
}

	//проверяем существование родительской категории. $сat_id - id категории, $existing -массив с родительскими id
	//возвращает 0, если родительской категории нет в базе
	function cat_exists($cat_id, &$existing, $taxonomy) {
		if (array_key_exists($cat_id, $existing)) {  //если родительская категория есть в масcиве
			return $existing[$cat_id]; // просто берем из массива id
		}
		else { //если родительской категории нет  в массиве
			$parent = term_exists($cat_id, $taxonomy); //проверяем наличие категории в базе
			if ($parent) { //если родительской категория есть в базе
				$existing[$cat_id] = $parent['term_id']; //добавляем ее в массив с существующими id
				return $parent['term_id'];
			}
		}
		return 0;
	}
	
	function restart_load_categories($categories, &$existing_cat, $start_pos) {
		part_categories($categories, $existing_cat, $start_pos);
	}
	
	function part_categories($categories, &$existing_cat, $start_pos) {
		$start_time = time();
		for ($i = $start_pos; $i<count($categories); $i++) {
			if (!term_exists($categories[$i]->ID, 'product_cat')) { //если категория не добавлена в базу
					if ($categories[$i]->ID == 'Order') { 
						$parent_id = 0;
					}
					$parent_id = cat_exists($categories[$i]->ID_PARENT, $existing_cat, 'product_cat');
				
					wp_insert_category(array(
						'cat_name' => $categories[$i]->Description, // название категории - название товарной группы
						'category_nicename' => $categories[$i]->ID, // ярлык (slug) - код группы
						'category_parent'=> $parent_id, // родительская категория
						'taxonomy' => 'product_cat'));
			}
			if ((time() - $start_time) > 20) {
				restart_load_categories($categories, $existing_cat, ++$i);
				break;
			}
		}
	}
	
	function sort_cat($a, $b) {
		return ($a->ID < $b->ID) ? -1 : 1;
		return 0;
	}
	
	function load_categories($category) { 
		$client = get_api_client();
		$id_existing_cat = array();
		if ($client) {
			$cats = $client->getCatalog(array('cat_id' =>$category));
			if ($cats) {
				$arr = $cats->getCatalogResult->item;
				usort($arr, "sort_cat");
				part_categories($arr, $id_existing_cat, 0);
			}
		}
		else { // если клиент не создан
			echo "<pre>"; print_r($client); echo "</pre>";
		}
		
	}
	
	//добавляем префикс к артикулу. $num - артикул
	function get_article($num) {
		$article = get_option('merlion_article');
		
		return $article['prefix'].$num.$article['suffix'];
	}
	
	//получаем список методов доставки
	function load_shipments_methods() {
		$client = get_api_client();
		if ($client) {
			$ships = $client->getShipmentMethods();
			$as_array = array();
			foreach ($ships->getShipmentMethodsResult->item as $sh) {
				$as_array[$sh->Code] = $sh->Description;
			}
		}
		else { // если клиент не создан
			echo "<pre>"; print_r($client); echo "</pre>";
		}
		return $as_array;
	}
	
	//проверяем существование продукта сначала в масиве $prod_id_array(продуктов, данные о которых уже были получены раньше)
	//если нету, то в базе по номеру артикула $item_no
	function item_exists($item_no, &$prod_id_array) {
		$item_id = 1;
		if (!array_key_exists($item_no, $prod_id_array)) {
			$product = get_posts(array(
				'post_type' => 'product',
				'meta_key' => '_sku',
				'meta_value' => get_article($item_no)));
			if ($product) { $prod_id_array[$item_no] = $product[0]->ID; }
			else { $item_id = 0; }
		} 
		return $item_id;
	}
	
	//добавляем атрибут в таблицу woocommerce
	//$id - уникальное название таксономии 
	//$name - имя таксономии
	function insert_attribute_wc($id, $name) {
		global $wpdb;
		
		$ex = $wpdb->get_var($wpdb->prepare("SELECT attribute_id
			FROM wp_woocommerce_attribute_taxonomies
			WHERE attribute_name = '%s'", $id));
		if (!$ex) {
			$attribute = array(
				'attribute_name' => $id, 
				'attribute_label'=> $name, 
				'attribute_type'=>'text', 
				'attribute_public' => 0, 
				'attribute_orderby' => 'menu_order');
		//echo '<pre>'; print_r($attribute); echo'</pre>';
			$insert_id = $wpdb->insert('wp_woocommerce_attribute_taxonomies', $attribute);
			if ($insert_id) {
				do_action( 'woocommerce_attribute_added', $insert_id, $attribute);			
				flush_rewrite_rules();
				delete_transient('wc_attribute_taxonomies');
			}
		}
	}
	
	//регистрируем новую таксономию
	//$id - уникальное название таксономии 
	//$name - имя таксономии
	function add_new_taxonomy($id, $name) {
		if (!taxonomy_exists('pa_'.$id)) {
			$args = array(
				'labels' => array('name' =>  $name),
				'hierarchical' => true, 'public' => false,
				'rewrite' => false, 
				'update_count_callback' => '_update_post_term_count',
				'capabilities' => array('manage_terms' => 'manage_product_terms',
            				'edit_terms' => 'edit_product_terms',
					'delete_terms' => 'delete_product_terms',
					'assign_terms' => 'assign_product_terms')
			);
			register_taxonomy($id, 'product', $args);
		}
	}
	
	function set_category($item_id, $code, $ch = false) {
		wp_set_object_terms($item_id, $code, 'product_cat', $ch);
	}
	
	function add_category($id, $slug) {
		global $wpdb;
		$ttid = $wpdb->get_var($wpdb->prepare("
			SELECT tt.term_taxonomy_id FROM wp_terms tr
			JOIN wp_term_taxonomy tt ON tr.term_id = tt.term_id
			WHERE slug =  '%s'
			", $slug));
		if ($ttid) {
			$ex = $wpdb->get_var($wpdb->prepare("
				SELECT object_id FROM $wpdb->term_relationships 
				WHERE object_id = %d AND term_taxonomy_id = %d
				", $id, $ttid));
			if (!$ex) $wpdb->insert("$wpdb->term_relationships", array('object_id'=>$id, 'term_taxonomy_id'=>$ttid), array('%d', '%d'));
		}
	}
	
	function set_meta ($item_id, $item_no) {
		update_post_meta($item_id, '_sku', get_article($item_no));
		update_post_meta($item_id, '_visibility', 'visible');
		update_post_meta($item_id, '_stock_status', 'outofstock');
	}
	
	//устанавливаем основную информацию о прдукте (артикул, видимость, бренд, категории)
	//$item - товар (объект, то, что получено при выгрузке)
	//$item_id - его id в базе
	function set_main_information($item, $item_id) {
		set_meta($item_id, $item->No);
		//update_post_meta($item_id, '_last_time_modified',$item->Last_time_modified);
		
		wp_set_object_terms($item_id, $item->Brand, 'product_brand');
				
		add_category($item_id, $item->GroupCode1);
		add_category($item_id, $item->GroupCode2); 
		add_category($item_id, $item->GroupCode3);
	}
	
	function restart_find_avail($items, &$prod_id, &$avail_count, $start_pos) {
		find_avail ($items, $prod_id, $avail_count, $start_pos);
	}
	
	function find_avail ($items, &$prod_id, &$avail_count, $start_pos) {
		$start_time = time();
		for ($i = $start_pos; $i<count($items);$i++) {
			if (!array_key_exists($items[$i]->No, $avail_count)) {
				$avail_count[$items[$i]->No] = array();
				$avail_count[$items[$i]->No]['price'] = 0;
				$avail_count[$items[$i]->No]['count'] = 0;
			}
				
			if ($avail_count[$items[$i]->No]['price'] < $items[$i]->PriceClientRUB)
				$avail_count[$items[$i]->No]['price'] = $items[$i]->PriceClientRUB;
			$avail_count[$items[$i]->No]['count'] += $items[$i]->AvailableClient;
			
			error_log(current_time('mysql')." Added avail ".$i.": ".$items[$i]->No." Price: ".$items[$i]->PriceClientRUB." Count:".$items[$i]->AvailableClient." \r\n", 3, "connect.log");
			if ((time() - $start_time) > 20) {
				restart_find_avail($items, $prod_id, $avail_count, ++$i);
				break;
			}
		}
	}	
	
	
	
	
	
	//устанавливаем цены и наличие товара
	//$client - клиент, 
	//$cat_id - категория (если нужны данные об одном товаре, то передать 0), 
	//$prod_id - ассоциативный массив: ключ - артикул, значение - id в бд, для более быстрого получения id, передается по ссылке 
	
	
	function repeat_set_prop($properties, $start_pos) {
		part_set_prop($properties, $start_pos);
	}
	
	function part_set_prop($properties, $start_pos) {
		$start_time = time(); $prod_id = array();
		for ($i = $start_pos; $i<count($properties); $i++) {
			
			if (item_exists($properties[$i]->No, $prod_id)) {
				//$date_exist = array_key_exists($properties[$i]->No, $date_mod);
				//if ($date_exist &&  $date_mod[$properties[$i]->No] <= $properties[$i]->Last_time_modified || !$date_exist) {
					$product_attributes = get_post_meta($prod_id[$properties[$i]->No], '_product_attributes', true);
					//if (!taxonomy_exists('pa_'.$properties[$i]->PropertyID)) {
						insert_attribute_wc($properties[$i]->PropertyID, $properties[$i]->PropertyName);
						add_new_taxonomy('pa_'.$properties[$i]->PropertyID, $properties[$i]->PropertyName);
					//}
					$product_attributes['pa_'.$properties[$i]->PropertyID] = array(
						'name' => 'pa_'.$properties[$i]->PropertyID,
						'is_visible' => 1,
						'is_variation' => 0,
						'is_taxonomy' => 1);
					update_post_meta($prod_id[$properties[$i]->No], '_product_attributes', $product_attributes);
					wp_set_object_terms($prod_id[$properties[$i]->No], $properties[$i]->Value, 'pa_'.$properties[$i]->PropertyID);			
				//}
				
			}
			error_log(current_time('mysql')." Added properties ".$i.": ".$properties[$i]->No.": ".$properties[$i]->PropertyName." \r\n", 3, "connect.log");
			if ((time()- $start_time) > 25) {
				repeat_set_prop($properties, ++$i);
				break;
			}
		}
	}
	
	
	
	function download_img($filename ) {
		$link = "http://img.merlion.ru/items/".$filename;
		$file_array = array();
						
		$tmp = download_url($link);
		error_log(current_time('mysql')." Download http://img.merlion.ru/items/".$filename."\r\n", 3, "connect.log");
		//echo "<pre>"; print_r($tmp); echo "</pre>";
		if (is_wp_error($tmp)) {
			$mes = '';
			foreach( $tmp->get_error_messages() as $error ){
				$mes .= $error." ";
			}
			update_option('merlion_image_error', '<div id="message" class="error"><p>Не удаётся загрузить изображения: '.$mes.'</p></div>');
			update_option('merlion_current_download', '');
			//$next = wp_next_scheduled('merlion_images_hook');
			//wp_unschedule_event($next, 'merlion_images_hook');
			return 0;
		}
		else {
			preg_match('/[^\?]+\.(jpg|jpe|jpeg|gif|png)/i', $link, $matches );
			$file_array['name'] = basename( $matches[0] );
			$file_array['tmp_name'] = $tmp;
						
			$res = media_handle_sideload($file_array, 0);
							
			if( is_wp_error( $res ) ) {
				@unlink($tmp);
				$mes = '';
				foreach( $res->get_error_messages() as $error ){
					$mes .= $error." ";
				}
				update_option('merlion_image_error', '<div id="message" class="error"><p>Не удаётся загрузить изображения: '.$mes.'</p></div>');
				update_option('merlion_current_download', '');
				//$next = wp_next_scheduled('merlion_images_hook');
				//wp_unschedule_event($next, 'merlion_images_hook');
				return 0;
			}
							
			@unlink( $file_array['tmp_name']);
			return $res;
			
		}
	}
	
	function picture_exists($name) {
		$subname = substr($name,0,strripos($name,'_'));
		global $wpdb;
		$id = $wpdb->get_var(
			"SELECT ID FROM $wpdb->posts
			WHERE post_type = 'attachment' AND
			post_title LIKE '$subname%'");
		if ($id) return $id;
		else return 0;
	}
	
	function restart_set_image(&$picts_id, &$prod_id, $start_pos) {
		error_log(current_time('mysql')." Restart $start_pos \r\n", 3, "connect.log");
		$res = part_set_image($picts_id, $prod_id, $start_pos);
		return $res;
	}
	
	function part_set_image(&$picts_id, &$prod_id, $start_pos) {
		$start_time = time();  
		$total = get_option('merlion_total_images');
		$items = get_option('merlion_download_images');
		$cur_sec = get_option('merlion_current_second');
		$request = get_option('merlion_count_request');
		
		if (!is_array($request)) $request = array();
		$count = 0; 
		$begin = time();
		for ($i = $start_pos; $i < count($items); $i++) {
			if (item_exists($items[$i]->No, $prod_id)){
				
				if (!array_key_exists($items[$i]->No, $picts_id)) {
					if (get_post_meta($prod_id[$items[$i]->No],'_product_image_gallery',true))
						$picts_id[$items[$i]->No] = split(',', get_post_meta($prod_id[$items[$i]->No],'_product_image_gallery',true) ); 
					else $picts_id[$items[$i]->No] = array();
				}
					
				$pic_exists = picture_exists( $items[$i]->FileName);	
				if (!$pic_exists) { 
					$total++;
					if ($total == 290) return -1;
					if ((array_sum($request)+$count) >= 119) {
						$request[$cur_sec] = $count; $cur_sec = $cur_sec == 9 ? 0 : $cur_sec + 1;
						$begin = time();
						sleep(1); $count = 0;
					}
					$count++; 
					error_log(current_time('mysql')." Total $total \r\n", 3, "connect.log");
					$res = download_img($items[$i]->FileName);
					if ($res) {
						error_log(current_time('mysql')." Added image ".$i.": ".$items[$i]->No.": ".$items[$i]->FileName." \r\n", 3, "connect.log");
						$picts_id[$items[$i]->No][] = $res;
					}
					//else return 'error';
				}
				else { if (!in_array($pic_exists, $picts_id[$items[$i]->No])) 
						$picts_id[$items[$i]->No][] = $pic_exists;
				}
			}
			if (time() - $begin == 1) {
				$request[$cur_sec] = $count;
				$cur_sec = $cur_sec == 9 ? 0 : $cur_sec + 1;
				$begin = time();
			}
			if ((time() - $start_time)> 20) {
				update_option('merlion_total_images', $total);
				update_option('merlion_current_second', $cur_sec);
				update_option('merlion_count_request', $request);
				$s = restart_set_image($picts_id, $prod_id, ++$i);
				return $s;
			}
		}
		update_option('merlion_current_second', $cur_sec);
		update_option('merlion_count_request', $request);
	}	

	function get_id_by_sku($sku) {
		global $wpdb;
		$post_id = $wpdb->get_var($wpdb->prepare("
			SELECT post_id FROM $wpdb->postmeta meta JOIN $wpdb->posts post ON meta.post_id = post.ID
			WHERE meta.meta_key='_sku' AND meta.meta_value='%s' AND post.post_status = 'publish'", $sku));
		if ($post_id) return $post_id;
		return 0;
	}
	
	function restart_load_items($items, &$prod_id, &$date_mod, $start_pos) {
		part_items($items, $prod_id, $date_mod, $start_pos);
	}
	
	function create_item($item, &$prod_id) {
		if (!$item->EOL) {
			$new_item_id = wp_insert_post(array(
						'post_title' => $item->Name, 
						'post_type' => 'product',
						'post_name' => str_replace(' ', '-', strtolower($item->Name)),
						'post_status' => 'publish' 	
			));
			$prod_id[$item->No] = $new_item_id;
			set_main_information($item, $new_item_id);
			update_post_meta($new_item_id, '_last_time_modified',$item->Last_time_modified);
		}
	}
	
	function part_items($items, &$prod_id, &$date_mod, $start_pos) {
		$start_time = time();
		
		for ($i = $start_pos; $i<count($items) && $i<$start_pos + 30; $i++) {
			$item_id = get_id_by_sku(get_article($items[$i]->No));
			if ($item_id) {
				$prod_id[$items[$i]->No] = $item_id;
				$date_mod[$items[$i]->No] = get_post_meta($item_id, '_last_time_modified',true);
				if ($date_mod[$items[$i]->No] < $items[$i]->Last_time_modified) { //если дата последнего изменения товара в бд меньше, чем дата изменения выгруженного товара, то обновляем
					set_main_information($items[$i], $item_id);
					update_post_meta($item_id, '_last_time_modified',$items[$i]->Last_time_modified);
				}
			}
			else { //новый продукт добавляем в базу
				create_item($items[$i], $prod_id);
			}
			if ((time()- $start_time)> 25 || $i == ($start_pos + 29)) {
				restart_load_items($items, $prod_id, $date_mod, ++$i);
				break;
			}
			error_log(current_time('mysql')." Added item ".$i.": ".$prod_id[$items[$i]->No].": ".$items[$i]->No." \r\n", 3, "connect.log");
		}
	}
	
	function load_more_information() {
		$client = get_api_client();
		error_log(current_time('mysql').' Create Client '.$client->sdl."\r\n", 3, "connect.log");
		if ($client) {
			$dates = get_option('merlion_shipment_dates');
			if (!$dates || date('d-m-Y', time())>$dates['update_date']) {
				if ((time() - $dates['update_time']) > 60 ) {
					$download_dates = $client->getShipmentDates();
					update_option('merlion_shipment_dates', array('update_date' => date('d-m-Y', time()), 'update_time' => time(),'date' => $download_dates->getShipmentDatesResult->item[1]->Date));
					$dates = get_option('merlion_shipment_dates');
				}
			}
			
			if ($dates['date']) {
				
				$data = get_option('merlion_load_categories');
				$cats = json_decode($data['cats']);
				$i = $data['index'];
				
				$shipments = get_option('merlion_selected_shipment');
				$avail_count = array(); $prod_id = array();
				if ($shipments) {
					foreach ($shipments as $ship) {
						$avail = $client->getItemsAvail(array(
								'cat_id' => $cats[$i], 
								'shipment_method' => $ship, 
								'shipment_date' => $dates['date'],
								'only_avail' => '0'));
						if ($avail) {
							update_option('merlion_last_item_add', time());
							find_avail($avail->getItemsAvailResult->item, $prod_id, $avail_count, 0);
						}
					}
					update_option('merlion_update_avail', $avail_count);
					wp_schedule_single_event(time(), 'merlion_set_avail_hook');
				}
			}
		}
	}
	
	function restart_part_avail(&$avail_count, &$prod_id) {
		part_avail($avail_count, $prod_id);
	}
	
	function part_avail($key, $val) {		
		//$start_time = time();
		
			if (item_exists($key, $prod_id)) {
				if ($val['price']>0) update_post_meta($prod_id[$key], '_regular_price', $val['price']);
				else update_post_meta($prod_id[$key], '_regular_price', '');
				if ($val['count'] >0) update_post_meta($prod_id[$key], '_stock_status', 'instock');
				update_post_meta($prod_id[$key], '_stock', $val['count']);
				wp_update_post(array('ID'=>$prod_id[$key]));
				error_log(current_time('mysql')." Set avail ".$key." Price: ".$val['price']." Count:".$val['count']." \r\n", 3, "connect.log");
			}
			
			/*if ((time() - $start_time) > 20) {
				restart_part_avail($avail_count, $prod_id);
				return;
			}*/
		
	}
	
	function set_available() {
		
		$avail_count = get_option('merlion_update_avail');		
		if ($avail_count) {
			update_option('merlion_last_item_add', time());
			$i = 0;
			foreach ($avail_count as $key=>$val) {
				part_avail($key, $val);
				unset($avail_count[$key]);
				if (!$avail_count || ++$i == 200) break;
			}
			if ($avail_count) { 
				update_option('merlion_update_avail', $avail_count);
				wp_schedule_single_event(time(), 'merlion_set_avail_hook');
				exit;
			}
		}
		$data = get_option('merlion_load_categories');
		$cats = json_decode($data['cats']);
		if ($data['index'] == count($cats)-1) {
			update_option('merlion_load_categories', array('cats' => $data['cats'], 'index' => 0, 'page' => 1, 'rows_on_page' => 500));
			update_option('merlion_current_download', 'характеристик');
			wp_schedule_single_event(time(), 'merlion_properties_hook');
		}
		else {
			$page = 1;
			update_option('merlion_load_categories', array('cats' => $data['cats'], 'index' =>$data['index']+1 , 'page' => 1, 'rows_on_page' => 150));
			wp_schedule_single_event(time(), 'merlion_download_hook');
		}
	}
	
	function load_page_items($client,$cat_id, $page, $rows_on_page, &$prod_id, &$date_mod) {
		$items = $client->getItems(array('cat_id' => $cat_id, 'rows_on_page' => $rows_on_page, 'page' => $page));
				//echo "<pre>";print_r($items);echo "</pre>";	
				//echo count($items->getItemsResult->item)."<br />";
		error_log(current_time('mysql')." Request Page $page Rows on page $rows_on_page \r\n".$client->__getLastRequestHeaders()."\r\n", 3, "connect.log");
		error_log(current_time('mysql')." Response \r\n".$client->__getLastResponseHeaders()."\r\n", 3, "connect.log");
		if ($items) {
			part_items($items->getItemsResult->item, $prod_id, $date_mod, 0);
			return count($items->getItemsResult->item);
		}
		return 0;
	}
	
	//загружаем продукты
	function load_items() {
		$client = get_api_client();
		error_log(current_time('mysql').' Create Client '.$client->sdl."\r\n", 3, "connect.log");
		if ($client) {
			$prod_id = array(); $date_mod = array();
			$data = get_option('merlion_load_categories');
			
			$cats = json_decode($data['cats']);
			$i = $data['index'];
			$page = $data['page']; 
			$rows_on_page = $data['rows_on_page'];
			
			if ($cats[$i]) {
					$cat = get_term_by( 'slug', $cats[$i], 'product_cat' );
					error_log(current_time('mysql')." Download items of ".$cat->name."\r\n", 3, "connect.log");
		
					update_option('merlion_current_download', 'товаров категории "'.$cat->name.'"');
					$items = load_page_items($client, $cats[$i], $page, $rows_on_page, $prod_id, $date_mod);
					update_option('merlion_last_item_add', time());
					error_log(current_time('mysql').' Download '.$items."\r\n", 3, "connect.log");
					
					if ($items == $rows_on_page) { 
						$page++; 
						update_option('merlion_load_categories', array('cats' => $data['cats'], 'index' => $i, 'page' => $page, 'rows_on_page' => $rows_on_page));
						wp_schedule_single_event(time(), 'merlion_download_hook');
					}
					else {
						wp_schedule_single_event(time(), 'merlion_add_avail_hook');
						//if (!get_option('merlion_update_avail'))
							//load_more_information($client, $cats[$i], $prod_id, $date_mod);
						//update_option('merlion_update_avail', false);
						
						/*$i++; 
						if ($i >= count($cats)) {
						//update_option('merlion_last_update', date("d-m-Y H:i:s", current_time('timestamp')));
							update_option('merlion_load_categories', array('cats' => $data['cats'], 'index' => 0, 'page' => 1, 'rows_on_page' => 1500));
							update_option('merlion_current_download', 'характеристик');
							wp_schedule_single_event(time(), 'merlion_properties_hook');
						}
						else {
							$page = 1;
							update_option('merlion_load_categories', array('cats' => $data['cats'], 'index' => $i, 'page' => $page, 'rows_on_page' => $rows_on_page));
							wp_schedule_single_event(time(), 'merlion_download_hook');
						}*/
					}
				//}
			}
		}
	}
	
	//устанавливаем характеристики товара
	//$client - клиент, 
	//$cat_id - категория (если нужны данные об одном товаре, то передать 0), 
	//$prod_id - ассоциативный массив: ключ - артикул, значение - id в бд, для более быстрого получения id, передается по ссылке 
	//$date_mod - ассоциативный массив: ключ - артикул, значение - дата последнего обновления, передается по ссылке
	//$item_no = 0 - если нужно получить инф-ю выборочно о продуктах, если указан $cat_id, то не учитывается
	function set_properties($client, $cat_id, $rows_on_page, $page) {
		$properties = $client->getItemsProperties(array('cat_id' => $cat_id, 'rows_on_page' => $rows_on_page, 'page' => $page));
			//echo "<pre>"; print_r($properties); echo "</pre>";
		error_log(current_time('mysql')." Request Page $page Rows on page $rows_on_page \r\n".$client->__getLastRequestHeaders()."\r\n", 3, "connect.log");	
		error_log(current_time('mysql')." Response \r\n".$client->__getLastResponseHeaders()."\r\n", 3, "connect.log");
		if ($properties) { 
				//echo "<pre>"; print_r($properties); echo "</pre>";
				part_set_prop($properties->getItemsPropertiesResult->item, 0);
				return count($properties->getItemsPropertiesResult->item);
		}
		return 0;
	}
	
	function load_properties() {
		$client = get_api_client();
		error_log(current_time('mysql').' Create Client '.$client->sdl."\r\n", 3, "connect.log");
		if ($client) {
			$prod_id = array(); $date_mod = array();
			$data = get_option('merlion_load_categories');
			
			$cats = json_decode($data['cats']);
			$i = $data['index'];
			$page = $data['page']; 
			$rows_on_page = $data['rows_on_page'];
			
			if ($cats[$i]) {
				$cat = get_term_by( 'slug', $cats[$i], 'product_cat' );
					error_log(current_time('mysql')." Download properties of ".$cat->name."\r\n", 3, "connect.log");
		
					update_option('merlion_current_download', 'характеристик категории "'.$cat->name.'"');
					$items = set_properties($client, $cats[$i], $rows_on_page, $page);
					update_option('merlion_last_item_add', time());
					error_log(current_time('mysql').' Download '.$items."\r\n", 3, "connect.log");
					if ($items == $rows_on_page) { 
						$page++; 
						update_option('merlion_load_categories', array('cats' => $data['cats'], 'index' => $i, 'page' => $page, 'rows_on_page' => $rows_on_page));
						wp_schedule_single_event(time(), 'merlion_properties_hook');
					}
					else {
						$i++; 
						if ($i >= count($cats)) {
							//update_option('merlion_last_update', date("d-m-Y H:i:s", current_time('timestamp')));
							update_option('merlion_load_categories', array('cats' => $data['cats'], 'index' => 0, 'page' => 1, 'rows_on_page' => 500));
							update_option('merlion_current_download', 'изображений');
							update_option('merlion_current_second', 0);
							wp_schedule_single_event(time(), 'merlion_images_hook');
							
						}
						else {
							$page = 1;
							update_option('merlion_load_categories', array('cats' => $data['cats'], 'index' => $i, 'page' => $page, 'rows_on_page' => $rows_on_page));
							wp_schedule_single_event(time(), 'merlion_properties_hook');
						}
					}
				//}
			}
		}
	}
	
	//устанавливаем миниатюру и галерею
	//$client - клиент, 
	//$cat_id - категория (если нужны данные об одном товаре, то передать 0), 
	//$prod_id - ассоциативный массив: ключ - артикул, значение - id в бд, для более быстрого получения id, передается по ссылке 
	//$item_no = 0 - если нужно получить инф-ю выборочно о продуктах, если указан $cat_id, то не учитывается
	function set_pictures($client, $cat_id, &$prod_id, &$picts_id, $rows_on_page, $page) {
		
		$pictures = $client->getItemsImages(array('cat_id' => $cat_id, 'rows_on_page' => $rows_on_page, 'page' => $page));
		error_log(current_time('mysql')." Request Page $page Rows on page $rows_on_page \r\n".$client->__getLastRequestHeaders()."\r\n", 3, "connect.log");
		error_log(current_time('mysql')." Response \r\n".$client->__getLastResponseHeaders()."\r\n", 3, "connect.log");
		update_option('merlion_download_images', $pictures->getItemsImagesResult->item);
		update_option('merlion_prop_count',count($pictures->getItemsImagesResult->item));
		if (count($pictures->getItemsImagesResult->item)) {
				//echo "<pre>"; print_r($pictures); echo "</pre>";
			update_option('merlion_total_images', 5);
			$res = part_set_image($picts_id, $prod_id, 0);
			if ($res == -1 || $res=='error') return $res;
			return count($pictures->getItemsImagesResult->item);
		}
		return 0;
	}
	
	function load_images() {
		$client = get_api_client();
		error_log(current_time('mysql').' Create Client '.$client->sdl."\r\n", 3, "connect.log");
		if ($client) {
			$prod_id = array(); $picts_id = array();
			$data = get_option('merlion_load_categories');
			if ($data) {
				$cats = json_decode($data['cats']);
				$i = $data['index'];
				$page = $data['page']; 
				$rows_on_page = $data['rows_on_page'];
			
				if ($cats[$i]) {
					$cat = get_term_by( 'slug', $cats[$i], 'product_cat' );
					error_log(current_time('mysql')." Download images of ".$cat->name."\r\n", 3, "connect.log");
		
					update_option('merlion_current_download', 'изображений категории "'.$cat->name.'"');
						$items = set_pictures($client, $cats[$i], $prod_id, $picts_id, $rows_on_page, $page);
						update_option('merlion_last_item_add', time());
						error_log(current_time('mysql').' Download '.$items."\r\n", 3, "connect.log");
						error_log(current_time('mysql').' Prods_id '.count($prod_id)."\r\n", 3, "connect.log");
						error_log(current_time('mysql').' Picts_id '.count($picts_id)."\r\n", 3, "connect.log");
						foreach ($picts_id as $key=>$value) {
							if ($key && $value) {
								update_post_meta($prod_id[$key], '_product_image_gallery', implode(",",$value));
								update_post_meta($prod_id[$key], '_thumbnail_id', $value[0]);				
							}
						}	
						if ($items == -1) {
							if (!count($picts_id))
								update_option('merlion_load_categories', array('cats' => $data['cats'], 'index' => $i, 'page' => ++$page, 'rows_on_page' => $rows_on_page));
							wp_schedule_single_event(time(), 'merlion_images_hook');
						}
						elseif ($items == 'error') {
							update_option('merlion_current_download', '');
							update_option('merlion_download_images', '');
							update_option('merlion_load_categories', array('cats' => $data['cats'], 'index' => 0, 'page' => 0, 'rows_on_page' => 0));
						}
						elseif ($items == $rows_on_page) { 
							$page++; 
							update_option('merlion_load_categories', array('cats' => $data['cats'], 'index' => $i, 'page' => $page, 'rows_on_page' => $rows_on_page));
							wp_schedule_single_event(time(), 'merlion_images_hook');
						}
						else {
							$i++; 
							if ($i >= count($cats)) {
								update_option('merlion_last_update', current_time('mysql'));
								update_option('merlion_load_categories', array('cats' => $data['cats'], 'index' => 0, 'page' => 0, 'rows_on_page' => 0));
								update_option('merlion_current_download', '');
								update_option('merlion_download_images', '');
								//$next = wp_next_scheduled('merlion_images_hook');
								//wp_unschedule_event($next, 'merlion_images_hook');
							}
							else {
								$page = 1;
								update_option('merlion_load_categories', array('cats' => $data['cats'], 'index' => $i, 'page' => $page, 'rows_on_page' => $rows_on_page));
								wp_schedule_single_event(time(), 'merlion_images_hook');
							}
						}
					//}
				}
			}
		}
	}
	
?>