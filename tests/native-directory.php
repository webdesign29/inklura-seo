<?php
$root=getenv('WD29_WP_ROOT');if(!$root)throw new RuntimeException('Set WD29_WP_ROOT.');
define('WP_DISABLE_FATAL_ERROR_HANDLER',true);require $root.'/wp-load.php';
if(DB_NAME!=='wd29woo'||parse_url(home_url(),PHP_URL_HOST)!=='woo.example.test')throw new RuntimeException('Not isolated fixture.');
$checks=0;function seo_check($ok,$message){global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
$plugin=Inklura\SEO\Plugin::instance();
seo_check($plugin->module('updater')===null,'External updater registered');
seo_check(!is_file(WP_PLUGIN_DIR.'/inklura-seo/src/Updater.php'),'External updater shipped');
$id=wp_insert_post(['post_title'=>'Inklura SEO native fixture','post_content'=>'Public fixture content.','post_status'=>'publish','post_type'=>'post'],true);seo_check(!is_wp_error($id),'Post creation failed');
update_post_meta($id,'_inklura_seo_title','Native SEO title');update_post_meta($id,'_inklura_seo_desc','Native description <script>alert(1)</script>');
$GLOBALS['wp_query']=new WP_Query(['p'=>$id]);$GLOBALS['wp_the_query']=$GLOBALS['wp_query'];$GLOBALS['post']=get_post($id);setup_postdata($GLOBALS['post']);
seo_check(strpos(wp_get_document_title(),'Native SEO title')!==false,'SEO title override failed');
ob_start();do_action('wp_head');$head=ob_get_clean();
seo_check(strpos($head,'name="description"')!==false,'Description missing');
seo_check(strpos($head,'property="og:title"')!==false,'Social metadata missing');
seo_check(strpos($head,'<script>alert(1)</script>')===false,'Metadata not escaped');
seo_check(strpos($head,'application/ld+json')!==false,'Schema missing');
wp_set_current_user(1);$settings=new Inklura\SEO\Settings($plugin->options(),$plugin);$clean=$settings->sanitize(['title_format'=>'<script>bad</script>%title%','default_og_image'=>'javascript:alert(1)']);
seo_check(strpos($clean['title_format'],'<script>')===false,'Settings HTML retained');seo_check($clean['default_og_image']==='','Unsafe URL retained');
$meta=new Inklura\SEO\MetaBox($plugin->options(),$plugin);$_POST=['inklura_seo_title'=>'Unauthorized'];$meta->save($id,get_post($id));seo_check(get_post_meta($id,'_inklura_seo_title',true)==='Native SEO title','Missing nonce accepted');
$_POST=['inklura_seo_meta_nonce'=>wp_create_nonce('inklura_seo_save_meta'),'inklura_seo_title'=>'Saved through metabox','inklura_seo_og_image'=>['invalid'=>'array']];$meta->save($id,get_post($id));seo_check(get_post_meta($id,'_inklura_seo_title',true)==='Saved through metabox','Authorized save failed');seo_check(get_post_meta($id,'_inklura_seo_og_image',true)==='','Malformed image input retained');
echo 'PASS: '.$checks." native WordPress directory-edition, metadata, escaping and permission checks\n";
