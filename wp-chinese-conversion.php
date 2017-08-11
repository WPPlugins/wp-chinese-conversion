<?php
/*
Plugin Name: WP Chinese Conversion
Plugin URI: https://oogami.name/project/wpcc/
Description: Adds the language conversion function between Chinese Simplified and Chinese Traditional to your WP Blog.
Version: 1.1.15
Author: Ono Oogami
Author URI: https://oogami.name/
*/

/*
Copyright (C) 2009-2013 Ono Oogami, https://oogami.name/ (ono@oogami.name)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

/**
 * WP Chinese Conversion Plugin main file
 *
 * 為Wordpress增加中文繁簡轉換功能. 轉換過程在服務器端完成. 使用的繁簡字符映射表來源于Mediawiki.
 * 本插件比较耗费资源. 因为对页面内容繁简转换时载入了一个几百KB的转换表(ZhConversion.php), 编译后占用内存超过1.5MB
 * 如果可能, 建议安装xcache/ eAccelerator之类PHP缓存扩展. 可以有效提高速度并降低CPU使用,在生产环境下测试效果非常显著.
 *
 * @package WPCC
 * @version see WPCC_VERSION constant below
 * @TODO 用OO方式重寫全部代碼, 計劃1.2版本實現.
 * @link http://plugins.svn.wordpress.org/wp-chinese-conversion/ SVN Repository
 * @link http://wordpress.org/extend/plugins/wp-chinese-conversion/ Plugin Page on wordpress.org, including guides and docs.
 * @link https://oogami.name/project/wpcc/ Plugin Homepage
 *
 */

//define('WPCC_DEBUG', true); $wpcc_deubg_data = array(); //uncomment this line to enable debug
define('WPCC_ROOT_URL', WP_PLUGIN_URL . '/' . str_replace(basename( __FILE__), "", plugin_basename(__FILE__)));
define('WPCC_VERSION', '1.1.15');

$wpcc_options = get_option('wpcc_options');
// /**********************
//初始化所有全局變量.其实不初始化也没关系,主要是防止某些古董php版本register_globals打开可能造成意想不到问题.
$wpcc_admin = false;
$wpcc_noconversion_url = false;
$wpcc_redirect_to = false;
$wpcc_direct_conversion_flag = false;
$wpcc_langs_urls = array();
$wpcc_target_lang = false;
// ***************************/

//你可以更改提示文字,如"简体中文","繁体中文".但是不要改动其它.
//不要改键值的语言代码zh-xx, 本插件一些地方使用了硬编码的语言代码.
$wpcc_langs = array(
	'zh-hans' => array('zhconversion_hans', 'hanstip', '简体中文','zh-Hans'),
	'zh-hant' => array('zhconversion_hant', 'hanttip', '繁體中文','zh-Hant'),
	'zh-cn' => array('zhconversion_cn', 'cntip', '大陆简体','zh-CN'),
	'zh-hk' => array('zhconversion_hk', 'hktip', '港澳繁體','zh-HK'),
	'zh-mo' => array('zhconversion_hk', 'motip', '澳門繁體','zh-MO'),
	'zh-sg' => array('zhconversion_sg', 'sgtip', '马新简体','zh-SG'),
	'zh-my' => array('zhconversion_sg', 'mytip', '马来西亚简体','zh-MY'),
	'zh-tw' => array('zhconversion_tw', 'twtip', '台灣正體','zh-TW'),
);

//容错处理.
if( $wpcc_options != false && is_array($wpcc_options) && is_array($wpcc_options['wpcc_used_langs']) ) {
	add_action('widgets_init', create_function('', 'return register_widget("Wpcc_Widget");'));
	add_filter('query_vars', 'wpcc_insert_query_vars');//修改query_vars钩子,增加一个'variant'公共变量.
	add_action('init', 'wpcc_init');//插件初始化

	if(
		WP_DEBUG ||
		( defined('WPCC_DEBUG') && WPCC_DEBUG == true )
	) {
		add_action('init', create_function('', 'global $wp_rewrite; $wp_rewrite->flush_rules();'));
		add_action('wp_footer', 'wpcc_debug');
	}
}

add_action('admin_menu', 'wpcc_admin_init');//插件后台菜单钩子


/* 全局代码END; 下面的全是函数定义 */

/**
 * 插件初始化
 *
 * 本函数做了下面事情:
 * A. 调用wpcc_get_noconversion_url函数设置 $wpcc_noconversion_url全局变量
 * A. 调用wpcc_get_lang_url函数设置 $wpcc_langs_urls全局(数组)变量
 * B. 如果当前为POST方式提交评论请求, 直接调用wpcc_do_conversion
 * B. 否则, 加载parse_request接口
 */
function wpcc_init() {
	global $wpcc_options, $wp_rewrite;

	if( $wpcc_options['wpcc_use_permalink'] !=0 && empty($wp_rewrite->permalink_structure) ) {
		$wpcc_options['wpcc_use_permalink'] = 0;
		update_option('wpcc_options', $wpcc_options);
	}
	if( $wpcc_options['wpcc_use_permalink'] != 0 )
		add_filter('rewrite_rules_array', 'wpcc_rewrite_rules');

	if( (strpos($_SERVER['PHP_SELF'], 'wp-comments-post.php') !== false
		|| strpos($_SERVER['PHP_SELF'], 'ajax-comments.php') !== false
		|| strpos($_SERVER['PHP_SELF'], 'comments-ajax.php') !== false
		) &&
		$_SERVER["REQUEST_METHOD"] == "POST" &&
		!empty($_POST['variant']) && in_array($_POST['variant'], $wpcc_options['wpcc_used_langs'])
	) {
		global $wpcc_target_lang;
		$wpcc_target_lang = $_POST['variant'];
		wpcc_do_conversion();
		return;
	}

	if( 'page' == get_option('show_on_front') && get_option('page_on_front') )
		add_action('parse_query', 'wpcc_parse_query_fix');
	add_action('parse_request', 'wpcc_parse_query');
	add_action('template_redirect', 'wpcc_template_redirect', -100);//本插件核心代码.
}

/**
 * 修复首页显示Page时繁简转换页仍然显示最新posts的问题
 * dirty but should works
 * based on wp 3.5
 * @since 1.1.13
 * @see wp-include/query.php
 *
 */
function wpcc_parse_query_fix($this_WP_Query) {

		//copied and modified from wp-includes/query.php
		$qv = &$this_WP_Query->query_vars;

		// Correct is_* for page_on_front and page_for_posts
		if ( $this_WP_Query->is_home && 'page' == get_option('show_on_front') && get_option('page_on_front') ) {
			$_query = wp_parse_args($this_WP_Query->query);
			// pagename can be set and empty depending on matched rewrite rules. Ignore an empty pagename.
			if ( isset($_query['pagename']) && '' == $_query['pagename'] )
				unset($_query['pagename']);
			if ( empty($_query) || !array_diff( array_keys($_query), array('preview', 'page', 'paged', 'cpage', 'variant') ) ) {
				$this_WP_Query->is_page = true;
				$this_WP_Query->is_home = false;
				$qv['page_id'] = get_option('page_on_front');
				// Correct <!--nextpage--> for page_on_front
				if ( !empty($qv['paged']) ) {
					$qv['page'] = $qv['paged'];
					unset($qv['paged']);
				}
			}
		}

		if ( '' != $qv['pagename'] ) {
			$this_WP_Query->queried_object = get_page_by_path($qv['pagename']);
			if ( !empty($this_WP_Query->queried_object) )
				$this_WP_Query->queried_object_id = (int) $this_WP_Query->queried_object->ID;
			else
				unset($this_WP_Query->queried_object);

			if( 'page' == get_option('show_on_front') && isset($this_WP_Query->queried_object_id) && $this_WP_Query->queried_object_id == get_option('page_for_posts') ) {
				$this_WP_Query->is_page = false;
				$this_WP_Query->is_home = true;
				$this_WP_Query->is_posts_page = true;
			}
		}

		if ( $qv['page_id'] ) {
			if( 'page' == get_option('show_on_front') && $qv['page_id'] == get_option('page_for_posts') ) {
				$this_WP_Query->is_page = false;
				$this_WP_Query->is_home = true;
				$this_WP_Query->is_posts_page = true;
			}
		}

		if ( !empty($qv['post_type']) ) {
			if ( is_array($qv['post_type']) )
				$qv['post_type'] = array_map('sanitize_key', $qv['post_type']);
			else
				$qv['post_type'] = sanitize_key($qv['post_type']);
		}

		if ( ! empty( $qv['post_status'] ) ) {
			if ( is_array( $qv['post_status'] ) )
				$qv['post_status'] = array_map('sanitize_key', $qv['post_status']);
			else
				$qv['post_status'] = preg_replace('|[^a-z0-9_,-]|', '', $qv['post_status']);
		}

		if ( $this_WP_Query->is_posts_page && ( ! isset($qv['withcomments']) || ! $qv['withcomments'] ) )
			$this_WP_Query->is_comment_feed = false;

		$this_WP_Query->is_singular = $this_WP_Query->is_single || $this_WP_Query->is_page || $this_WP_Query->is_attachment;
		// Done correcting is_* for page_on_front and page_for_posts

		/*
		if ( '404' == $qv['error'] )
			$this_WP_Query->set_404();

		$this_WP_Query->query_vars_hash = md5( serialize( $this_WP_Query->query_vars ) );
		$this_WP_Query->query_vars_changed = false;
		*/

}

