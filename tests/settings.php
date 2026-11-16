<?php
// Offline regression checks: no WordPress installation or outbound requests.
define('ABSPATH','/nonexistent/');
$hooks=[];$opts=[];$trans=[];
function add_action($name,$fn,...$args){$GLOBALS['hooks'][$name]=$fn;}
function get_option($k,$default=[]){return $GLOBALS['opts'][$k]??$default;}
function update_option($k,$v){$GLOBALS['opts'][$k]=$v;}
function get_transient($k){return $GLOBALS['trans'][$k]??false;}
function set_transient($k,$v,$ttl){$GLOBALS['trans'][$k]=$v;}
function delete_transient($k){unset($GLOBALS['trans'][$k]);}
function current_user_can($x){return true;}
function check_admin_referer($x){return true;}
function wp_unslash($x){return is_array($x)?array_map('wp_unslash',$x):stripslashes($x);}
function sanitize_text_field($x){return strip_tags($x);}
function sanitize_key($x){return preg_replace('/[^a-z0-9_\-]/','',strtolower($x));}
function esc_url_raw($x){return filter_var($x,FILTER_VALIDATE_URL)?$x:'';}
function admin_url($x){return 'https://example.test/wp-admin/'.$x;}
class Redirected extends Exception{}
function wp_safe_redirect($x){throw new Redirected($x);}
function get_post_types(...$args){return ['post'=>(object)['labels'=>(object)['singular_name'=>'Записи']]];}
function esc_html($x){return htmlspecialchars($x,ENT_QUOTES,'UTF-8');}
function esc_attr($x){return esc_html($x);}
function esc_url($x){return esc_html($x);}
function esc_textarea($x){return esc_html($x);}
function wp_nonce_field($x){}
function checked($x){if($x)echo 'checked';}
require dirname(__DIR__).'/seo-instantindex.php';
function verify($condition,$message){if(!$condition)throw new Exception($message);}
function save($extra=[]){$_POST=array_merge(['hcin_action'=>'save_settings','post_types'=>['post'],'google_enabled'=>'1','indexnow_enabled'=>'1'],$extra);try{($GLOBALS['hooks']['admin_init'])();}catch(Redirected $e){return;}throw new Exception('Expected redirect');}
verify(hcin_settings()['post_types']===['post','page'],'Defaults must only include posts and pages');
$original=json_encode(['client_email'=>'fixture@example.test','private_key'=>'FAKE_PRIVATE_KEY_OLD']);
$opts[HCIN_OPT]=['google_sa_json'=>$original,'post_types'=>['post'],'indexnow_enabled'=>true,'google_enabled'=>true];
save();verify($opts[HCIN_OPT]['google_sa_json']===$original,'Unrelated save lost credential');
$next=json_encode(['client_email'=>'fixture-new@example.test','private_key'=>"FAKE_PRIVATE_KEY_NEW\nline2"]);
$trans['hcin_google_token']='old-cache';save(['google_sa_json'=>addslashes($next)]);verify($opts[HCIN_OPT]['google_sa_json']===$next,'Replacement JSON changed');verify(!isset($trans['hcin_google_token']),'Cached token not cleared');
save(['google_sa_json'=>'']);verify($opts[HCIN_OPT]['google_sa_json']===$next,'Empty field erased credential');
save(['google_sa_json'=>'invalid']);verify($opts[HCIN_OPT]['google_sa_json']===$next,'Invalid JSON erased credential');verify($trans['hcin_flash']['type']==='error','Invalid JSON not reported');
$_GET=[];ob_start();hcin_admin_page();$html=ob_get_clean();verify(strpos($html,'FAKE_PRIVATE_KEY_NEW')===false,'Private key leaked to HTML');verify(strpos($html,'impik.dev')!==false,'Author not shown');
save(['google_sa_remove'=>'1']);verify($opts[HCIN_OPT]['google_sa_json']==='','Explicit removal failed');
echo "Passed: retain, replace, clear-token, empty, invalid, no-key-in-HTML, explicit-remove\n";
