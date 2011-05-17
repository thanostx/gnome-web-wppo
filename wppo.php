<?php
/*
Plugin Name: WPPO
Description: Gettext layer for WordPress content
Author: Vinicius Depizzol
Author URI: http://vinicius.depizzol.com.br
License: AGPLv3
*/

/* Copyright 2011  Vinicius Depizzol <vdepizzol@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

if(!function_exists('_log')) {
    function _log( $message ) {
        if(WP_DEBUG === true) {
            if( is_array($message) || is_object($message) ){
                file_put_contents(ABSPATH.'debug.log', print_r($message, true)."\n", FILE_APPEND);
            } else {
                file_put_contents(ABSPATH.'debug.log', $message."\n" , FILE_APPEND);
            }
        }
    }
}


require_once("wppo.genxml.php");

/* 
 * Folder structure:
 * /wppo/[post_type]/[file_type]/[lang].[ext]
 * 
 * Example:
 * /wppo/pages/po/fr.po
 * /wppo/posts/pot/es.pot
 * /wppo/posts/xml/pt_BR.xml
 * 
 * Global POT file:
 * /wppo/posts.pot
 * /wppo/pages.pot
 * 
 * Global XML file:
 * /wppo/posts.xml
 * /wppo/pages.xml
 * 
 */

define('WPPO_DIR', ABSPATH . "wppo/");
define('WPPO_PREFIX', "wppo_");
define('WPPO_XML2PO_COMMAND', "/usr/bin/xml2po");

$wppo_cache = array();


if(is_admin()) {
    require_once dirname(__FILE__).'/admin.php';
}

/*
 * Install WPPO Plugin
 */