//开发中功能, 发表文章时进行繁简转换
/*
add_filter('content_save_pre', $wpcc_langs['zh-tw'][0]);
add_filter('title_save_pre', $wpcc_langs['zh-tw'][0]);

add_action('admin_menu', 'wpcc_ep');
function wpcc_ep() {
	add_meta_box('chinese-conversion', 'Chinese Conversion', 'wpcc_edit_post', 'post');
	add_meta_box('chinese-conversion', 'Chinese Conversion', 'wpcc_edit_post', 'page');
}

function wpcc_edit_post() {
	echo '111';
}
*/

/**
 * 输出Header信息
 *
 * 在繁简转换页<header>部分输出一些JS和noindex的meta信息.
 * noindex的meta头是为了防止搜索引擎索引重复内容;
 *
 * JS信息是为了客户端一些应用和功能保留的.
 * 举例, 当访客在一个繁简转换页面提交搜索时, 本插件载入的JS脚本会在GET请求里附加一个variant变量,
 * 如 /?s=test&variant=zh-tw
 * 这样服务器端能够获取用户当前中文语言, 并显示对应语言的搜索结果页
 *
 */
function wpcc_header() {
	global $wpcc_target_lang, $wpcc_langs_urls, $wpcc_noconversion_url, $wpcc_direct_conversion_flag;
	echo "\n" . '<!-- WP Chinese Conversion Plugin Version ' . WPCC_VERSION . ' -->';
	echo "<script type=\"text/javascript\">
//<![CDATA[
var wpcc_target_lang=\"$wpcc_target_lang\";var wpcc_noconversion_url=\"$wpcc_noconversion_url\";var wpcc_langs_urls=new Array();";

	foreach($wpcc_langs_urls as $key => $value) {
		echo 'wpcc_langs_urls["' . $key . '"]="' . $value . '";';
	}
	echo '
//]]>
</script>';
	if( !$wpcc_direct_conversion_flag )
		wp_enqueue_script('wpcc-search-js', WPCC_ROOT_URL . 'search-variant.min.js', array(), '1.1', false);
		//echo '<script type="text/javascript" src="' . WPCC_ROOT_URL . 'search-variant.min.js' . '"></script>';

	if( $wpcc_direct_conversion_flag ||
		( ( class_exists('All_in_One_SEO_Pack') || class_exists('Platinum_SEO_Pack') ) &&
		!is_single() && !is_home() && !is_page() && !is_search() )
	)
		return;
	else
		echo '<meta name="robots" content="noindex,follow" />';
}

/*
 * 设置url. 包括当前页面原始URL和各个语言版本URL
 * @since 1.1.7
 *
 */
function wpcc_template_redirect() {
	global $wpcc_noconversion_url, $wpcc_langs_urls, $wpcc_options, $wpcc_target_lang, $wpcc_redirect_to;

	if( $wpcc_noconversion_url == get_option('home') . '/' && $wpcc_options['wpcc_use_permalink'] )
		foreach( $wpcc_options['wpcc_used_langs'] as $value ) {
			$wpcc_langs_urls[$value] = $wpcc_noconversion_url . $value . '/';
		}
	else
		foreach( $wpcc_options['wpcc_used_langs'] as $value ) {
			$wpcc_langs_urls[$value] = wpcc_link_conversion($wpcc_noconversion_url, $value);
		}

	if( !is_404() && $wpcc_redirect_to) {
		setcookie('wpcc_is_redirect_' . COOKIEHASH, '1', 0, COOKIEPATH, COOKIE_DOMAIN);
		wp_redirect($wpcc_langs_urls[$wpcc_redirect_to], 302);
	}

	if( !$wpcc_target_lang ) return;

	add_action('comment_form', 'wpcc_modify_comment_form');
	function wpcc_modify_comment_form() {
		global $wpcc_target_lang;
		echo '<input type="hidden" name="variant" value="' . $wpcc_target_lang . '" />';
	}
	wpcc_do_conversion();
}

/**
 * 在Wordpress的query vars里增加一个variant变量.
 *
 */
function wpcc_insert_query_vars($vars) {
	array_push($vars, 'variant');
	return $vars;
}

/**
 * Widget Class
 * @since 1.1.8
 *
 */
class Wpcc_Widget extends WP_Widget {
	function Wpcc_Widget() {
		$this->WP_Widget('widget_wpcc', 'Chinese Conversion', array('classname' => 'widget_wpcc', 'description' =>'Chinese Conversion Widget'));
	}

	function widget($args, $instance) {
		extract($args);
		$title = apply_filters('widget_title', $instance['title']);
		echo $before_widget;
		if( $title )
			echo $before_title . $title . $after_title;
		wpcc_output_navi( isset($instance['args']) ? $instance['args'] : '' );
		echo $after_widget;
	}

	function update($new_instance, $old_instance) {
		return $new_instance;
	}

	function form($instance) {
		$title = isset($instance['title']) ? esc_attr($instance['title']) : '';
		$args = isset($instance['args']) ? esc_attr($instance['args']) : '';
		?>
		<p>
			<label for="<?php echo $this->get_field_id('title'); ?>">Title: <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" /></label>
			<label for="<?php echo $this->get_field_id('args'); ?>">Args: <input class="widefat" id="<?php echo $this->get_field_id('args'); ?>" name="<?php echo $this->get_field_name('args'); ?>" type="text" value="<?php echo $args; ?>" /></label>
		</p>
		<?php
	}
}

/**
 * 转换字符串到当前请求的中文语言
 *
 * @param string $str string inputed
 * @param string $variant optional, Default to null, chinese language code you want string to be converted, if null( default), will use $GLOBALS['wpcc_target_lang']
 * @return converted string
 *
 * 这是本插件繁简转换页使用的基本中文转换函数. 例如, 如果访客请求一个"台灣正體"版本页面,
 * $wpcc_conversion_function被设置为'zhconversion_tw',
 * 本函数调用其把字符串转换为"台灣正體"版本
 *
 */
function zhconversion($str, $variant = null) {
	global $wpcc_options, $wpcc_langs;
	if( $variant === null ) $variant = $GLOBALS['wpcc_target_lang'];
	if( $variant == false) return $str;

	//if( !empty($wpcc_options['wpcc_no_conversion_tag']) || $wpcc_options['wpcc_no_conversion_ja'] == 1 )
	//	return limit_zhconversion($str, $wpcc_langs[$variant][0]);
	return $wpcc_langs[$variant][0]($str);
}


function zhconversion2($str, $variant = null) { // do not convert content within <!--WPCC_NC_START--> and <!--WPCC_NC_END-->.
	global $wpcc_options, $wpcc_langs;
	if( $variant === null ) $variant = $GLOBALS['wpcc_target_lang'];
	if( $variant == false) return $str;

	return limit_zhconversion($str, $wpcc_langs[$variant][0]);
}


$_wpcc_id = 1000;
/**
 * get a unique id number
 */
function wpcc_id() {
	global $_wpcc_id;
	return $_wpcc_id++;
}

/**
 * filter the content
 * @since 1.1.14
 *
 */
function wpcc_no_conversion_filter($str) {
	global $wpcc_options;

	$html = str_get_html($str);
	$query = '';

	if( !empty($wpcc_options['wpcc_no_conversion_ja']) )
		$query .= '*[lang="ja"]';

	if( !empty($wpcc_options['wpcc_no_conversion_tag']) ) {
		if( $query != '' )
			$query .= ',';
		if( preg_match('/^[a-z1-9|]+$/', $wpcc_options['wpcc_no_conversion_tag']) )
			$query .= str_replace('|', ',', $wpcc_options['wpcc_no_conversion_tag']); // backward compatability
		else
			$query .= $wpcc_options['wpcc_no_conversion_tag'];
	}

	$elements = $html->find($query);
	if( count($elements) == 0 )
		return $str;
	foreach($elements as $element) {
		$id = wpcc_id();
		$element->innertext = '<!--WPCC_NC' . $id . '_START-->' . $element->innertext . '<!--WPCC_NC' . $id . '_END-->';
	}

	return (string)$html;

}


/**
 * 安全转换字符串到当前请求的中文语言
 *
 * @param string $str string inputed
 * @param string $variant optional, Default to null, chinese language code you want string to be converted, if null( default), will use $GLOBALS['wpcc_target_lang']
 * @return converted string
 *
 * 与zhconversion函数不同的是本函数首先确保载入繁简转换表, 因为多了一次判断, 不可避免多耗费资源.
 *
 */
function zhconversion_safe($str, $variant = null) {
	wpcc_load_conversion_table();
	return zhconversion($str, $variant);
}

/**
 * 转换字符到多种中文语言,返回数组
 *
 * @param string $str string to be converted
 * @param array $langs Optional, Default to array('zh-tw', 'zh-cn'). array of chinese languages codes you want string to be converted to
 * @return array converted strings array
 *
 * Example: zhconversion('網絡');
 * Return: array('網路', '网络');
 *
 */
function zhconversion_all($str, $langs = array('zh-tw', 'zh-cn', 'zh-hk', 'zh-sg', 'zh-hans', 'zh-hant')) {
	global $wpcc_langs;
	$return = array();
	foreach($langs as $value) {
		$tmp = $wpcc_langs[$value][0] ($str);
		if($tmp != $str ) $return[]= $tmp;
	}
	return array_unique($return);
}

/**
 * 递归的对数组中元素用zhconversion函数转换, 返回处理后数组.
 *
 */
function zhconversion_deep($value) {
	$value = is_array($value) ? array_map('zhconversion_deep', $value) : zhconversion($value);
	return $value;
}

/**
 * 对输入字符串进行有限中文转换. 不转换<!--WPCC_NC_START-->和<!--WPCC_NC_END-->之間的中文
 *
 * @param string $str string inputed
 * @param string $function conversion function for current requested chinese language
 * @return converted string
 *
 */
function limit_zhconversion($str, $function) {
	if( $m = preg_split('/(<!--WPCC_NC(\d*)_START-->)(.*?)(<!--WPCC_NC\2_END-->)/s', $str, -1, PREG_SPLIT_DELIM_CAPTURE ) ) {
		$r = '';
		$count = 0;
		foreach($m as $v) {
			$count++;
			if($count % 5 == 1)
				$r .= $function ($v);
			else if($count % 5 == 4)
				$r .= $v;
		}
		return $r;
	}

	else return $function($str);
}


/**
 * 中文轉換函數. (zhconversion_hans轉換字符串為簡體中文, zhconversion_hant轉換字符串為繁體中文, zhconversion_tw轉換字符串為臺灣正體, 依次類推)
 *
 * @param string $str string to be converted
 * @return string converted chinese string
 *
 * 对于zh-hans和zh-hant以外中文语言(如zh-tw),Mediawiki里的做法是 先array_merge($zh2Hans, $zh2TW),再做一次strtr. 但这里考虑到内存需求和CPU资源,采用两次strtr方法.其中$zh2TW先做,因为其中项目可能覆盖zh2Hant里的项目
 *
 * 注意: 如果你想在其他地方(如Theme)里使用下面中文轉換函數, 請保證首先調用一次wpcc_load_conversion_table(); , 因為出于節省內存需求, 本插件僅在繁簡轉換頁面才會載入中文轉換表.
 *
 */
function zhconversion_hant($str) {
	global $zh2Hant;
	return strtr($str, $zh2Hant );
}

function zhconversion_hans($str) {
	global $zh2Hans;
	return strtr($str, $zh2Hans);
}

function zhconversion_cn($str) {
	global $zh2Hans, $zh2CN;
	return strtr(strtr($str, $zh2CN), $zh2Hans);
}

function zhconversion_tw($str) {
	global $zh2Hant, $zh2TW;
	return strtr(strtr($str, $zh2TW), $zh2Hant);
}

function zhconversion_sg($str) {
	global $zh2Hans, $zh2SG;
	return strtr(strtr($str, $zh2SG), $zh2Hans);
}

function zhconversion_hk($str) {
	global $zh2Hant, $zh2HK;
	return strtr(strtr($str, $zh2HK), $zh2Hant);
}

/**
 * 不推荐, 为向后兼容保留的函数
 * 为模板预留的函数, 把链接安全转换为当前中文语言版本的, 你可以在模板中调用其转换硬编码的链接.
 * 例如, 你可以在你的footer.php里显示博客About页链接: <a href="<?php echo wpcc_link_safe_conversion('http://domain.tld/about/'); ?>" >About</a>
 * 如果用户请求一个繁简转换页面, 则输出为该页的对应繁简转换版本链接,如 http://domain.tld/about/zh-tw/
 *
 * @deprecated Use wpcc_link_conversion($link)
 * @param string $link URL to be converted
 * @return string converted URL
 *
 */
function wpcc_link_safe_conversion($link) {
	return wpcc_link_conversion($link);
}

/**
 * 取消WP错误的重定向
 *
 * @param string $redirect_to 'redirect_canonical' filter's first argument
 * @param string $redirect_from 'redirect_canonical' filter's second argument
 * @return string|false
 *
 * 因为Wordpress canonical url机制, 有时会把繁简转换页重定向到错误URL
 * 本函数检测并取消这种重定向(通过返回false)
 *
 */
function wpcc_cancel_incorrect_redirect($redirect_to, $redirect_from) {
	global $wp_rewrite;
	if( preg_match('/^.*\/(zh-tw|zh-cn|zh-sg|zh-hant|zh-hans|zh-my|zh-mo|zh-hk|zh|zh-reset)\/?.+$/', $redirect_to) ) {
		if( ( $wp_rewrite->use_trailing_slashes && substr($redirect_from, -1) != '/' ) ||
			( !$wp_rewrite->use_trailing_slashes && substr($redirect_from, -1) == '/' )
			)
			return user_trailingslashit($redirect_from);
		return false;
	}
	return $redirect_to;
}

/**
 * 修改WP Rewrite规则数组, 增加本插件添加的Rewrite规则
 *
 * @param array $rules 'rewrite_rules_array' filter's argument , WP rewrite rules array
 * @return array processed rewrite rules array
 *
 *
 * 基本上, 本函数对WP的Rewrite规则数组这样处理:
 *
 * 对 '..../?$' => 'index.php?var1=$matches[1]..&varN=$matches[N]' 这样一条规则,
 * 如果规则体部分 '.../?$' 含有 'trackback', 'attachment', 'print', 不做处理
 * 否则, 增加一条 '.../zh-tw|zh-hant|...|zh-hans|zh|zh-reset/?$' => 'index.php?var1=$matches[1]..&varN=$matches[N]&variant=$matches[N+1]'的新规则
 * 1.1.6版本后, 因为增加了/zh-tw/original/permalink/这种URL形式, 情况更加复杂
 *
 */