function wppo_install() {
    
    global $wpdb;
    
    $tables = array(
        'posts' =>              "CREATE TABLE `".WPPO_PREFIX."posts` (
                                  `wppo_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                                  `post_id` bigint(20) unsigned NOT NULL,
                                  `lang` varchar(10) NOT NULL,
                                  `translated_title` text NOT NULL,
                                  `translated_excerpt` text NOT NULL,
                                  `translated_name` varchar(200) NOT NULL,
                                  `translated_content` longtext NOT NULL,
                                  PRIMARY KEY (`wppo_id`),
                                  KEY `post_id` (`post_id`)
                                ) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;",
        
        
        'languages' =>          "CREATE TABLE `".WPPO_PREFIX."languages` (
                                  `lang_code` varchar(10) NOT NULL,
                                  `lang_name` varchar(100) NOT NULL,
                                  `lang_status` enum('visible', 'hidden') NOT NULL,
                                  PRIMARY KEY ( `lang_code` )
                                ) ENGINE=MYISAM DEFAULT CHARSET=latin1 ;",
        
        
        'translation_log' =>    "CREATE TABLE `".WPPO_PREFIX."translation_log` (
                                  `log_id` int(10) NOT NULL AUTO_INCREMENT PRIMARY KEY ,
                                  `lang` varchar(10) NOT NULL ,
                                  `translation_date` timestamp NOT NULL ,
                                  `status` varchar(255) NOT NULL
                                ) ENGINE=MYISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1;"
    );
    
    foreach($tables as $name => $sql) {
        if($wpdb->get_var("SHOW TABLES LIKE '".WPPO_PREFIX."{$name}'") != WPPO_PREFIX.$name) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }
    
    
    /*
     * Create WPPO directories
     */
    $directories_for_post_types = array('pages', 'posts');
    $directories_for_formats = array('po', 'pot', 'xml');
    
    if(!is_dir(WPPO_DIR)) {
        
        if(!is_writable(ABSPATH)) {
            die("Please, make ".ABSPATH." a writeable directory or create manually a folder called “wppo” with chmod 0755 on it.");
        }
        
        mkdir(WPPO_DIR, 0755);
    }
    
    if(!is_writable(WPPO_DIR)) {
        die("The directory ".WPPO_DIR." must be writeable.");
    }
    
    foreach($directories_for_post_types as $directory) {
        if(!is_dir(WPPO_DIR.'/'.$directory)) {
            mkdir(WPPO_DIR.'/'.$directory, 0755);
        } else {
            if(!is_writable(WPPO_DIR."/".$directory)) {
                die("All the folders inside ".WPPO_DIR." should be writeable.");
            }
        }
                
        foreach($directories_for_formats as $format) {
            if(!is_dir(WPPO_DIR.'/'.$directory.'/'.$format)) {
                mkdir(WPPO_DIR.'/'.$directory.'/'.$format, 0755);
            } else {
                if(!is_writable(WPPO_DIR."/".$directory."/".$format)) {
                    die("All the folders inside ".WPPO_DIR." should be writeable.");
                }
            }
        }
    }
    
    /*
     * Add the default plugin options
     */
    
    // Number of old POT files we'll keep in the pot_archive folder
    // FIXME
    add_option("wppo_pot-cache-number", 5);
    
    // Limit of date to select news to translate. Occasionally people
    // want to disable the translation of old news.
    // FIXME
    add_option("wppo_old-news-limit", strtotime('now - 4 months'));
    
    
    /*
     * Check for existing translations
     */
    // FIXME
    //wppo_update_pot_file();
    
}
register_activation_hook(__FILE__, 'wppo_install');


function wppo_uninstall() {
    
    global $wpdb;
    
    /*
     * Drop existing tables
     */
    
    $tables = array('posts', 'languages', 'translation_log');
    
    foreach($tables as $index => $name) {
        $tables[$index] = WPPO_PREFIX.$name;
    }
    
    $wpdb->query("DROP TABLE IF EXISTS ".implode(", ", $tables));
    
    
    /*
     * We won't delete any wppo files here.
     */
    
}
register_deactivation_hook(__FILE__, 'wppo_uninstall');





add_filter('the_title', function($title, $id) {
    global $wppo_cache;

    $translated_title = trim(wppo_get_translated_data('translated_title', $id));

    if(empty($translated_title)) {
        return $title;
    } else {
        return $translated_title;
    }
}, 10, 2);

add_filter('the_content', function($content) {
    global $wppo_cache, $post;

    if(isset($wppo_cache['posts'][$post->ID])) {
        $translated_content = $wppo_cache['posts'][$post->ID]['translated_content'];
    } else {
        $translated_content = trim(wppo_get_translated_data('translated_content', $post->ID));
    }

    if(empty($translated_content)) {
        return $content;
    } else {
        return $translated_content;
    }
}, 10, 1);

add_filter('get_pages', function($pages) {
    
    global $wppo_cache, $wpdb;

    $lang = wppo_get_lang();

    foreach($pages as $page) {
        if(!isset($wppo_cache['posts'][$page->ID]) && $lang != 'C') {
          $wppo_cache['posts'][$page->ID] = $wpdb->get_row("SELECT * FROM " . WPPO_PREFIX . "posts WHERE post_id = '" . mysql_real_escape_string($page->ID) . "' AND lang = '" . mysql_real_escape_string($lang) . "'", ARRAY_A);
        }
        
        if(isset($wppo_cache['posts'][$page->ID]) && is_array($wppo_cache['posts'][$page->ID])) {
          $page->post_title   = $wppo_cache['posts'][$page->ID]['translated_title'];
          $page->post_name    = $wppo_cache['posts'][$page->ID]['translated_name'];
          $page->post_content = $wppo_cache['posts'][$page->ID]['translated_content'];
          $page->post_excerpt = $wppo_cache['posts'][$page->ID]['translated_excerpt'];
        }
    }
    
    return $pages;   
     
}, 1);


function wppo_get_lang() {
    
    global $wpdb, $wppo_cache;
    
    if(isset($wppo_cache['lang'])) {
        
        return $wppo_cache['lang'];
        
    } elseif(isset($_REQUEST['lang'])) {
        
        $defined_lang = $_REQUEST['lang'];
        
    } elseif(isset($_SESSION['lang'])) {
        
        $defined_lang = $_SESSION['lang'];
        
    }
    
    if(isset($defined_lang)) {
        
        /*
         * Verify if the selected language really exists
         */
        
        $check_lang = $wpdb->get_row("SELECT lang_code, lang_name FROM ".WPPO_PREFIX."languages WHERE lang_status = 'visible' AND lang_code = '".mysql_real_escape_string($defined_lang)."'", ARRAY_A);
        if($wpdb->num_rows === 1) {
            $wppo_cache['lang'] = $defined_lang;
            return $defined_lang;
        }
        
    }
    
    /*
     * Since at this point no one told us what language the user wants to see the content,
     * we'll try to guess from the HTTP headers
     */
    
    $http_langs = explode(',', $_SERVER["HTTP_ACCEPT_LANGUAGE"]);
    
    $user_lang = array();
    foreach($http_langs as $i => $value) {
        $user_lang[$i] = explode(';', $value);
        $user_lang[$i] = str_replace('-', '_', $user_lang[$i][0]);
        
        /*
         * If the first language available also contains the country code,
         * we'll automatically add as a second option the same language without the country code.
         */
        if($i == 0 && strpos($user_lang[$i], '_') !== false) {
            $user_lang[$i+1] = explode('_', $user_lang[$i]);
            $user_lang[$i+1] = $user_lang[$i+1][0];
        }
    }
    
    if(!isset($wppo_cache['available_lang'])) {
        $all_languages = $wpdb->get_results("SELECT lang_code, lang_name FROM ".WPPO_PREFIX."languages WHERE lang_status = 'visible'", ARRAY_A);
        
        foreach($all_languages as $index => $array) {
            $wppo_cache['available_lang'][$array['lang_code']] = $array['lang_name'];
        }
    }
    
    foreach($user_lang as $lang_code) {
        if(isset($wppo_cache['available_lang'][$lang_code])) {
            $defined_lang = $lang_code;
            break;
        }
    }
    
    if(isset($defined_lang)) {
        $wppo_cache['lang'] = $defined_lang;
    } else {
        /*
         * Returning this means that we won't show any translation to the user.
         * "C" disables all localization.
         */
        $wppo_cache['lang'] = 'C';
        return 'C';
    }
    
    return $defined_lang;
}


/*
 * Get all the translated data from the current post.
 */
function wppo_get_translated_data($string, $id = null) {
    global $post, $wpdb, $wppo_cache;

    $lang = wppo_get_lang();

    if($id !== null) {
        $p = &get_post($id);
    } else {
        $p = $post;
    }
    
    if($lang != "C") {
        if(!isset($wppo_cache['posts'][$p->ID])) {
            $wppo_cache['posts'][$p->ID] = $wpdb->get_row("SELECT * FROM " . WPPO_PREFIX . "posts WHERE post_id = '" . mysql_real_escape_string($p->ID). "' AND lang = '" . mysql_real_escape_string($lang) . "'", ARRAY_A);
        }
    
        if(isset($wppo_cache['posts'][$p->ID][$string])) {
            return $wppo_cache['posts'][$p->ID][$string];
        }
    }
    
    if($string == 'translated_content') {
        return wpautop($p->post_content);
    } else {
        return $p->{str_replace("translated_", "post_", $string)};
    }
}




/*
 * This function regenerates all the POT files, including all the recent
 * changes in any content.
 * Automatically it also updates the internal XML used for translation.
 * 
 * TODO:
 * For reference it shouldn't just replace the previous POT files.
 * It should keep a backup somewhere else.
 * 
 */
function wppo_update_pot($coverage = array('posts', 'pages')) {
    
    global $wpdb;
    
    if($coverage == 'all') {
        $coverage = array('posts', 'pages');
    }
    
    if(is_string($coverage) && $coverage != 'posts' && $coverage != 'pages') {
        die("First argument of wppo_update_pot() must be \"posts\" or \"pages\" (or an array of both).");
    }
    
    if(is_string($coverage)) {
        $coverage = array($coverage);
    }
    
    foreach($coverage as $post_type) {
        
        $pot_file = WPPO_DIR.$post_type.".pot";
        $xml_file = WPPO_DIR.$post_type.".xml";
        
        $generated_xml = wppo_generate_po_xml($post_type);
        
        if(is_writable($xml_file)) {
            file_put_contents($xml_file, $generated_xml);
        } else {
            die("Ooops. We got an error here. The file ".$xml_file." must be writeable otherwise we can't do anything.");
        }
        
        $output = shell_exec(WPPO_XML2PO_COMMAND." -m xhtml -o ".escapeshellarg($pot_file)." ".escapeshellarg($xml_file));
        
        // Updates translation_log table.
        // FIXME
        
    }
    
    
    
}
add_action('post_updated', 'wppo_update_pot_file');
add_action('post_updated', 'wppo_receive_po_file');


/*
 * this action will be fired when damned lies system send an updated version of
 * a po file. This function needs to take care of creating the translated
 * xml file and separate its content to the wordpress database
 */
function wppo_receive_po_file() {
    global $wpdb;
    
    /*
     * WordPress pattern for validating data
     */
    $table_format = array('%s', '%d', '%s', '%s', '%s');
    
    // FIXME
    $post_type = 'posts';
    
    $po_dir = WPPO_DIR."/".$post_type;
    
    
    if($handle = opendir(PO_DIR)) {
        while(false !== ($po_file = readdir($handle))) {
            /*
             * Gets all the .po files from PO_DIR. Then it will generate a translated
             * XML for each language.
             *
             * All the po files must use the following format: "gnomesite.[lang-code].po"
             *
             */
            if(strpos($po_file, '.po') !== false && strpos($po_file, '.pot') === false) {
                $po_file_array = explode('.', $po_file);
                
                /*
                 * Arranging the name of the translated xml to something like
                 * "gnomesite.pt-br.xml".
                 */
                $lang = $po_file_array[1];
                $translated_xml_file = XML_DIR . 'gnomesite.' . $lang . '.xml';
                $cmd = "/usr/bin/xml2po -m xhtml -p " . PO_DIR . "$po_file -o $translated_xml_file " . XML_DIR . "gnomesite.xml";
                $out = exec($cmd);

                $translated_xml = file_get_contents($translated_xml_file);
                $dom = new DOMDocument();
                $dom->loadXML($translated_xml);
                
                $pages = $dom->getElementsByTagName('page');
                
                foreach($pages as $page) {
                
                    $page_id      = $page->getAttributeNode('id')->value;
                    $page_title   = $page->getElementsByTagName('title')->item(0)->nodeValue;
                    $page_excerpt = $page->getElementsByTagName('excerpt')->item(0)->nodeValue;
                    $page_name    = $page->getElementsByTagName('name')->item(0)->nodeValue;


                    $page_content_elements = $page->getElementsByTagName('html')->item(0)->childNodes;
                    $page_content = '';
                    foreach($page_content_elements as $element) {
                        $page_content .= $element->ownerDocument->saveXML($element);
                    }
                    
                    $page_array = array('lang' => $lang,
                                        'post_id' => $page_id,
                                        'translated_title' => $page_title,
                                        'translated_excerpt' => $page_excerpt,
                                        'translated_name' => $page_name,
                                        'translated_content' => $page_content
                                        );
                    
                    /*
                     * Stores in the table the translated version of the page
                     */
                    $wpdb->get_row("SELECT wppo_id FROM ".WPPO_PREFIX."posts WHERE post_id = '". mysql_real_escape_string($page_id) ."' AND lang = '". mysql_real_escape_string($lang) ."'");
                    if($wpdb->num_rows == 0) {
                        $wpdb->insert(WPPO_PREFIX."posts", $page_array, $table_format);
                    } else {
                        $wpdb->update(WPPO_PREFIX."posts", $page_array, array('post_id' => $page_id, 'lang' => $lang), $table_format);
                    }
                }
            }
        }
    }
}


?>