function wpcc_rewrite_rules($rules) {
	global $wpcc_options;
	$reg = implode('|', $wpcc_options['wpcc_used_langs']);
	$rules2 = array();
	if($wpcc_options['wpcc_use_permalink'] == 1) {
		foreach($rules as $key => $value) {
			if( strpos($key, 'trackback') !== false || strpos($key, 'print') !== false || strpos($value, 'lang=') !== false ) continue;
			if( substr($key, -3) == '/?$' ) {
				if( !preg_match_all('/\$matches\[(\d+)\]/', $value, $matches, PREG_PATTERN_ORDER)) continue;
				$number = count($matches[0])+1;
				$rules2[substr($key, 0, -3) . '/(' . $reg . '|zh|zh-reset)/?$'] = $value . '&variant=$matches[' . $number . ']';
			}
		}
	}
	else { // $wpcc_options['wpcc_use_permalink'] == 2
		foreach($rules as $key => $value) {
			if( strpos($key, 'trackback') !== false || strpos($key, 'print') !== false || strpos($value, 'lang=') !== false ) continue;
			if( substr($key, -3) == '/?$' ) {
				$rules2['(' . $reg . '|zh|zh-reset)/' . $key] = preg_replace_callback('/\$matches\[(\d+)\]/', '_wpcc_permalink_preg_callback', $value) . '&variant=$matches[1]';
			}
		}
	}
	$rules2['^(' . $reg . '|zh|zh-reset)/?$'] = 'index.php?variant=$matches[1]';//首页的繁简转换版本rewrite规则
	$return = array_merge($rules2, $rules);
	return $return;
}

function _wpcc_permalink_preg_callback($matches) {
	return '$matches[' . ( intval($matches[1]) + 1 ) .']';
}

/**
 * 修改繁簡轉換頁面WP內部鏈接
 *
 * @param string $link URL to be converted
 * @return string converted URL
 *
 * 如果訪客請求一個繁簡轉換頁面, 本函數把該頁的所有鏈接轉換為對應中文語言版本的
 * 例如把分類頁鏈接轉換為 /category/cat-name/zh-xx/, 把Tag頁鏈接轉換為 /tag/tag-name/zh-xx/
 *
 */
function wpcc_link_conversion($link, $variant = null) {
	global $wpcc_options;

	static $wpcc_wp_homepath;
	if( empty($wpcc_wp_homepath) ) {
		$home = parse_url(home_url());
		$wpcc_wp_homepath = trailingslashit($home["path"]);
	}

	if( $variant === null ) $variant = $GLOBALS['wpcc_target_lang'];
	if( $variant == false ) return $link;

	if( strpos($link, '?') !== false || !$wpcc_options['wpcc_use_permalink'] )
		return add_query_arg('variant', $variant, $link);
	if($wpcc_options['wpcc_use_permalink'] == 1)
		return user_trailingslashit(trailingslashit($link) . $variant);
	return preg_replace('#^(http(s?)://[^/]+' . $wpcc_wp_homepath . ')#', '\\1' . $variant . '/', $link);
}

/**
 * don't convert a link in "direct_conversion" mode;
 * @since 1.1.14.2
 */
function wpcc_link_conversion_auto($link, $variant = null) {
	global $wpcc_target_lang, $wpcc_direct_conversion_flag, $wpcc_options;

	if( $link == home_url('') )
		$link .= '/';
	if ( !$wpcc_target_lang || $wpcc_direct_conversion_flag ) {
		return $link;
	} else {
		if( $link == home_url('/') && !empty($wpcc_options['wpcc_use_permalink']) )
			return trailingslashit(wpcc_link_conversion($link));
		return wpcc_link_conversion($link);
	}
}

/**
 * 獲取當前頁面原始URL
 * @return original permalink of current page
 *
 * 本函數返回當前請求頁面"原始版本" URL.
 * 即如果當前URL是 /YYYY/mm/sample-post/zh-tw/ 形式的臺灣正體版本,
 * 會返回 /YYYY/mm/sample-post/ 的原始(不進行中文轉換)版本鏈接.
 *
 */
function wpcc_get_noconversion_url() {
	global $wpcc_options;
	$reg = implode('|', $wpcc_options['wpcc_used_langs']);
	$tmp = ( is_ssl() ? 'https://' : 'http://' ) .
		$_SERVER['HTTP_HOST'] .
		$_SERVER['REQUEST_URI'];
	$tmp = trim(strtolower(remove_query_arg('variant', $tmp)));

	if( preg_match('/^(.*)\/(' . $reg . '|zh|zh-reset)(\/.*)?$/', $tmp, $matches) ) {
		$tmp = user_trailingslashit(trailingslashit($matches[1]) . ltrim($matches[3], '/')); //为什么这样写代码? 是有原因的- -(众人: 废话!)
		if( $tmp == get_option('home') ) {
			$tmp .= '/';
		}
	}
	return $tmp;
}

/**
 * 修復繁簡轉換頁分頁鏈接
 *
 * @param string $link URL to be fixed
 * @return string Fixed URL
 *
 * 本函數修復繁簡轉換頁面 /.../page/N 形式的分頁鏈接為正確形式. 具體說明略.
 *
 * 你可以在本函數內第一行加上 'return $link;' 然后訪問你博客首頁或存檔頁的繁體或簡體版本,
 * 會發現"上一頁"(previous posts page)和"下一頁"(next posts page)的鏈接URL是錯誤的.
 * 本函数算法极为愚蠢- -, 但是没有其它办法, 因为wordpress对于分页链接的生成策略非常死板且无法更多地通过filter控制
 *
 */
function wpcc_pagenum_link_fix($link) {
	global $wpcc_target_lang, $wpcc_options;
	global $paged;
	if( $wpcc_options['wpcc_use_permalink'] != 1 ) return $link;

	if( preg_match('/^(.*)\/page\/\d+\/' . $wpcc_target_lang . '\/page\/(\d+)\/?$/', $link, $tmp) ||
		preg_match('/^(.*)\/' . $wpcc_target_lang . '\/page\/(\d+)\/?$/', $link, $tmp) ) {
		return user_trailingslashit ( $tmp[1] . '/page/' . $tmp[2] . '/' . $wpcc_target_lang);
	} else if( preg_match('/^(.*)\/page\/(\d+)\/' . $wpcc_target_lang . '\/?$/', $link, $tmp) && $tmp[2] == 2 && $paged == 2 ) {
		if($tmp[1] == get_option('home'))
			return $tmp[1] . '/' . $wpcc_target_lang . '/';
		return user_trailingslashit($tmp[1] . '/' . $wpcc_target_lang);
	}
	return $link;
}

/**
 * 修复繁简转换后页面部分内部链接.
 *
 * @param string $link URL to be fixed
 * @return string Fixed URL
 *
 * 本插件会添加 post_link钩子, 从而修改繁简转换页单篇文章页永久链接, 但WP的很多内部链接生成依赖这个permalink.
 * (为什么加载在post_link钩子上而不是the_permalink钩子上? 有很多原因,这里不说了.)
 *
 * 举例而言, 本插件把 繁简转换页的文章permalink修改为 /YYYY/mm/sample-post/zh-tw/ (如果您原来的Permalink是/YYYY/mm/sample-post/)
 * 那么WP生成的该篇文章评论Feed链接是 /YYYY/mm/sample-post/zh-tw/feed/, 出错
 * 本函数把这个链接修复为 /YYYY/mm/sample-post/feed/zh-tw/ 的正确形式.
 *
 */
function wpcc_fix_link_conversion($link) {
	global $wpcc_options;
	if( $wpcc_options['wpcc_use_permalink'] == 1 ) {
		if( $flag = strstr($link, '#') )
			$link = substr($link, 0, -strlen($flag));
		if(preg_match('/^(.*\/)(zh-tw|zh-cn|zh-sg|zh-hant|zh-hans|zh-my|zh-mo|zh-hk|zh|zh-reset)\/(.+)$/', $link, $tmp))
			return user_trailingslashit ($tmp[1] . trailingslashit( $tmp[3] ) . $tmp[2]) . $flag;
		return $link . $flag;
	}
	else if( $wpcc_options['wpcc_use_permalink'] == 0 ) {
		if( preg_match('/^(.*)\?variant=([-a-zA-Z]+)\/(.*)$/', $link, $tmp) )
			return add_query_arg('variant', $tmp[2], trailingslashit($tmp[1]) . $tmp[3]);
		return $link;
	}
	else
		return $link;
}

/**
 * "取消"繁简转换后页面部分内部链接轉換.
 * @param string $link URL to be fixed
 * @return string Fixed URL
 *
 * 本函數作用與上面的wpcc_fix_link_conversion類似, 不同的是本函數"取消"而不是"修復"繁簡轉換頁內部鏈接
 * 舉例而言, 對繁簡轉換頁面而言, WP生成的單篇文章trackback地址 是 /YYYY/mm/sample-post/zh-tw/trackback/
 * 本函數把它修改為 /YYYY/mm/sample-post/trackback/的正確形式(即去除URL中 zh-xx字段)
 *
 */
function wpcc_cancel_link_conversion($link) {
	global $wpcc_options;
	if( $wpcc_options['wpcc_use_permalink'] ) {
		if(preg_match('/^(.*\/)(zh-tw|zh-cn|zh-sg|zh-hant|zh-hans|zh-my|zh-mo|zh-hk|zh|zh-reset)\/(.+)$/', $link, $tmp))
			return $tmp[1] . $tmp[3];
		return $link;
	}
	else {
		if( preg_match('/^(.*)\?variant=[-a-zA-Z]+\/(.*)$/', $link, $tmp) )
			return trailingslashit($tmp[1]) . $tmp[2];
		return $link;
	}
}

/**
 * ...
 */
function wpcc_rel_canonical() {
	if ( !is_singular() )
		return;
	global $wp_the_query;
	if ( !$id = $wp_the_query->get_queried_object_id() )
		return;
	$link = wpcc_cancel_link_conversion(get_permalink( $id ));
	echo "<link rel='canonical' href='$link' />\n";
}


/**
 * 返回w3c標準的當前中文語言代碼,如 zh-CN, zh-Hans
 * 返回值可以用在html元素的 lang=""標籤裡
 *
 * @since 1.1.9
 * @link http://www.w3.org/International/articles/language-tags/ W3C關於language attribute文章.
 */
function variant_attribute($default = "zh", $variant = false) {
	global $wpcc_langs;
	if( !$variant )
		$variant = $GLOBALS['wpcc_target_lang'];
	if( !$variant )
		return $default;
	return $wpcc_langs[$variant][3];
}
/**
 * 返回當前語言代碼
 * @since 1.1.9
 */
function variant($default = false) {
	global $wpcc_target_lang;
	if( !$wpcc_target_lang )
		return $default;
	return $wpcc_target_lang;
}

/**
 * 输出当前页面不同中文语言版本链接
 * @param bool $return Optional, Default to false, return or echo result.
 *
 * 本插件Widget会调用这个函数.
 *
 */
function wpcc_output_navi($args = '') {
	global $wpcc_target_lang, $wpcc_noconversion_url, $wpcc_langs_urls, $wpcc_langs, $wpcc_options;

	extract(wp_parse_args($args, array('mode'=>'normal', 'echo'=>1)));
	if($mode =='wrap') {
		wpcc_output_navi2();
		return;
	}

	if( !empty($wpcc_options['nctip']) ) {
		$noconverttip = $wpcc_options['nctip'];
	} else {
		$locale = str_replace('_', '-', strtolower(get_locale()));
		if( in_array($locale, array('zh-hant', 'zh-tw', 'zh-hk', 'zh-mo')) ) //zh-mo = 澳門繁體, 目前與zh-hk香港繁體轉換表相同
			$noconverttip = '不轉換';
		else
			$noconverttip = '不转换';
	}
	if( $wpcc_target_lang )
		$noconverttip = zhconversion($noconverttip);
	if( ( $wpcc_options['wpcc_browser_redirect'] ==2 || $wpcc_options['wpcc_use_cookie_variant'] == 2 ) &&
		$wpcc_target_lang
	) {
		$default_url = wpcc_link_conversion($wpcc_noconversion_url, 'zh');
		if( $wpcc_options['wpcc_use_permalink'] !=0 && is_home() && ! is_paged() )
			$default_url = trailingslashit($default_url);
	}
	else $default_url = $wpcc_noconversion_url;


	$output = "\n" . '<div id="wpcc_widget_inner"><!--WPCC_NC_START-->' . "\n";
	$output .= '	<span id="wpcc_original_link" class="' . ( $wpcc_target_lang == false ? 'wpcc_current_lang' : 'wpcc_lang' ) . '" ><a class="wpcc_link" href="' . esc_url($default_url) . '" title="' . esc_html($noconverttip) . '">' . esc_html($noconverttip) . '</a></span>' . "\n";

	foreach($wpcc_langs_urls as $key => $value) {
		$tip = !empty($wpcc_options[$wpcc_langs[$key][1]]) ? esc_html($wpcc_options[$wpcc_langs[$key][1]]) : $wpcc_langs[$key][2];
		$output .= '	<span id="wpcc_'. $key . '_link" class="'. ( $wpcc_target_lang == $key ? 'wpcc_current_lang' : 'wpcc_lang' ) . '" ><a class="wpcc_link" rel="nofollow" href="'. esc_url($value) .'" title="' . esc_html($tip) . '" >' . esc_html($tip) . '</a></span>' . "\n";
	}
	$output .= '<!--WPCC_NC_END--></div>' . "\n";
	if(!$echo) return $output;
	echo $output;
}

/**
 * Another function for outputing navi. You should not want to use it.
 *
 */
function wpcc_output_navi2() {
	global $wpcc_target_lang, $wpcc_noconversion_url, $wpcc_langs_urls, $wpcc_options;

	if( ($wpcc_options['wpcc_browser_redirect'] == 2 || $wpcc_options['wpcc_use_cookie_variant'] == 2) &&
		$wpcc_target_lang
	) {
		$default_url = wpcc_link_conversion($wpcc_noconversion_url, 'zh');
		if( $wpcc_options['wpcc_use_permalink'] !=0 && is_home() && ! is_paged() )
			$default_url = trailingslashit($default_url);
	}
	else $default_url = $wpcc_noconversion_url;

	$output = "\n" . '<div id="wpcc_widget_inner"><!--WPCC_NC_START-->' . "\n";
	$output .= '	<span id="wpcc_original_link" class="' . ( $wpcc_target_lang == false ? 'wpcc_current_lang' : 'wpcc_lang' ) .'" ><a class="wpcc_link" href="' . esc_url($default_url) . '" title="不轉換">不轉換</a></span>' ."\n";
	$output .= '	<span id="wpcc_cn_link" class="'. ( $wpcc_target_lang == 'zh-cn' ? 'wpcc_current_lang' : 'wpcc_lang' ) . '" ><a class="wpcc_link" rel="nofollow" href="'. esc_url($wpcc_langs_urls['zh-cn']) . '" title="大陆简体" >大陆简体</a></span>' ."\n";
	$output .= '	<span id="wpcc_tw_link" class="'. ( $wpcc_target_lang == 'zh-tw' ? 'wpcc_current_lang' : 'wpcc_lang' ) .'"><a class="wpcc_link" rel="nofollow" href="'. esc_url($wpcc_langs_urls['zh-tw']) .'" title="台灣正體" >台灣正體</a></span>' ."\n";
	$output .= '	<span id="wpcc_more_links" class="wpcc_lang" >
	<span id="wpcc_more_links_inner_more" class="'. ( ( $wpcc_target_lang == false || $wpcc_target_lang == 'zh-cn' || $wpcc_target_lang == 'zh-tw' ) ? 'wpcc_lang' : 'wpcc_current_lang' ) . '"><a class="wpcc_link" href="#" onclick="return false;" >其它中文</a></span>
		<span id="wpcc_more_links_inner" >
			<span id="wpcc_hans_link" class="' . ( $wpcc_target_lang == 'zh-hans' ? 'wpcc_current_lang' : 'wpcc_lang' ) . '" ><a class="wpcc_link" rel="nofollow" href="' . esc_url($wpcc_langs_urls['zh-hans']) . '" title="简体中文" >简体中文' . '</a></span>
			<span id="wpcc_hant_link" class="' . ( $wpcc_target_lang == 'zh-hant' ? 'wpcc_current_lang' : 'wpcc_lang' ) . '" ><a class="wpcc_link" rel="nofollow" href="' . esc_url($wpcc_langs_urls['zh-hant']) . '" title="繁體中文" >繁體中文' . '</a></span>
			<span id="wpcc_hk_link" class="' . ( $wpcc_target_lang == 'zh-hk' ? 'wpcc_current_lang' : 'wpcc_lang' ) . '"><a class="wpcc_link" rel="nofollow" href="' . esc_url($wpcc_langs_urls['zh-hk']) . '" title="港澳繁體" >港澳繁體</a></span>
			<span id="wpcc_sg_link" class="' . ( $wpcc_target_lang == 'zh-sg' ? 'wpcc_current_lang' : 'wpcc_lang' ) . '" ><a class="wpcc_link" rel="nofollow" href="' . esc_url($wpcc_langs_urls['zh-sg']) . '" title="马新简体" >马新简体</a></span>
		</span>
	</span>';

	$output .= '<!--WPCC_NC_END--></div>' . "\n";
	echo $output;
}

/**
 * 从给定的语言列表中, 解析出浏览器客户端首选语言, 返回解析出的语言字符串或false
 *
 * @param string $accept_languages the languages sting, should set to $_SERVER['HTTP_ACCEPT_LANGUAGE']
 * @param array $target_langs given languages array
 * @param int|bool $flag Optional, default to 0 ( mean false ), description missing.
 * @return string|bool the parsed lang or false if it doesn't exists
 *
 * 使用举例: 调用形式 wpcc_get_prefered_language($_SERVER['HTTP_ACCEPT_LANGUAGE'], $target_langs)
 *
 * $_SERVER['HTTP_ACCEPT_LANGUAGE']: ja,zh-hk;q=0.8,fr;q=0.5,en;q=0.3
 * $target_langs: array('zh-hk', 'en')
 * 返回值: zh-hk
 *
 * $_SERVER['HTTP_ACCEPT_LANGUAGE']: fr;q=0.5,en;q=0.3
 * $target_langs: array('zh-hk', 'en')
 * 返回值: en
 *
 * $_SERVER['HTTP_ACCEPT_LANGUAGE']: ja,zh-hk;q=0.8,fr;q=0.5,en;q=0.3
 * $target_langs: array('zh-tw', 'zh-cn')
 * 返回值: false
 *
 */
function wpcc_get_prefered_language($accept_languages, $target_langs, $flag = 0) {
	$langs = array();
	preg_match_all('/([a-z]{1,8}(-[a-z]{1,8})?)\s*(;\s*q\s*=\s*(1|0\.[0-9]+))?/i', $accept_languages, $lang_parse);

	if(count($lang_parse[1])) {
		$langs = array_combine($lang_parse[1], $lang_parse[4]);//array_combine需要php5以上版本
		foreach($langs as $lang => $val) {
			if($val === '') $langs[$lang] = '1';
		}
	arsort($langs, SORT_NUMERIC);
	$langs = array_keys($langs);
	$langs = array_map('strtolower', $langs);

		foreach($langs as $val) {
			if(in_array($val, $target_langs))
				return $val;
		}

		if( $flag ) {
			$array = array('zh-hans', 'zh-cn', 'zh-sg', 'zh-my');
			$a = array_intersect($array, $target_langs);
			if(!empty($a)) {
				$b = array_intersect($array, $langs);
				if(!empty($b)) {
					$a = each($a);
					return $a[1];
				}
			}

			$array = array('zh-hant', 'zh-tw', 'zh-hk', 'zh-mo');
			$a = array_intersect($array, $target_langs);
			if(!empty($a)) {
				$b = array_intersect($array, $langs);
				if(!empty($b)) {
					$a = each($a);
					return $a[1];
				}
			}
		}
		return false;
	}
	return false;
}

/**
 * 判断当前请求是否为搜索引擎访问.
 * 使用的算法极为保守, 只要不是几个主要的浏览器,就判定为Robots
 *
 * @uses $_SERVER['HTTP_USER_AGENT']
 * @return bool
 */
function wpcc_is_robot() {
	if(empty($_SERVER['HTTP_USER_AGENT'])) return true;
	$ua = strtoupper($_SERVER['HTTP_USER_AGENT']);

	$robots = array(
		'bot',
		'spider',
		'crawler',
		'dig',
		'search',
		'find'
	);

	while(list($key, $val) = each($robots))
		if(strstr($ua, strtoupper($val)))
			return true;

	$browsers = array(
		"compatible; MSIE",
		"UP.Browser",
		"Mozilla",
		"Opera",
		"NSPlayer",
		"Avant Browser",
		"Chrome",
		"Gecko",
		"Safari",
		"Lynx",
	);

	while(list($key, $val) = each($browsers))
		if(strstr($ua, strtoupper($val)))
			return false;

	return true;
}

/**
 * fix a relative bug
 * @since 1.1.14
 *
 */
function wpcc_apply_filter_search_rule() {
	add_filter('posts_where', 'wpcc_filter_search_rule', 100);
	add_filter('posts_distinct', create_function('$s', 'return \'DISTINCT\';'));
}

/**
 * 对Wordpress搜索时生成sql语句的 where 条件部分进行处理, 使其同时在数据库中搜索关键词的中文简繁体形式.
 *
 * @param string $where 'post_where' filter's argument, 'WHERE...' part of the wordpesss query sql sentence
 * @return string WHERE sentence have been processed
 *
 * 使用方法: add_filter('posts_where', 'wpcc_filter_search_rule');
 * 原理说明: 假设访客通过表单搜索 "简体 繁體 中文", Wordpress生成的sql语句条件$where中一部分是这样的:
 *
 * ((wp_posts.post_title LIKE '%简体%') OR (wp_posts.post_content LIKE '%简体%')) AND ((wp_posts.post_title LIKE '%繁體%') OR (wp_posts.post_content LIKE '%繁體%')) AND ((wp_posts.post_title LIKE '%中文%') OR (wp_posts.post_content LIKE '%中文%')) OR (wp_posts.post_title LIKE '%简体 繁體 中文%') OR (wp_posts.post_content LIKE '%简体 繁體 中文%')
 *
 * 本函数把$where中的上面这部分替换为:
 *
 * ( ( wp_posts.post_title LIKE '%簡體%') OR ( wp_posts.post_content LIKE '%簡體%') OR ( wp_posts.post_title LIKE '%简体%') OR ( wp_posts.post_content LIKE '%简体%') ) AND ( ( wp_posts.post_title LIKE '%繁体%') OR ( wp_posts.post_content LIKE '%繁体%') OR ( wp_posts.post_title LIKE '%繁體%') OR ( wp_posts.post_content LIKE '%繁體%') ) AND ( ( wp_posts.post_title LIKE '%中文%') OR ( wp_posts.post_content LIKE '%中文%') ) OR ( wp_posts.post_title LIKE '%簡體 繁體 中文%') OR ( wp_posts.post_content LIKE '%簡體 繁體 中文%') OR ( wp_posts.post_title LIKE '%简体 繁体 中文%') OR ( wp_posts.post_content LIKE '%简体 繁体 中文%') OR ( wp_posts.post_title LIKE '%简体 繁體 中文%') OR ( wp_posts.post_content LIKE '%简体 繁體 中文%')
 *
 */
function wpcc_filter_search_rule($where) {
	global $wp_query, $wpdb;
	if(empty($wp_query->query_vars['s'])) return $where;
	if( !preg_match("/^([".chr(228)."-".chr(233)."]{1}[".chr(128)."-".chr(191)."]{1}[".chr(128)."-".chr(191)."]{1}){1}/", $wp_query->query_vars['s']) && !preg_match("/([".chr(228)."-".chr(233)."]{1}[".chr(128)."-".chr(191)."]{1}[".chr(128)."-".chr(191)."]{1}){1}$/", $wp_query->query_vars['s']) && !preg_match("/([".chr(228)."-".chr(233)."]{1}[".chr(128)."-".chr(191)."]{1}[".chr(128)."-".chr(191)."]{1}){2,}/", $wp_query->query_vars['s']) )
		return $where;//如果搜索关键字中不含中文字符, 直接返回

	wpcc_load_conversion_table();

	$sql = '';
	$and1 = '';
	$original = '';//Wordpress原始搜索sql代码中 post_title和post_content like '%keyword%'的部分,本函数最后需要找出原始sql代码中这部分并予以替换, 所以必须在过程中重新生成一遍,
	foreach( $wp_query->query_vars['search_terms'] as $value ) {
		$value = addslashes_gpc($value);
		$original .= "{$and1}(($wpdb->posts.post_title LIKE '%{$value}%') OR ($wpdb->posts.post_excerpt LIKE '%{$value}%') OR ($wpdb->posts.post_content LIKE '%{$value}%'))";
		$valuea = zhconversion_all($value);
		$valuea[] = $value;
    $sql .= "{$and1}( ";
    $or2 = '';
		foreach($valuea as $v) {
      $sql .= "{$or2}( ". $wpdb->prefix ."posts.post_title LIKE '%" . $v . "%') ";
      $sql .= " OR ( ". $wpdb->prefix ."posts.post_content LIKE '%" . $v . "%') ";
      $sql .= " OR ( ". $wpdb->prefix ."posts.post_excerpt LIKE '%" . $v . "%') ";
      $or2 = ' OR ';
		}
		$sql .= ' ) ';
		$and1 = ' AND ';
	}

	// debug
	//echo("Where: ". $where . "<br /><br />Search: " . $original . "<br /><br />Replace with: $sql");die();

	if(empty($sql))
		return $where;
	$where = preg_replace('/' . preg_quote($original, '/') . '/', $sql, $where, 1);
	return $where;
}

/**
 * ob_start Callback function
 *
 */
function wpcc_ob_callback($buffer) {
	global $wpcc_target_lang, $wpcc_direct_conversion_flag;
	if( $wpcc_target_lang && !$wpcc_direct_conversion_flag ) {
		$wpcc_home_url = wpcc_link_conversion_auto(home_url('/'));
		$buffer = preg_replace('|(<a\s(?!class="wpcc_link")[^<>]*?href=([\'"]))' . preg_quote(esc_url(home_url('')), '|') . '/?(\2[^<>]*?>)|', '\\1' . esc_url($wpcc_home_url) . '\\3', $buffer);
	}
	return zhconversion2($buffer) . "\n" . '<!-- WP Chinese Conversion Full Page Converted. Target Lang: ' . $wpcc_target_lang . ' -->';
}

/**
 * Debug Function
 *
 * 要开启本插件Debug, 去掉第一行 defined('WPCC_DEBUG', true')...注释.
 * Debug信息将输出在页面footer位置( wp_footer action)
 *
 */
function wpcc_debug() {
	global $wpcc_noconversion_url, $wpcc_target_lang, $wpcc_langs_urls, $wpcc_deubg_data, $wpcc_langs, $wpcc_options, $wp_rewrite;
	echo '<!--';
	echo '<p style="font-size:20px;color:red;">';
	echo 'WP Chinese Conversion Plugin Debug Output:<br />';
	echo '默认URL: <a href="'. $wpcc_noconversion_url . '">' . $wpcc_noconversion_url . '</a><br />';
	echo '当前语言(空则是不转换): ' . $wpcc_target_lang . "<br />";
	echo 'Query String: ' . $_SERVER['QUERY_STRING'] . '<br />';
	echo 'Request URI: ' . $_SERVER['REQUEST_URI'] . '<br />';
	foreach($wpcc_langs_urls as $key => $value) {
		echo $key . ' URL: <a href="' . $value . '">' . $value . '</a><br />';
	}
	echo 'Category feed link: ' . get_category_feed_link(1) . '<br />';
	echo 'Search feed link: ' . get_search_feed_link('test');
	echo 'Rewrite Rules: <br />';
	echo nl2br(htmlspecialchars(var_export($wp_rewrite->rewrite_rules(), true))) . '<br />';
	echo 'Debug Data: <br />';
	echo nl2br(htmlspecialchars(var_export($wpcc_deubg_data, true)));
	echo '</p>';
	echo '-->';
}

/**
 * Admin管理后台初始化
 *
 */
function wpcc_admin_init() {
	global $wpcc_admin;
	require_once(dirname(__FILE__) . '/wp-chinese-conversion-admin.php');
	$wpcc_admin = new Wpcc_Admin();
}

/**
 * Parse current request
 * @param object $query 'parse_request' filter' argument, the 'WP' object
 *
 * @todo 彻底重写本函数（目前是一团浆糊）。使用Wordpress原生的query var系統讀/寫variant參數, 1.2版本實現.
 * Core codes of this plugin
 * 本函数获取当前请求中文语言并保存到 $wpcc_target_lang全局变量里.
 * 并且还做其它一些事情.
 *
 */
function wpcc_parse_query($query) {
	if( is_robots() )
		return;
	global $wpcc_target_lang, $wpcc_redirect_to, $wpcc_noconversion_url, $wpcc_options, $wpcc_direct_conversion_flag;

	if( !is_404() )
		$wpcc_noconversion_url = wpcc_get_noconversion_url();
	else {
		$wpcc_noconversion_url = get_option('home') . '/';
		$wpcc_target_lang = false;
		return;
	}

	$request_lang = isset($query->query_vars['variant']) ? $query->query_vars['variant'] : '';
	$cookie_lang = isset($_COOKIE['wpcc_variant_' . COOKIEHASH]) ? $_COOKIE['wpcc_variant_' . COOKIEHASH] : '';

	if( $request_lang && in_array($request_lang, $wpcc_options['wpcc_used_langs'] ) )
		$wpcc_target_lang = $request_lang;
	else
		$wpcc_target_lang = false;

	if(!$wpcc_target_lang) {
		if( $request_lang == 'zh' ) {
			if( $wpcc_options['wpcc_use_cookie_variant'] != 0 ) {
				setcookie('wpcc_variant_' . COOKIEHASH, 'zh', time() + 30000000, COOKIEPATH, COOKIE_DOMAIN);
			} else {
				setcookie('wpcc_is_redirect_' . COOKIEHASH, '1', 0, COOKIEPATH, COOKIE_DOMAIN);
			}
			header('Location: ' . $wpcc_noconversion_url); die();
		}
		if( $request_lang == 'zh-reset' ) {
			setcookie('wpcc_variant_' . COOKIEHASH, '', time() - 30000000, COOKIEPATH, COOKIE_DOMAIN);
			setcookie('wpcc_is_redirect_' . COOKIEHASH, '', time() - 30000000, COOKIEPATH, COOKIE_DOMAIN);
			header('Location: ' . $wpcc_noconversion_url); die();
		}

		if( $cookie_lang == 'zh') {
			if( $wpcc_options['wpcc_use_cookie_variant'] != 0 ) {
				if( $wpcc_options['wpcc_search_conversion'] == 2 ) {
					wpcc_apply_filter_search_rule();
				}
				return;
			} else {
				setcookie('wpcc_variant_' . COOKIEHASH, '', time() - 30000000, COOKIEPATH, COOKIE_DOMAIN);
			}
		}

		if( !$request_lang && !empty($_COOKIE['wpcc_is_redirect_' . COOKIEHASH]) ) {
			if( $wpcc_options['wpcc_use_cookie_variant'] != 0 ) {
				setcookie('wpcc_variant_' . COOKIEHASH, 'zh', time() + 30000000, COOKIEPATH, COOKIE_DOMAIN);
				setcookie('wpcc_is_redirect_' . COOKIEHASH, '', time() - 30000000, COOKIEPATH, COOKIE_DOMAIN);
			} else if( $cookie_lang ) {
				setcookie('wpcc_variant_' . COOKIEHASH, '', time() - 30000000, COOKIEPATH, COOKIE_DOMAIN);
			}
			if( $wpcc_options['wpcc_search_conversion'] == 2 ) {
				wpcc_apply_filter_search_rule();
			}
			return;
		}
		$is_robot = wpcc_is_robot();
		if($wpcc_options['wpcc_use_cookie_variant'] != 0 && !$is_robot && $cookie_lang) {
			if(in_array($cookie_lang, $wpcc_options['wpcc_used_langs'])) {
				if( $wpcc_options['wpcc_use_cookie_variant'] == 2 ) {
					$wpcc_target_lang = $cookie_lang;
					$wpcc_direct_conversion_flag = true;
				} else {
					$wpcc_redirect_to = $cookie_lang;
				}
			}
			else
				setcookie('wpcc_variant_' . COOKIEHASH, '', time() - 30000000, COOKIEPATH, COOKIE_DOMAIN);
		} else {
			if( $cookie_lang )
				setcookie('wpcc_variant_' . COOKIEHASH, '', time() - 30000000, COOKIEPATH, COOKIE_DOMAIN);
			if(
				$wpcc_options['wpcc_browser_redirect'] != 0 &&
				!$is_robot &&
				!empty($_SERVER['HTTP_ACCEPT_LANGUAGE']) &&
				$wpcc_browser_lang = wpcc_get_prefered_language($_SERVER['HTTP_ACCEPT_LANGUAGE'], $wpcc_options['wpcc_used_langs'], $wpcc_options['wpcc_auto_language_recong'])
			) {
				if($wpcc_options['wpcc_browser_redirect'] == 2) {
					$wpcc_target_lang = $wpcc_browser_lang;
					$wpcc_direct_conversion_flag = true;
				}
				else{
					$wpcc_redirect_to = $wpcc_browser_lang;
				}
			}
		}
	}

	if( $wpcc_options['wpcc_search_conversion'] == 2 ||
		( $wpcc_target_lang && $wpcc_options['wpcc_search_conversion'] == 1 )
	) {
		wpcc_apply_filter_search_rule();
	}

	if( $wpcc_target_lang && $wpcc_options['wpcc_use_cookie_variant'] != 0 && $cookie_lang != $wpcc_target_lang ) {
		setcookie('wpcc_variant_'. COOKIEHASH, $wpcc_target_lang, time() + 30000000, COOKIEPATH, COOKIE_DOMAIN);
	}

}

/**
 * 载入繁简转换表.
 *
 * 出于节省内存考虑, 本插件并不总是载入繁简转换表. 而仅在繁简转换页面才这样做.
 */
function wpcc_load_conversion_table() {
	global $wpcc_options;
	if( !empty($wpcc_options['wpcc_no_conversion_ja']) || !empty($wpcc_options['wpcc_no_conversion_tag']) ) {
		if( !function_exists('str_get_html') )
			require_once(__DIR__ . '/simple_html_dom.php');
	}

	global $zh2Hans;
	if($zh2Hans == false) {
		global $zh2Hant, $zh2TW, $zh2CN, $zh2SG, $zh2HK;
		require_once( dirname(__FILE__) . '/ZhConversion.php' );
		if( file_exists(WP_CONTENT_DIR . '/extra_zhconversion.php') )
			require_once( WP_CONTENT_DIR . '/extra_zhconversion.php' );
	}
}

/**
 * 进行繁简转换. 加载若干filter转换页面内容和内部链接
 *
 */
function wpcc_do_conversion() {
	global $wpcc_direct_conversion_flag, $wpcc_options;
	wpcc_load_conversion_table();

	add_action('wp_head', 'wpcc_header');

	if( !$wpcc_direct_conversion_flag ) {
		remove_action('wp_head', 'rel_canonical');
		add_action('wp_head', 'wpcc_rel_canonical');

		//add_filter('the_permalink', 'wpcc_link_conversion');
		add_filter('post_link', 'wpcc_link_conversion');
		add_filter('month_link', 'wpcc_link_conversion');
		add_filter('day_link', 'wpcc_link_conversion');
		add_filter('year_link', 'wpcc_link_conversion');
		add_filter('page_link', 'wpcc_link_conversion');
		add_filter('tag_link', 'wpcc_link_conversion');
		add_filter('author_link', 'wpcc_link_conversion');
		add_filter('category_link', 'wpcc_link_conversion');
		add_filter('feed_link', 'wpcc_link_conversion');
		add_filter('attachment_link', 'wpcc_link_conversion');
		add_filter('search_feed_link', 'wpcc_link_conversion');

		add_filter('category_feed_link', 'wpcc_fix_link_conversion');
		add_filter('tag_feed_link', 'wpcc_fix_link_conversion');
		add_filter('author_feed_link', 'wpcc_fix_link_conversion');
		add_filter('post_comments_feed_link', 'wpcc_fix_link_conversion');
		add_filter('get_comments_pagenum_link', 'wpcc_fix_link_conversion');
		add_filter('get_comment_link', 'wpcc_fix_link_conversion');

		add_filter('attachment_link', 'wpcc_cancel_link_conversion');
		add_filter('trackback_url', 'wpcc_cancel_link_conversion');

		add_filter('get_pagenum_link', 'wpcc_pagenum_link_fix');
		add_filter('redirect_canonical', 'wpcc_cancel_incorrect_redirect', 10, 2);
	}

	if( !empty($wpcc_options['wpcc_no_conversion_ja']) || !empty($wpcc_options['wpcc_no_conversion_tag']) ) {
		add_filter('the_content', 'wpcc_no_conversion_filter', 15);
		add_filter('the_content_rss', 'wpcc_no_conversion_filter', 15);
	}

	if($wpcc_options['wpcc_use_fullpage_conversion'] == 1) {
		@ob_start('wpcc_ob_callback');
		/*
		function wpcc_ob_end() {
			while( @ob_end_flush() );
		}
		add_action('shutdown', 'wpcc_ob_end');
		*/
		//一般不需要这段代码, Wordpress默认在shutdown时循环调用ob_end_flush关闭所有缓存.

		return;
	}

	add_filter('the_content', 'zhconversion2', 20);
	add_filter('the_content_rss', 'zhconversion2', 20);
	add_filter('the_excerpt', 'zhconversion2', 20);
	add_filter('the_excerpt_rss', 'zhconversion2', 20);

	add_filter('the_title', 'zhconversion' );
	add_filter('comment_text', 'zhconversion');
	add_filter('bloginfo', 'zhconversion');
	add_filter('the_tags', 'zhconversion_deep');
	add_filter('term_links-post_tag', 'zhconversion_deep');
	add_filter('wp_tag_cloud', 'zhconversion');
	add_filter('the_category', 'zhconversion');
	add_filter('list_cats', 'zhconversion');
	add_filter('category_description', 'zhconversion');
	add_filter('single_cat_title', 'zhconversion');
	add_filter('single_post_title', 'zhconversion');
	add_filter('bloginfo_rss', 'zhconversion');
	add_filter('the_title_rss', 'zhconversion');
	add_filter('comment_text_rss', 'zhconversion');
}

/**
 * 在html的body标签class属性里添加当前中文语言代码
 * thanks to chad luo.
 * @since 1.1.13
 *
 */
function wpcc_body_class($classes) {
	global $wpcc_target_lang;
	$classes[] = $wpcc_target_lang ? $wpcc_target_lang : "zh";
	return $classes;
}
add_filter("body_class", "wpcc_body_class");

/**
 * add a WPCC_NC button to html editor toolbar.
 * @since 1.1.14
 */
function wpcc_appthemes_add_quicktags() {
	global $wpcc_options;
	if ( !empty($wpcc_options) && !empty($wpcc_options['wpcc_no_conversion_qtag']) && wp_script_is('quicktags') ) {
?>
	<script type="text/javascript">
//<![CDATA[
		QTags.addButton('eg_wpcc_nc', 'WPCC_NC', '<!--WPCC_NC_START-->', '<!--WPCC_NC_END-->', null, 'WP Chinese Conversion DO-NOT Convert Tag', 120 );
//]]>
	</script>
<?php
	}
}
add_action( 'admin_print_footer_scripts', 'wpcc_appthemes_add_quicktags' );

/**
 * Function executed when plugin is activated
 *
 * add or update 'wpcc_option' in wp_option table of the wordpress database
 * your current settings will be reserved if you have installed this plugin before.
 *
 */
function wpcc_activate() {
	$current_options = (array) get_option('wpcc_options');
	$wpcc_options = array(
		'wpcc_search_conversion' => 1,
		'wpcc_used_langs' => array('zh-hans', 'zh-hant', 'zh-cn', 'zh-hk', 'zh-sg', 'zh-tw'),
		'wpcc_browser_redirect' => 0,
		'wpcc_auto_language_recong' => 0,
		'wpcc_flag_option' => 1,
		'wpcc_use_cookie_variant' => 0,
		'wpcc_use_fullpage_conversion' => 1,
		'wpcc_trackback_plugin_author' => 0,
		'wpcc_add_author_link' => 0,
		'wpcc_use_permalink' => 0,
		'wpcc_no_conversion_tag' => '',
		'wpcc_no_conversion_ja' => 0,
		'wpcc_no_conversion_qtag' => 0,
		'wpcc_engine' => 'mediawiki', // alternative: opencc
		'nctip' => '',
	);

	foreach( $current_options as $key => $value )
		if( isset($wpcc_options[$key]) )
			$wpcc_options[$key] = $value;

	foreach( array('zh-hans' => "hanstip", 'zh-hant' => "hanttip", 'zh-cn' => "cntip", 'zh-hk' => "hktip", 'zh-sg' => "sgtip", 'zh-tw' => "twtip", 'zh-my' => "mytip", 'zh-mo' => "motip") as $lang => $tip ) {
		if( !empty($current_options[$tip]) )
			$wpcc_options[$tip] = $current_options[$tip];
	}

	//WP will automaticlly add a option if it doesn't exists( when this plugin is firstly being installed).
	update_option('wpcc_options', $wpcc_options);
}
register_activation_hook(__FILE__, 'wpcc_activate');
